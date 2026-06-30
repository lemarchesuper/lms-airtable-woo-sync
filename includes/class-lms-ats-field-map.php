<?php
/**
 * Table de correspondance : champ Airtable (table WEB | Products) → cible WooCommerce.
 *
 * Chaque règle décrit COMMENT écrire une valeur Airtable dans Woo :
 *   - 'source'   : nom EXACT du champ Airtable (tel que renvoyé par l'API).
 *   - 'type'     : 'core' | 'meta' | 'category' | 'producer'.
 *   - 'core'     : (si type=core) titre|regular_price|stock_status|catalog_visibility|description|sku.
 *   - 'meta_key' : (si type=meta) étiquette de stockage WordPress.
 *   - 'transform': (optionnel) nom d'une méthode statique de LMS_ATS_Transforms.
 *
 * ⚠️ Les meta_key marqués « TODO » doivent être remplacés par les VRAIS noms
 *    que WP All Import écrit aujourd'hui (sinon l'affichage front casse).
 *    On les relèvera ensemble depuis une fiche produit existante.
 *
 * Surchargeable sans toucher ce fichier via le filtre 'lms_ats_field_map'.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LMS_ATS_Field_Map {

	/**
	 * @return array Liste de règles de mapping.
	 */
	public static function get() {
		$map = array(

			// --- Champs natifs WooCommerce (sûrs, déduits du CSV) ---
			array(
				'source' => 'AT | Product Name Poids',
				'type'   => 'core',
				'core'   => 'title',
			),
			array(
				// Prix de vente. À confirmer : « WC | Net Sale Price » (HT/net) vs « WC | Consigned Sale Price ».
				'source' => 'WC | Net Sale Price',
				'type'   => 'core',
				'core'   => 'regular_price',
			),
			array(
				'source'    => 'WC | Product Stock',
				'type'      => 'core',
				'core'      => 'stock_status',
				'transform' => 'stock_status', // instock/outofstock normalisé.
			),
			array(
				'source'    => 'WC | Product Visibility',
				'type'      => 'core',
				'core'      => 'catalog_visibility',
				'transform' => 'catalog_visibility',
			),
			array(
				'source' => 'WC | Product Description',
				'type'   => 'core',
				'core'   => 'description',
			),

			// --- Catégories hiérarchiques « Parent>Enfant » ---
			array(
				'source'    => 'WC | Product Category Calculated',
				'type'      => 'category',
				'transform' => 'category_path', // découpe sur « > ».
			),

			// --- Producteur (taxonomie / terme) ---
			array(
				'source' => 'Producteur WC ID',
				'type'   => 'producer',
			),

			// --- Meta sûrs ---
			array(
				'source'   => 'NEW Product EAN13',
				'type'     => 'meta',
				'meta_key' => '_ean', // ⚠️ TODO confirmer (souvent _ean, _barcode, _wpm_gtin_code...).
			),

			// --- Conditionnement (ACF/JetEngine) : meta_key à confirmer ---
			array(
				'source'   => 'WC | Product Packaging',   // ex. « 500gr »
				'type'     => 'meta',
				'meta_key' => 'TODO_packaging',
			),
			array(
				'source'   => 'WC | Price/Unit',          // ex. « 78.00 » (prix au kg)
				'type'     => 'meta',
				'meta_key' => 'TODO_price_per_unit',
			),
			array(
				'source'   => 'WC | Converted Unit',      // ex. « kg »
				'type'     => 'meta',
				'meta_key' => 'TODO_converted_unit',
			),

			// --- Consigne : meta_key à confirmer ---
			array(
				'source'   => 'Consigne | Consigne Price',
				'type'     => 'meta',
				'meta_key' => 'TODO_consigne_price',
			),
			array(
				'source'   => 'WC | Consigned Sale Price',
				'type'     => 'meta',
				'meta_key' => 'TODO_consigned_sale_price',
			),

			// --- Onglets / attributs descriptifs : meta_key à confirmer ---
			array(
				'source'   => 'Attributs Composition du produit',
				'type'     => 'meta',
				'meta_key' => 'TODO_composition',
			),
			array(
				'source'   => 'Attribut Product Informations',
				'type'     => 'meta',
				'meta_key' => 'TODO_product_info',
			),
			array(
				'source'   => 'Attributs DLC',
				'type'     => 'meta',
				'meta_key' => 'TODO_dlc',
			),
			array(
				'source'   => 'WC | Product Pays',
				'type'     => 'meta',
				'meta_key' => 'TODO_pays',
			),
			array(
				'source'   => 'WC | Alternative Name',
				'type'     => 'meta',
				'meta_key' => 'TODO_alternative_name',
			),
		);

		/**
		 * Permet de surcharger le mapping sans éditer ce fichier.
		 * Pratique pour brancher les vrais meta_key depuis un mu-plugin.
		 */
		return apply_filters( 'lms_ats_field_map', $map );
	}
}
