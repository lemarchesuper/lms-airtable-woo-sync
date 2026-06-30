<?php
/**
 * Client API Airtable : récupère les enregistrements d'une vue avec pagination
 * et respect de la limite de débit (5 req/s par base).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LMS_ATS_Airtable_Client {

	/** @var string */
	private $token;
	/** @var string */
	private $base_id;
	/** @var string */
	private $table_id;

	const API_ROOT   = 'https://api.airtable.com/v0/';
	const PAGE_SIZE  = 100;          // Max Airtable.
	const THROTTLE_US = 220000;      // ~0,22 s entre deux requêtes → < 5 req/s.

	public function __construct( $token, $base_id, $table_id ) {
		$this->token    = $token;
		$this->base_id  = $base_id;
		$this->table_id = $table_id;
	}

	/**
	 * Récupère TOUS les enregistrements d'une vue (suit la pagination).
	 *
	 * @param string $view Nom ou ID de la vue.
	 * @return array{records: array, error: ?string}
	 */
	public function fetch_all_records( $view = '' ) {
		$records = array();
		$offset  = null;

		do {
			$query = array( 'pageSize' => self::PAGE_SIZE );
			if ( $view ) {
				$query['view'] = $view;
			}
			if ( $offset ) {
				$query['offset'] = $offset;
			}

			$result = $this->request( $query );
			if ( ! empty( $result['error'] ) ) {
				return array(
					'records' => $records,
					'error'   => $result['error'],
				);
			}

			if ( ! empty( $result['data']['records'] ) ) {
				$records = array_merge( $records, $result['data']['records'] );
			}

			$offset = $result['data']['offset'] ?? null;

			if ( $offset ) {
				usleep( self::THROTTLE_US ); // Respecte la limite de débit.
			}
		} while ( $offset );

		return array(
			'records' => $records,
			'error'   => null,
		);
	}

	/**
	 * Effectue une requête GET unique vers l'API Airtable.
	 *
	 * @return array{data: ?array, error: ?string}
	 */
	private function request( array $query ) {
		$url = self::API_ROOT . rawurlencode( $this->base_id ) . '/' . rawurlencode( $this->table_id );
		$url = add_query_arg( $query, $url );

		$response = wp_remote_get( $url, array(
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->token,
				'Content-Type'  => 'application/json',
			),
		) );

		if ( is_wp_error( $response ) ) {
			return array( 'data' => null, 'error' => $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 429 === $code ) {
			// Rate limit atteint : Airtable demande 30 s d'attente. On temporise et on signale.
			sleep( 2 );
			return array( 'data' => null, 'error' => 'Rate limit Airtable (429). Réessayez dans 30 s.' );
		}

		if ( $code < 200 || $code >= 300 ) {
			$msg = isset( $body['error']['message'] ) ? $body['error']['message'] : ( 'HTTP ' . $code );
			return array( 'data' => null, 'error' => 'Airtable : ' . $msg );
		}

		return array( 'data' => $body, 'error' => null );
	}
}
