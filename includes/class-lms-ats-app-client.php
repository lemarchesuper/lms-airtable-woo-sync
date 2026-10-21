<?php
/**
 * Client du flux catalogue de l'app (lms-app).
 *
 * Remplace Airtable comme source, sans que le reste du plugin change : même
 * interface que LMS_ATS_Airtable_Client (fetch_all_records → {records, error}),
 * et l'app sert délibérément les MÊMES noms de champs (« WC | Product Stock »,
 * « AT | Product Name »…). Le mapping, les transforms et l'upsert continuent
 * donc de travailler à l'identique.
 *
 * Deux différences de fond avec Airtable, et elles sont en notre faveur :
 *
 *   - Pas de pagination : l'app renvoie tout le catalogue en une réponse
 *     (750 fiches ≈ 400 Ko), donc pas de limite de débit à respecter.
 *   - Les champs VIDES sont ABSENTS de la réponse, jamais envoyés vides. Le
 *     moteur ignore déjà les champs absents et laisse la valeur du produit
 *     WooCommerce intacte : une fiche sans description ne videra donc pas la
 *     description en ligne.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LMS_ATS_App_Client {

	/** @var string URL du flux, ex https://app.lemarchesuper.com/flux-web.php */
	private $url;
	/** @var string Secret partagé (FLUX_WEB_KEY côté app). */
	private $key;

	const TIMEOUT = 30;

	public function __construct( $url, $key ) {
		$this->url = trim( (string) $url );
		$this->key = trim( (string) $key );
	}

	/**
	 * Récupère tous les enregistrements du flux.
	 *
	 * @param string $view Ignoré — l'app décide elle-même ce qui est publiable
	 *                     (to_import = 1, non archivé). Le paramètre existe pour
	 *                     garder la signature du client Airtable.
	 * @return array{records: array, error: ?string}
	 */
	public function fetch_all_records( $view = '' ) {
		if ( '' === $this->url ) {
			return array( 'records' => array(), 'error' => 'URL du flux non configurée.' );
		}
		if ( '' === $this->key ) {
			return array( 'records' => array(), 'error' => 'Clé du flux non configurée.' );
		}

		$response = wp_remote_get( $this->url, array(
			'timeout' => self::TIMEOUT,
			'headers' => array(
				// La clé passe en entête, jamais en query string : une URL se
				// retrouve dans les journaux du serveur, un entête non.
				'X-Flux-Key' => $this->key,
				'Accept'     => 'application/json',
			),
		) );

		if ( is_wp_error( $response ) ) {
			return array( 'records' => array(), 'error' => $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( 200 !== $code ) {
			$msg = 'HTTP ' . $code;
			$j   = json_decode( $body, true );
			if ( is_array( $j ) && ! empty( $j['error'] ) ) {
				$msg .= ' — ' . $j['error'];
			}
			return array( 'records' => array(), 'error' => 'Flux app : ' . $msg );
		}

		$data = json_decode( $body, true );
		if ( ! is_array( $data ) || empty( $data['ok'] ) || ! isset( $data['records'] ) ) {
			return array( 'records' => array(), 'error' => 'Réponse du flux illisible.' );
		}

		/* Un flux vide est presque toujours une panne — une clé qui a changé, un
		   filtre trop strict — et l'appliquer déclencherait la politique des
		   absents sur TOUT le catalogue : jusqu'à mettre la boutique entière en
		   rupture. On refuse plutôt que d'obéir. */
		if ( ! $data['records'] ) {
			return array( 'records' => array(), 'error' =>
				'Le flux ne renvoie aucun article : synchronisation annulée par sécurité.' );
		}

		return array( 'records' => $data['records'], 'error' => null );
	}
}
