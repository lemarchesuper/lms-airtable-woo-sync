<?php
/**
 * Transformations de valeurs Airtable → format attendu par WooCommerce.
 * Chaque méthode est référencée par son nom dans la table de mapping.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LMS_ATS_Transforms {

	/**
	 * Normalise un statut de stock Airtable → 'instock' | 'outofstock'.
	 */
	public static function stock_status( $value ) {
		$v = strtolower( trim( (string) self::scalar( $value ) ) );
		if ( in_array( $v, array( 'instock', 'in stock', 'en stock', 'true', '1', 'oui' ), true ) ) {
			return 'instock';
		}
		return 'outofstock';
	}

	/**
	 * Normalise la visibilité catalogue.
	 * Airtable renvoie souvent 'visible' / "'Visible'" (avec apostrophes) → 'visible'|'hidden'.
	 */
	public static function catalog_visibility( $value ) {
		$v = strtolower( trim( (string) self::scalar( $value ), " '\"" ) );
		if ( in_array( $v, array( 'hidden', 'masqué', 'masque', 'false', '0', 'non', 'outofstock' ), true ) ) {
			return 'hidden';
		}
		return 'visible';
	}

	/**
	 * Découpe un chemin de catégorie « Parent>Enfant>Sous-enfant » en tableau de niveaux.
	 * Retourne ['Parent', 'Enfant', ...] (trimés).
	 */
	public static function category_path( $value ) {
		$raw   = (string) self::scalar( $value );
		$parts = array_map( 'trim', explode( '>', $raw ) );
		return array_values( array_filter( $parts, 'strlen' ) );
	}

	/**
	 * Réduit une valeur Airtable (parfois tableau pour lookups/links) à un scalaire.
	 */
	public static function scalar( $value ) {
		if ( is_array( $value ) ) {
			// Lookup/link Airtable : on prend le premier élément.
			$first = reset( $value );
			if ( is_array( $first ) ) {
				// ex. {id, name} ou pièce jointe.
				return $first['name'] ?? ( $first['value'] ?? '' );
			}
			return $first;
		}
		return $value;
	}
}
