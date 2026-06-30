<?php
/**
 * Interface d'administration en onglets : Paramètres / Mapping / Journal.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LMS_ATS_Admin {

	const MENU_SLUG = 'lms-airtable-sync';
	const NONCE     = 'lms_ats_nonce';

	/** Onglets disponibles. */
	private function tabs() {
		return array(
			'settings' => 'Paramètres',
			'mapping'  => 'Mapping',
			'logs'     => 'Journal',
		);
	}

	public function hooks() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_lms_ats_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_lms_ats_sync', array( $this, 'handle_sync_now' ) );
	}

	public function menu() {
		add_submenu_page(
			'woocommerce',
			'Airtable Sync',
			'Airtable Sync',
			'manage_woocommerce',
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	/** URL d'un onglet. */
	private function tab_url( $tab ) {
		return add_query_arg( array( 'page' => self::MENU_SLUG, 'tab' => $tab ), admin_url( 'admin.php' ) );
	}

	/** Onglet courant (assaini). */
	private function current_tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'settings';
		return array_key_exists( $tab, $this->tabs() ) ? $tab : 'settings';
	}

	/**
	 * Sauvegarde des réglages.
	 */
	public function handle_save() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! check_admin_referer( self::NONCE ) ) {
			wp_die( 'Accès refusé.' );
		}
		LMS_ATS_Settings::save( $_POST['lms_ats'] ?? array() );
		lms_ats_reschedule_cron();
		wp_safe_redirect( add_query_arg( array( 'page' => self::MENU_SLUG, 'tab' => 'settings', 'saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Déclenchement d'une synchro manuelle. Redirige sur l'onglet Journal.
	 */
	public function handle_sync_now() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! check_admin_referer( self::NONCE ) ) {
			wp_die( 'Accès refusé.' );
		}
		$engine = new LMS_ATS_Sync_Engine();
		$report = $engine->run( 'manual' );
		$flag   = $report['errors'] > 0 ? 'synced_err' : 'synced';
		wp_safe_redirect( add_query_arg( array( 'page' => self::MENU_SLUG, 'tab' => 'logs', $flag => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Rendu de la page (entête + onglets + contenu de l'onglet courant).
	 */
	public function render_page() {
		$tab = $this->current_tab();
		?>
		<div class="wrap">
			<h1>LMS Airtable Sync <span style="font-size:13px;color:#888;">v<?php echo esc_html( LMS_ATS_VERSION ); ?></span></h1>

			<?php if ( isset( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>Réglages enregistrés.</p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['synced'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>Synchronisation terminée. Détails dans le journal ci-dessous.</p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['synced_err'] ) ) : ?>
				<div class="notice notice-error is-dismissible"><p>Synchronisation terminée avec des erreurs. Voir le journal.</p></div>
			<?php endif; ?>

			<h2 class="nav-tab-wrapper">
				<?php foreach ( $this->tabs() as $key => $label ) : ?>
					<a href="<?php echo esc_url( $this->tab_url( $key ) ); ?>"
						class="nav-tab <?php echo $tab === $key ? 'nav-tab-active' : ''; ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</h2>

			<?php
			if ( 'mapping' === $tab ) {
				$this->render_tab_mapping();
			} elseif ( 'logs' === $tab ) {
				$this->render_tab_logs();
			} else {
				$this->render_tab_settings();
			}
			?>
		</div>
		<?php
	}

	/**
	 * Bouton « Synchroniser maintenant » (réutilisé sur plusieurs onglets).
	 */
	private function render_sync_button() {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="lms_ats_sync">
			<?php wp_nonce_field( self::NONCE ); ?>
			<p>
				<button type="submit" class="button button-primary">Synchroniser maintenant</button>
				<span class="description">Recommandé pour un premier essai : cocher « Mode simulation » dans les Paramètres.</span>
			</p>
		</form>
		<?php
	}

	/**
	 * Onglet Paramètres : connexion + synchronisation + bouton.
	 */
	private function render_tab_settings() {
		$s = LMS_ATS_Settings::get();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="lms_ats_save">
			<?php wp_nonce_field( self::NONCE ); ?>

			<h2>Connexion Airtable</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="api_token">Token API (PAT)</label></th>
					<td>
						<input name="lms_ats[api_token]" id="api_token" type="password" class="regular-text" autocomplete="off"
							value="<?php echo esc_attr( $s['api_token'] ); ?>" placeholder="patXXXXXXXXXXXXXX...">
						<p class="description">Personal Access Token avec le scope <code>data.records:read</code> sur la base.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="base_id">Base ID</label></th>
					<td><input name="lms_ats[base_id]" id="base_id" type="text" class="regular-text" value="<?php echo esc_attr( $s['base_id'] ); ?>">
						<p class="description">Base « Odoo Products ».</p></td>
				</tr>
				<tr>
					<th scope="row"><label for="table_id">Table ID</label></th>
					<td><input name="lms_ats[table_id]" id="table_id" type="text" class="regular-text" value="<?php echo esc_attr( $s['table_id'] ); ?>">
						<p class="description">Table « WEB | Products ».</p></td>
				</tr>
				<tr>
					<th scope="row"><label for="view">Vue</label></th>
					<td><input name="lms_ats[view]" id="view" type="text" class="regular-text" value="<?php echo esc_attr( $s['view'] ); ?>">
						<p class="description">Vue filtrée à importer (ex. « To Import WC »).</p></td>
				</tr>
			</table>

			<h2>Synchronisation</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="match_meta_key">Clé de matching (meta_key)</label></th>
					<td><input name="lms_ats[match_meta_key]" id="match_meta_key" type="text" class="regular-text" value="<?php echo esc_attr( $s['match_meta_key'] ); ?>">
						<p class="description">Champ personnalisé Woo qui stocke le Record ID Airtable. Pivot de la synchro.</p></td>
				</tr>
				<tr>
					<th scope="row"><label for="cron_frequency">Fréquence (cron)</label></th>
					<td>
						<select name="lms_ats[cron_frequency]" id="cron_frequency">
							<?php
							$freqs = array(
								'disabled'      => 'Désactivé (manuel uniquement)',
								'lms_ats_5min'  => 'Toutes les 5 minutes',
								'lms_ats_15min' => 'Toutes les 15 minutes',
								'hourly'        => 'Toutes les heures',
							);
							foreach ( $freqs as $k => $label ) {
								printf( '<option value="%s" %s>%s</option>', esc_attr( $k ), selected( $s['cron_frequency'], $k, false ), esc_html( $label ) );
							}
							?>
						</select>
						<p class="description">WP-Cron dépend du trafic. Pour un déclenchement fiable, appeler <code>wp-cron.php</code> via un vrai cron cPanel.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="absent_policy">Produit absent de la vue</label></th>
					<td>
						<select name="lms_ats[absent_policy]" id="absent_policy">
							<?php
							$pol = array(
								'ignore'     => 'Ne rien faire (recommandé)',
								'outofstock' => 'Passer en rupture de stock',
								'draft'      => 'Passer en brouillon',
							);
							foreach ( $pol as $k => $label ) {
								printf( '<option value="%s" %s>%s</option>', esc_attr( $k ), selected( $s['absent_policy'], $k, false ), esc_html( $label ) );
							}
							?>
						</select>
						<p class="description">Aucune suppression n'est jamais effectuée. ⚠️ N'activer rupture/brouillon qu'avec la vue COMPLÈTE.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Options</th>
					<td>
						<label><input type="checkbox" name="lms_ats[create_missing]" value="1" <?php checked( $s['create_missing'], 1 ); ?>> Créer les produits absents de WooCommerce</label><br>
						<label><input type="checkbox" name="lms_ats[dry_run]" value="1" <?php checked( $s['dry_run'], 1 ); ?>> Mode simulation (n'écrit rien, journalise seulement)</label>
					</td>
				</tr>
			</table>

			<?php submit_button( 'Enregistrer les réglages' ); ?>
		</form>

		<hr>
		<h2>Lancer une synchronisation</h2>
		<?php $this->render_sync_button(); ?>
		<?php
	}

	/**
	 * Onglet Mapping : tableau de correspondance (lecture).
	 */
	private function render_tab_mapping() {
		?>
		<h2>Mapping des champs (lecture)</h2>
		<p class="description">Correspondances Airtable → WooCommerce. Les clés <code>TODO_*</code> ne sont PAS écrites tant qu'elles ne sont pas remplacées par les vrais <code>meta_key</code> (fichier <code>includes/class-lms-ats-field-map.php</code>).</p>
		<table class="widefat striped" style="max-width:900px;">
			<thead><tr><th>Champ Airtable</th><th>Type</th><th>Cible Woo</th></tr></thead>
			<tbody>
			<?php foreach ( LMS_ATS_Field_Map::get() as $rule ) :
				switch ( $rule['type'] ) {
					case 'core':
						$target = 'natif : ' . $rule['core'];
						break;
					case 'meta':
						$target = 'meta : ' . $rule['meta_key'];
						break;
					case 'taxonomy':
						$target = 'taxonomie : ' . $rule['taxonomy'];
						break;
					case 'category':
						$target = 'catégories produit (product_cat)';
						break;
					default:
						$target = $rule['type'];
				}
				$todo = ( isset( $rule['meta_key'] ) && 0 === strpos( $rule['meta_key'], 'TODO_' ) );
				?>
				<tr<?php echo $todo ? ' style="background:#fff8e5;"' : ''; ?>>
					<td><code><?php echo esc_html( $rule['source'] ); ?></code></td>
					<td><?php echo esc_html( $rule['type'] ); ?></td>
					<td><?php echo esc_html( $target ); ?><?php echo $todo ? ' ⚠️' : ''; ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Onglet Journal : bouton de sync + historique.
	 */
	private function render_tab_logs() {
		$logs = LMS_ATS_Logger::all();
		$this->render_sync_button();
		?>
		<hr>
		<h2>Journal des synchronisations</h2>
		<?php if ( empty( $logs ) ) : ?>
			<p>Aucune synchronisation pour l'instant.</p>
		<?php else : ?>
			<table class="widefat striped" style="max-width:1000px;">
				<thead><tr><th>Date</th><th>Source</th><th>Récupérés</th><th>Créés</th><th>MàJ</th><th>Inchangés</th><th>Ignorés</th><th>Erreurs</th><th>Durée</th><th>Détails</th></tr></thead>
				<tbody>
				<?php foreach ( $logs as $log ) : ?>
					<tr>
						<td><?php echo esc_html( $log['time'] ?? '' ); ?><?php echo ! empty( $log['dry_run'] ) ? ' <em>(simu)</em>' : ''; ?></td>
						<td><?php echo esc_html( $log['source'] ?? '' ); ?></td>
						<td><?php echo esc_html( $log['fetched'] ?? '—' ); ?></td>
						<td><?php echo (int) ( $log['created'] ?? 0 ); ?></td>
						<td><?php echo (int) ( $log['updated'] ?? 0 ); ?></td>
						<td style="color:#646970;"><?php echo (int) ( $log['unchanged'] ?? 0 ); ?></td>
						<td><?php echo (int) ( $log['skipped'] ?? 0 ); ?></td>
						<td<?php echo ! empty( $log['errors'] ) ? ' style="color:#b32d2e;font-weight:bold;"' : ''; ?>><?php echo (int) ( $log['errors'] ?? 0 ); ?></td>
						<td><?php echo esc_html( ( $log['duration'] ?? 0 ) . ' s' ); ?></td>
						<td>
							<?php if ( ! empty( $log['messages'] ) ) : ?>
								<details><summary><?php echo count( $log['messages'] ); ?> message(s)</summary>
									<ul style="margin:6px 0 0 14px;list-style:disc;">
										<?php foreach ( $log['messages'] as $m ) : ?>
											<li><?php echo esc_html( $m ); ?></li>
										<?php endforeach; ?>
									</ul>
								</details>
							<?php else : ?>—<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}
}
