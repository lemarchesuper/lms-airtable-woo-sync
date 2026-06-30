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
	 * Normalise un prix : « 3,90 » / « 3 90 » → « 3.90 ». Vide si non numérique.
	 */
	public static function price( $value ) {
		$v = trim( (string) self::scalar( $value ) );
		if ( '' === $v ) {
			return '';
		}
		$v = str_replace( array( ' ', "\xc2\xa0" ), '', $v ); // espaces / insécables.
		$v = str_replace( ',', '.', $v );
		return is_numeric( $v ) ? $v : '';
	}

	/**
	 * Réduit une valeur Airtable (parfois tableau/objet pour lookups/links/select) à un scalaire.
	 * Avec cellFormat=string l'API renvoie déjà des chaînes ; ce repli gère les autres cas.
	 */
	public static function scalar( $value ) {
		if ( is_array( $value ) ) {
			// Objet select/collaborateur : {id, name, color}.
			if ( isset( $value['name'] ) ) {
				$value = $value['name'];
			} elseif ( isset( $value['value'] ) ) {
				$value = $value['value'];
			} else {
				// Lookup/link/multipleSelects : tableau → premier élément.
				$first = reset( $value );
				$value = is_array( $first ) ? ( $first['name'] ?? ( $first['value'] ?? '' ) ) : $first;
			}
		}
		return is_string( $value ) ? trim( $value ) : $value;
	}
}
