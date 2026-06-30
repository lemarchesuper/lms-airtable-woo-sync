<?php
/**
 * Moteur de synchronisation Airtable → WooCommerce.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LMS_ATS_Sync_Engine {

	/** @var array */
	private $settings;
	/** @var array Messages accumulés pour le compte-rendu. */
	private $messages = array();

	public function __construct() {
		$this->settings = LMS_ATS_Settings::get();
	}

	/**
	 * Exécute une synchro complète.
	 *
	 * @param string $source 'cron' | 'manual'.
	 * @return array Compte-rendu.
	 */
	public function run( $source = 'manual' ) {
		$start = microtime( true );

		$report = array(
			'source'   => $source,
			'created'  => 0,
			'updated'  => 0,
			'skipped'  => 0,
			'errors'   => 0,
			'messages' => array(),
			'dry_run'  => (int) $this->settings['dry_run'],
			'duration' => 0,
		);

		// Garde-fou : token requis.
		if ( empty( $this->settings['api_token'] ) ) {
			$report['errors']     = 1;
			$report['messages'][] = 'Token API Airtable manquant — renseignez-le dans les réglages.';
			$report['duration']   = round( microtime( true ) - $start, 2 );
			LMS_ATS_Logger::record( $report );
			return $report;
		}

		// 1) Pull Airtable.
		$client = new LMS_ATS_Airtable_Client(
			$this->settings['api_token'],
			$this->settings['base_id'],
			$this->settings['table_id']
		);
		$result = $client->fetch_all_records( $this->settings['view'] );

		if ( ! empty( $result['error'] ) ) {
			$report['errors']     = 1;
			$report['messages'][] = 'Échec récupération Airtable : ' . $result['error'];
			$report['duration']   = round( microtime( true ) - $start, 2 );
			LMS_ATS_Logger::record( $report );
			return $report;
		}

		$records = $result['records'];
		$map     = LMS_ATS_Field_Map::get();
		$touched = array(); // IDs produits Woo créés/mis à jour cette passe.

		// 2) Upsert produit par produit.
		foreach ( $records as $record ) {
			$record_id = $record['id'] ?? '';
			$fields    = $record['fields'] ?? array();
			if ( ! $record_id ) {
				continue;
			}

			try {
				$outcome = $this->upsert_product( $record_id, $fields, $map );
				if ( 'created' === $outcome['status'] ) {
					$report['created']++;
				} elseif ( 'updated' === $outcome['status'] ) {
					$report['updated']++;
				} else {
					$report['skipped']++;
				}
				if ( $outcome['product_id'] ) {
					$touched[] = $outcome['product_id'];
				}
			} catch ( Exception $e ) {
				$report['errors']++;
				$report['messages'][] = 'Record ' . $record_id . ' : ' . $e->getMessage();
			}
		}

		// 3) Politique pour les produits absents de la vue.
		if ( 'ignore' !== $this->settings['absent_policy'] ) {
			$report['messages'][] = $this->apply_absent_policy( $touched );
		}

		$report['messages']  = array_merge( $report['messages'], $this->messages );
		$report['duration']  = round( microtime( true ) - $start, 2 );
		$report['fetched']   = count( $records );

		LMS_ATS_Logger::record( $report );
		return $report;
	}

	/**
	 * Crée ou met à jour un produit Woo à partir d'un enregistrement Airtable.
	 *
	 * @return array{status:string, product_id:int}
	 */
	private function upsert_product( $record_id, array $fields, array $map ) {
		$match_key  = $this->settings['match_meta_key'];
		$dry_run    = ! empty( $this->settings['dry_run'] );
		$product_id = $this->find_product_by_record_id( $record_id );

		// Création si absent.
		if ( ! $product_id ) {
			if ( empty( $this->settings['create_missing'] ) ) {
				return array( 'status' => 'skipped', 'product_id' => 0 );
			}
			if ( $dry_run ) {
				return array( 'status' => 'created', 'product_id' => 0 );
			}
			$product = new WC_Product_Simple();
			$product->update_meta_data( $match_key, $record_id );
			$is_new = true;
		} else {
			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				return array( 'status' => 'skipped', 'product_id' => 0 );
			}
			$is_new = false;
		}

		// Application des règles de mapping.
		$category_ids    = null;
		$tax_assignments = array();
		foreach ( $map as $rule ) {
			$source = $rule['source'] ?? '';
			if ( '' === $source || ! array_key_exists( $source, $fields ) ) {
				continue;
			}
			$value = $fields[ $source ];

			// Transform éventuel.
			if ( ! empty( $rule['transform'] ) && is_callable( array( 'LMS_ATS_Transforms', $rule['transform'] ) ) ) {
				$value = call_user_func( array( 'LMS_ATS_Transforms', $rule['transform'] ), $value );
			} else {
				$value = LMS_ATS_Transforms::scalar( $value );
			}

			switch ( $rule['type'] ) {
				case 'core':
					$this->apply_core( $product, $rule['core'], $value );
					break;

				case 'meta':
					$meta_key = $rule['meta_key'] ?? '';
					// On n'écrit pas les clés encore non résolues (TODO_*) pour ne pas polluer la base.
					if ( $meta_key && 0 !== strpos( $meta_key, 'TODO_' ) ) {
						$product->update_meta_data( $meta_key, $value );
					}
					break;

				case 'category':
					$category_ids = $this->resolve_category_ids( (array) $value, $dry_run );
					break;

				case 'taxonomy':
					$tax       = $rule['taxonomy'] ?? '';
					$term_name = trim( (string) LMS_ATS_Transforms::scalar( $value ) );
					if ( $tax && '' !== $term_name ) {
						$tax_assignments[ $tax ] = $term_name;
					}
					break;
			}
		}

		if ( is_array( $category_ids ) && ! empty( $category_ids ) ) {
			$product->set_category_ids( $category_ids );
		}

		if ( $dry_run ) {
			return array( 'status' => $is_new ? 'created' : 'updated', 'product_id' => $is_new ? 0 : $product_id );
		}

		$saved_id = $product->save();

		// Taxonomies : après save (nécessite l'ID produit).
		foreach ( $tax_assignments as $taxonomy => $term_name ) {
			$this->assign_term( $saved_id, $taxonomy, $term_name );
		}

		return array(
			'status'     => $is_new ? 'created' : 'updated',
			'product_id' => $saved_id,
		);
	}

	/**
	 * Pose un terme de taxonomie sur le produit (crée le terme s'il manque).
	 */
	private function assign_term( $product_id, $taxonomy, $term_name ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			$this->messages[] = sprintf( 'Taxonomie « %s » inexistante (terme « %s » ignoré).', $taxonomy, $term_name );
			return;
		}
		$term = get_term_by( 'name', $term_name, $taxonomy );
		if ( ! $term ) {
			$created = wp_insert_term( $term_name, $taxonomy );
			if ( is_wp_error( $created ) ) {
				$this->messages[] = sprintf( 'Taxonomie %s « %s » : %s', $taxonomy, $term_name, $created->get_error_message() );
				return;
			}
			$term_id = (int) $created['term_id'];
		} else {
			$term_id = (int) $term->term_id;
		}
		wp_set_object_terms( $product_id, array( $term_id ), $taxonomy, false );
	}

	/**
	 * Applique une valeur sur un champ natif WooCommerce.
	 */
	private function apply_core( WC_Product $product, $core_field, $value ) {
		switch ( $core_field ) {
			case 'title':
				$product->set_name( (string) $value );
				break;
			case 'regular_price':
				$price = $this->normalize_price( $value );
				if ( '' !== $price ) {
					$product->set_regular_price( $price );
				}
				break;
			case 'stock_status':
				$product->set_stock_status( $value === 'instock' ? 'instock' : 'outofstock' );
				break;
			case 'catalog_visibility':
				$product->set_catalog_visibility( $value === 'hidden' ? 'hidden' : 'visible' );
				break;
			case 'description':
				$product->set_description( (string) $value );
				break;
			case 'sku':
				try {
					$product->set_sku( (string) $value );
				} catch ( Exception $e ) {
					$this->messages[] = 'SKU refusé (' . $value . ') : ' . $e->getMessage();
				}
				break;
		}
	}

	/**
	 * Normalise un prix : « 3,90 » ou « 3.90 » → « 3.90 ».
	 */
	private function normalize_price( $value ) {
		$v = trim( (string) $value );
		if ( '' === $v ) {
			return '';
		}
		$v = str_replace( array( ' ', "\xc2\xa0" ), '', $v ); // espaces / insécables.
		$v = str_replace( ',', '.', $v );
		return is_numeric( $v ) ? $v : '';
	}

	/**
	 * Recherche un produit par le Record ID stocké dans le meta de matching.
	 *
	 * @return int 0 si absent.
	 */
	private function find_product_by_record_id( $record_id ) {
		$ids = get_posts( array(
			'post_type'      => array( 'product' ),
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array(
				array(
					'key'   => $this->settings['match_meta_key'],
					'value' => $record_id,
				),
			),
		) );
		return ! empty( $ids ) ? (int) $ids[0] : 0;
	}

	/**
	 * Résout (et crée si besoin) une arborescence de catégories « Parent>Enfant ».
	 * Retourne l'ID du terme le plus profond dans un tableau (pour set_category_ids).
	 *
	 * @param array $path Niveaux ['Parent','Enfant',...].
	 * @return array IDs de termes.
	 */
	private function resolve_category_ids( array $path, $dry_run ) {
		$parent_id = 0;
		$leaf_id   = 0;

		foreach ( $path as $name ) {
			$name = trim( (string) $name );
			if ( '' === $name ) {
				continue;
			}
			$term = get_term_by( 'name', $name, 'product_cat' );
			// Affine par parent pour gérer les noms identiques à des niveaux différents.
			if ( $term && (int) $term->parent !== (int) $parent_id ) {
				$term = false;
			}

			if ( ! $term ) {
				if ( $dry_run ) {
					return array(); // En simulation, on ne crée pas de terme.
				}
				$created = wp_insert_term( $name, 'product_cat', array( 'parent' => $parent_id ) );
				if ( is_wp_error( $created ) ) {
					$this->messages[] = 'Catégorie « ' . $name . " » : " . $created->get_error_message();
					break;
				}
				$leaf_id   = (int) $created['term_id'];
				$parent_id = $leaf_id;
			} else {
				$leaf_id   = (int) $term->term_id;
				$parent_id = $leaf_id;
			}
		}

		return $leaf_id ? array( $leaf_id ) : array();
	}

	/**
	 * Applique la politique « produit absent de la vue » aux produits gérés non vus cette passe.
	 *
	 * @param array $touched IDs vus cette passe.
	 * @return string Message de synthèse.
	 */
	private function apply_absent_policy( array $touched ) {
		$policy  = $this->settings['absent_policy'];
		$dry_run = ! empty( $this->settings['dry_run'] );

		// Tous les produits « gérés » = ceux qui ont le meta de matching.
		$managed = get_posts( array(
			'post_type'      => array( 'product' ),
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array(
				array(
					'key'     => $this->settings['match_meta_key'],
					'compare' => 'EXISTS',
				),
			),
		) );

		$absent = array_diff( array_map( 'intval', $managed ), array_map( 'intval', $touched ) );
		if ( empty( $absent ) ) {
			return 'Politique absents : aucun produit concerné.';
		}

		$count = 0;
		foreach ( $absent as $pid ) {
			if ( $dry_run ) {
				$count++;
				continue;
			}
			$product = wc_get_product( $pid );
			if ( ! $product ) {
				continue;
			}
			if ( 'outofstock' === $policy ) {
				$product->set_stock_status( 'outofstock' );
				$product->save();
				$count++;
			} elseif ( 'draft' === $policy ) {
				wp_update_post( array( 'ID' => $pid, 'post_status' => 'draft' ) );
				$count++;
			}
		}

		return sprintf( 'Politique absents (%s) : %d produit(s) traité(s).', $policy, $count );
	}
}
