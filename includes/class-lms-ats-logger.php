<?php
/**
 * Journal des synchronisations (stocké dans une option, conserve les N dernières).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LMS_ATS_Logger {

	const OPTION_KEY = 'lms_ats_logs';
	const MAX_RUNS   = 20; // Nombre de comptes-rendus conservés.

	/**
	 * Enregistre le compte-rendu d'une synchro.
	 *
	 * @param array $report ['source','created','updated','skipped','errors','messages','duration','dry_run']
	 */
	public static function record( array $report ) {
		$logs = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $logs ) ) {
			$logs = array();
		}

		$report['time'] = current_time( 'mysql' );
		array_unshift( $logs, $report );
		$logs = array_slice( $logs, 0, self::MAX_RUNS );

		update_option( self::OPTION_KEY, $logs, false );
	}

	/**
	 * @return array Liste des comptes-rendus, plus récent en premier.
	 */
	public static function all() {
		$logs = get_option( self::OPTION_KEY, array() );
		return is_array( $logs ) ? $logs : array();
	}

	public static function clear() {
		delete_option( self::OPTION_KEY );
	}
}
