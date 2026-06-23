<?php
/**
 * @package Supertext_Polylang
 */

namespace Supertext\Polylang\Human_Translation;

defined( 'ABSPATH' ) || exit;

use Supertext\Polylang\Admin\Settings;
use WP_Error;

/**
 * Client for Supertext's human / professional translation Order API.
 *
 * Authenticates with HTTP Basic (account email + Legacy API Key) against the
 * configured environment. Implements the two calls needed to place an order:
 *   1. upload the HTML document  (POST api/v1/files/files)
 *   2. create the order          (POST api/v1.1/translation/order)
 *
 * The response/callback handling is intentionally out of scope for now.
 *
 * @since 0.5.0
 */
class Client {
	/**
	 * Environment base URL (trailing slash).
	 *
	 * @var string
	 */
	private $base;

	/**
	 * Account email (Basic auth username).
	 *
	 * @var string
	 */
	private $email;

	/**
	 * Legacy API key (Basic auth password).
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Constructor — reads credentials from the plugin settings.
	 */
	public function __construct() {
		$this->base    = Settings::base_url();
		$this->email   = Settings::email();
		$this->api_key = Settings::api_key();
	}

	/**
	 * Uploads the document to translate.
	 *
	 * @param string $html     The HTML content.
	 * @param string $filename The file name (should end in .html).
	 * @return int|WP_Error The Supertext DocumentId on success.
	 */
	public function upload_file( string $html, string $filename ) {
		list( $boundary, $body ) = $this->build_multipart(
			array(
				'ElementId'      => '0',
				'ElementTypeId'  => '2',
				'DocumentTypeId' => '1',
			),
			array(
				'name'     => 'file',
				'filename' => $filename,
				'type'     => 'text/html',
				'content'  => $html,
			)
		);

		$response = $this->request(
			'POST',
			'api/v1/files/files',
			array(
				'headers' => array( 'Content-Type' => 'multipart/form-data; boundary=' . $boundary ),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$raw  = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		// The upload endpoint returns a JSON array of document objects (one per
		// uploaded file). Pick the document with the highest Id (the one we just added).
		$document_id = 0;
		if ( is_array( $data ) ) {
			if ( isset( $data['Id'] ) ) {
				$document_id = (int) $data['Id']; // Defensive: single-object response.
			} else {
				foreach ( $data as $item ) {
					if ( is_array( $item ) && isset( $item['Id'] ) ) {
						$document_id = max( $document_id, (int) $item['Id'] );
					}
				}
			}
		}

		if ( $document_id <= 0 ) {
			return new WP_Error(
				'supertext_no_document_id',
				sprintf(
					/* translators: %s is the raw API response. */
					__( 'Supertext did not return a document id for the uploaded file. Response: %s', 'supertext-polylang' ),
					mb_substr( wp_strip_all_tags( $raw ), 0, 200 )
				)
			);
		}

		return $document_id;
	}

	/**
	 * Creates a translation order.
	 *
	 * @param array $order The order body (see Bulk_Actions for the assembled fields).
	 * @return array|WP_Error The decoded response on success.
	 */
	public function create_order( array $order ) {
		$response = $this->request(
			'POST',
			'api/v1.1/translation/order',
			array(
				'headers' => array( 'Content-Type' => 'application/json; charset=UTF-8' ),
				'body'    => wp_json_encode( $order ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Downloads a translated file by its document id.
	 *
	 * @param int    $file_id The Supertext file/document id.
	 * @param string $name    The file name (as given in the callback).
	 * @return string|WP_Error The file contents (HTML) on success.
	 */
	public function download_file( int $file_id, string $name ) {
		$response = $this->request( 'GET', 'storage/file/' . $file_id . '/' . rawurlencode( $name ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return (string) wp_remote_retrieve_body( $response );
	}

	/**
	 * Performs an authenticated request against the human/order API.
	 *
	 * @param string $method   HTTP method.
	 * @param string $endpoint Endpoint relative to the environment base.
	 * @param array  $args     Request args (headers, body).
	 * @return array|WP_Error
	 */
	private function request( string $method, string $endpoint, array $args = array() ) {
		if ( '' === $this->email || '' === $this->api_key ) {
			return new WP_Error( 'supertext_human_not_configured', __( 'The Supertext human-translation credentials are not configured.', 'supertext-polylang' ) );
		}

		$url  = $this->base . ltrim( $endpoint, '/' );
		$args = array_merge_recursive(
			array(
				'method'  => $method,
				'timeout' => 60,
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $this->email . ':' . $this->api_key ),
					'Accept'        => 'application/json',
				),
			),
			$args
		);

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			$this->debug_log( sprintf( '%s %s -> transport error: %s', $method, $url, $response->get_error_message() ) );
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$resp_body = (string) wp_remote_retrieve_body( $response );

		if ( $code < 200 || $code >= 300 ) {
			$this->debug_log( sprintf( '%s %s -> HTTP %d | %s', $method, $url, $code, substr( $resp_body, 0, 1000 ) ) );
			$detail = wp_strip_all_tags( trim( $resp_body ) );
			return new WP_Error(
				'supertext_human_http_error',
				sprintf(
					/* translators: 1: HTTP status code, 2: server message. */
					__( 'Supertext order API returned status %1$d. %2$s', 'supertext-polylang' ),
					$code,
					'' !== $detail ? mb_substr( $detail, 0, 200 ) : ''
				)
			);
		}

		return $response;
	}

	/**
	 * Builds a multipart/form-data body.
	 *
	 * @param array<string, string> $fields Text fields.
	 * @param array                 $file   { name, filename, type, content }.
	 * @return array{0: string, 1: string} Boundary and body.
	 */
	private function build_multipart( array $fields, array $file ): array {
		$boundary = 'supertext-' . md5( uniqid( '', true ) );
		$eol      = "\r\n";
		$body     = '';

		foreach ( $fields as $name => $value ) {
			$body .= '--' . $boundary . $eol;
			$body .= 'Content-Disposition: form-data; name="' . $name . '"' . $eol . $eol;
			$body .= $value . $eol;
		}

		$body .= '--' . $boundary . $eol;
		$body .= 'Content-Disposition: form-data; name="' . $file['name'] . '"; filename="' . $file['filename'] . '"' . $eol;
		$body .= 'Content-Type: ' . $file['type'] . $eol . $eol;
		$body .= $file['content'] . $eol;
		$body .= '--' . $boundary . '--' . $eol;

		return array( $boundary, $body );
	}

	/**
	 * Logs a diagnostic line when WP_DEBUG is on.
	 *
	 * @param string $message The message.
	 * @return void
	 */
	private function debug_log( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[supertext-polylang][human] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
