<?php
/**
 * Table de correspondance : champ Airtable (table WEB | Products) → cible WooCommerce.
 *
 * Chaque règle décrit COMMENT écrire une valeur Airtable dans Woo :
 *   - 'source'   : nom EXACT du champ Airtable (tel que renvoyé par l'API REST).
 *   - 'type'     : 'core' | 'meta' | 'category' | 'taxonomy'.
 *   - 'core'     : (si type=core) title|regular_price|stock_status|catalog_visibility|description|sku.
 *   - 'meta_key' : (si type=meta) nom du champ (clé ACF, sans underscore).
 *   - 'acf'      : (si type=meta) true = champ ACF → écrit via update_field() pour poser
 *                  aussi la référence de clé `_champ = field_xxx` (indispensable en création).
 *   - 'taxonomy' : (si type=taxonomy) slug de la taxonomie (terme créé s'il manque).
 *   - 'transform': (optionnel) nom d'une méthode statique de LMS_ATS_Transforms.
 *
 * Champs ACF relevés en live le 2026-06-30 (REST API, produit 5184).
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

			// --- Titre & nom affiché ---
			array(
				'source' => 'AT | Product Name Poids',
				'type'   => 'core',
				'core'   => 'title',
			),
			array(
				'source'   => 'AT | Product Name', // sans le poids (le titre WC, lui, inclut le poids).
				'type'     => 'meta',
				'meta_key' => 'product_displayed_name',
				'acf'      => true,
			),

			// --- EAN13 → SKU WooCommerce (le matching utilise airtable_record_id, le SKU est donc libre) ---
			array(
				'source' => 'NEW Product EAN13',
				'type'   => 'core',
				'core'   => 'sku',
			),

			// --- Prix ---
			// ⚠️ À CONFIRMER : le prix WooCommerce facturé (_regular_price) =
			//    « WC | Consigned Sale Price » (net + consigne) ? Hypothèse retenue.
			//    Si le thème ajoute la consigne séparément, basculer sur « WC | Net Sale Price ».
			array(
				'source'    => 'WC | Consigned Sale Price',
				'type'      => 'core',
				'core'      => 'regular_price',
				'transform' => 'price',
			),
			array(
				'source'    => 'WC | Net Sale Price',
				'type'      => 'meta',
				'meta_key'  => 'net_price', // affichage « prix à afficher (net de consigne) ».
				'transform' => 'price',
				'acf'       => true,
			),
			array(
				'source'    => 'Consigne | Consigne Price',
				'type'      => 'meta',
				'meta_key'  => 'consigne_price',
				'transform' => 'price',
				'acf'       => true,
			),

			// --- Stock / visibilité / description ---
			array(
				'source'    => 'WC | Product Stock',
				'type'      => 'core',
				'core'      => 'stock_status',
				'transform' => 'stock_status',
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

			// --- Conditionnement (champs ACF) ---
			array(
				'source'   => 'WC | Product Packaging', // ex. « 500gr », « 1kg »
				'type'     => 'meta',
				'meta_key' => 'product_package',
				'acf'      => true,
			),
			array(
				'source'    => 'WC | Price/Unit',        // ex. « 78.00 » (prix au kg)
				'type'      => 'meta',
				'meta_key'  => 'price_unit',
				'transform' => 'price',
				'acf'       => true,
			),
			array(
				'source'   => 'WC | Converted Unit',     // ex. « kg »
				'type'     => 'meta',
				'meta_key' => 'unit_price',              // (radio kg/l/unité — nommage ACF).
				'acf'      => true,
			),

			// --- Producteur : HORS SCOPE court terme ---
			// Champ ACF de relation bidirectionnelle (≠ simple meta). À brancher plus tard
			// via l'API ACF (update_field) en gérant la réciprocité. Non synchronisé pour l'instant.

			// --- Attributs WooCommerce globaux (Composition, DLC, Informations…) : à faire plus tard ---
			// Attributs globaux (pa_*), renseignés partiellement. Nécessite les slugs pa_* +
			// gestion des termes. Reporté après validation de la synchro de base.

			// --- Catégories produit hiérarchiques « Parent>Enfant » ---
			array(
				'source'    => 'WC | Product Category Calculated',
				'type'      => 'category',
				'transform' => 'category_path',
			),

			// --- Taxonomies (terme posé sur le produit, créé si absent) ---
			array(
				'source'   => 'WC | Product Pays',
				'type'     => 'taxonomy',
				'taxonomy' => 'pays',
			),
			array(
				'source'   => 'AT | Label',
				'type'     => 'taxonomy',
				'taxonomy' => 'label',
			),
			array(
				'source'   => 'Packagings', // type d'emballage : Contenant Consigné, Kraft…
				'type'     => 'taxonomy',
				'taxonomy' => 'packaging',
			),
		);

		/**
		 * Permet de surcharger le mapping sans éditer ce fichier.
		 */
		return apply_filters( 'lms_ats_field_map', $map );
	}
}
