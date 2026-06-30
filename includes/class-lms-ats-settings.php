<?php
/**
 * Gestion des réglages du plugin (option unique sérialisée).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LMS_ATS_Settings {

	const OPTION_KEY = 'lms_ats_settings';

	/**
	 * Valeurs par défaut. Base/table/vue pré-remplies d'après la base « Odoo Products ».
	 * Le token API n'est JAMAIS en dur : il se saisit dans l'admin.
	 */
	public static function defaults() {
		return array(
			'api_token'        => '',                  // Personal Access Token Airtable (scope data.records:read).
			'base_id'          => 'appmHw1H1n5WYIS0N', // Base « Odoo Products ».
			'table_id'         => 'tblZXnFdvmME2D4sm', // Table « WEB | Products ».
			'view'             => 'To Import WC',       // Vue source (filtre WC | To Import).
			'match_meta_key'   => 'airtable_record_id', // meta_key JetEngine qui stocke le Record ID Airtable.
			'cron_frequency'   => 'hourly',             // disabled | lms_ats_5min | lms_ats_15min | hourly.
			'absent_policy'    => 'ignore',             // ignore | outofstock | draft (que faire si un produit Woo n'est plus dans la vue).
			'dry_run'          => 0,                     // 1 = simulation (n'écrit rien, log seulement).
			'create_missing'   => 1,                     // 1 = créer les produits absents de Woo.
		);
	}

	/**
	 * Récupère les réglages fusionnés avec les défauts.
	 */
	public static function get() {
		$saved = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return array_merge( self::defaults(), $saved );
	}

	/**
	 * Enregistre les réglages (après assainissement).
	 */
	public static function save( array $input ) {
		$clean = self::sanitize( $input );
		update_option( self::OPTION_KEY, $clean );
		return $clean;
	}

	/**
	 * Assainit les entrées du formulaire.
	 */
	public static function sanitize( array $input ) {
		$d     = self::defaults();
		$clean = array();

		$clean['api_token']      = isset( $input['api_token'] ) ? trim( sanitize_text_field( $input['api_token'] ) ) : '';
		$clean['base_id']        = isset( $input['base_id'] ) ? sanitize_text_field( $input['base_id'] ) : $d['base_id'];
		$clean['table_id']       = isset( $input['table_id'] ) ? sanitize_text_field( $input['table_id'] ) : $d['table_id'];
		$clean['view']           = isset( $input['view'] ) ? sanitize_text_field( $input['view'] ) : $d['view'];
		$clean['match_meta_key'] = isset( $input['match_meta_key'] ) ? sanitize_key( $input['match_meta_key'] ) : $d['match_meta_key'];

		$freqs = array( 'disabled', 'lms_ats_5min', 'lms_ats_15min', 'hourly' );
		$clean['cron_frequency'] = ( isset( $input['cron_frequency'] ) && in_array( $input['cron_frequency'], $freqs, true ) ) ? $input['cron_frequency'] : $d['cron_frequency'];

		$policies = array( 'ignore', 'outofstock', 'draft' );
		$clean['absent_policy'] = ( isset( $input['absent_policy'] ) && in_array( $input['absent_policy'], $policies, true ) ) ? $input['absent_policy'] : $d['absent_policy'];

		$clean['dry_run']        = ! empty( $input['dry_run'] ) ? 1 : 0;
		$clean['create_missing'] = ! empty( $input['create_missing'] ) ? 1 : 0;

		return $clean;
	}
}
