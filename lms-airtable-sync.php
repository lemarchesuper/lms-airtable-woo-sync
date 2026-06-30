<?php
/**
 * Plugin Name: LMS Airtable Sync
 * Description: Synchronisation descendante (pull) Airtable → WooCommerce pour Le Marché Super. Récupère les produits depuis Airtable (base « Odoo Products », table « WEB | Products », vue « To Import WC ») et crée/met à jour les produits WooCommerce. Matching par un meta_key dédié contenant le Record ID Airtable.
 * Version: 0.3.0
 * Author: Le Marché Super
 * Requires PHP: 7.4
 * Requires at least: 6.0
 * Text Domain: lms-airtable-sync
 *
 * Périmètre V1 :
 *  - Sens unique Airtable → WooCommerce (lecture seule côté Airtable).
 *  - Pull périodique (cron) + bouton « Synchroniser maintenant ».
 *  - Ne touche JAMAIS aux images (gérées manuellement dans Woo).
 *  - Ne supprime aucun produit par défaut (politique configurable).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Pas d'accès direct.
}

define( 'LMS_ATS_VERSION', '0.3.0' );
define( 'LMS_ATS_FILE', __FILE__ );
define( 'LMS_ATS_DIR', plugin_dir_path( __FILE__ ) );
define( 'LMS_ATS_URL', plugin_dir_url( __FILE__ ) );
define( 'LMS_ATS_CRON_HOOK', 'lms_ats_cron_sync' );

require_once LMS_ATS_DIR . 'includes/class-lms-ats-settings.php';
require_once LMS_ATS_DIR . 'includes/class-lms-ats-airtable-client.php';
require_once LMS_ATS_DIR . 'includes/class-lms-ats-field-map.php';
require_once LMS_ATS_DIR . 'includes/class-lms-ats-transforms.php';
require_once LMS_ATS_DIR . 'includes/class-lms-ats-logger.php';
require_once LMS_ATS_DIR . 'includes/class-lms-ats-sync-engine.php';
require_once LMS_ATS_DIR . 'includes/class-lms-ats-admin.php';

/**
 * Garde-fou : WooCommerce doit être actif.
 */
function lms_ats_requires_woocommerce() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p><strong>LMS Airtable Sync</strong> nécessite WooCommerce actif.</p></div>';
		} );
		return false;
	}
	return true;
}

/**
 * Initialisation.
 */
function lms_ats_init() {
	if ( ! lms_ats_requires_woocommerce() ) {
		return;
	}

	// Admin (réglages + logs + bouton sync).
	if ( is_admin() ) {
		( new LMS_ATS_Admin() )->hooks();
	}

	// Cron : exécute la synchro planifiée.
	add_action( LMS_ATS_CRON_HOOK, 'lms_ats_run_scheduled_sync' );
}
add_action( 'plugins_loaded', 'lms_ats_init' );

/**
 * Point d'entrée du cron.
 */
function lms_ats_run_scheduled_sync() {
	$engine = new LMS_ATS_Sync_Engine();
	$engine->run( 'cron' );
}

/**
 * Planifie le cron à l'activation selon la fréquence réglée.
 */
function lms_ats_activate() {
	lms_ats_reschedule_cron();
}
register_activation_hook( __FILE__, 'lms_ats_activate' );

/**
 * Nettoie le cron à la désactivation.
 */
function lms_ats_deactivate() {
	wp_clear_scheduled_hook( LMS_ATS_CRON_HOOK );
}
register_deactivation_hook( __FILE__, 'lms_ats_deactivate' );

/**
 * (Re)planifie le cron selon le réglage « fréquence ».
 * Appelé à l'activation et à chaque sauvegarde des réglages.
 */
function lms_ats_reschedule_cron() {
	wp_clear_scheduled_hook( LMS_ATS_CRON_HOOK );

	$settings  = LMS_ATS_Settings::get();
	$frequency = $settings['cron_frequency'] ?? 'hourly';

	if ( 'disabled' === $frequency ) {
		return; // Synchro manuelle uniquement.
	}

	if ( ! wp_next_scheduled( LMS_ATS_CRON_HOOK ) ) {
		wp_schedule_event( time() + 60, $frequency, LMS_ATS_CRON_HOOK );
	}
}

/**
 * Ajoute des intervalles cron personnalisés (toutes les 5 / 15 minutes).
 */
function lms_ats_cron_schedules( $schedules ) {
	$schedules['lms_ats_5min'] = array(
		'interval' => 5 * MINUTE_IN_SECONDS,
		'display'  => 'Toutes les 5 minutes (LMS Airtable Sync)',
	);
	$schedules['lms_ats_15min'] = array(
		'interval' => 15 * MINUTE_IN_SECONDS,
		'display'  => 'Toutes les 15 minutes (LMS Airtable Sync)',
	);
	return $schedules;
}
add_filter( 'cron_schedules', 'lms_ats_cron_schedules' );
