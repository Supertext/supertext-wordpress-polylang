<?php
/**
 * @package Supertext_Polylang
 */

namespace Supertext\Polylang\Integrations\VibeBoost;

defined( 'ABSPATH' ) || exit;

use WP_Error;

/**
 * Client for **VibeBoost Screenshots** — a hosted service that renders a web page
 * and returns a screenshot image.
 *
 * Used to attach a visual snapshot of a page to a human-translation order, so the
 * translator sees the content in its real layout. The page is reached through its
 * secret preview URL (see {@see \Supertext\Polylang\Preview\Draft_Preview}), which
 * lets the service capture even an unpublished draft.
 *
 * The public capture endpoint needs no authentication, but heavier use may require
 * a VibeBoost subscription. The service is still in development.
 *
 * @see https://vibeboost.me
 * @since 0.5.0
 */
class Client {
	/**
	 * Default capture endpoint (overridable via the
	 * `supertext_polylang_screenshot_endpoint` filter).
	 *
	 * @var string
	 */
	const ENDPOINT = 'https://vibeboost.me/api/screenshot';

	/**
	 * Captures a screenshot of a URL and returns the raw image bytes.
	 *
	 * @param string               $url  The page URL to capture.
	 * @param array<string, mixed> $opts Optional capture options (format, width,
	 *                                   hideCookies). Merged over the defaults.
	 * @return string|WP_Error The image binary on success.
	 */
	public function capture( string $url, array $opts = array() ) {
		if ( '' === trim( $url ) ) {
			return new WP_Error( 'supertext_screenshot_no_url', __( 'No URL to capture.', 'supertext-polylang' ) );
		}

		$params = array_merge(
			array(
				'format'      => 'png',
				'width'       => 1280,
				'hideCookies' => 'true',
			),
			$opts,
			array( 'url' => $url )
		);

		/** @var string $endpoint */
		$endpoint = (string) apply_filters( 'supertext_polylang_screenshot_endpoint', self::ENDPOINT );

		// Build the query with http_build_query (which URL-encodes values) — NOT
		// add_query_arg, which leaves values raw. The captured `url` itself contains
		// a query string (?p=…&st_preview=token); without encoding, its `&` would
		// split the token off into a separate parameter and it would be lost.
		$separator = ( false === strpos( $endpoint, '?' ) ) ? '?' : '&';
		$request   = $endpoint . $separator . http_build_query( $params );

		$response = wp_remote_get(
			$request,
			array(
				'timeout' => 60,
				'headers' => array( 'Accept' => 'image/png,image/jpeg,image/*' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->debug_log( 'transport error: ' . $response->get_error_message() );
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			$this->debug_log( sprintf( 'HTTP %d for %s', $code, $url ) );
			return new WP_Error(
				'supertext_screenshot_http_error',
				sprintf(
					/* translators: %d is the HTTP status code. */
					__( 'VibeBoost Screenshots returned status %d.', 'supertext-polylang' ),
					$code
				)
			);
		}

		$type = (string) wp_remote_retrieve_header( $response, 'content-type' );
		$body = (string) wp_remote_retrieve_body( $response );

		if ( 0 !== strpos( $type, 'image/' ) || '' === $body ) {
			$this->debug_log( sprintf( 'unexpected content-type "%s" (%d bytes)', $type, strlen( $body ) ) );
			return new WP_Error( 'supertext_screenshot_bad_response', __( 'VibeBoost Screenshots did not return an image.', 'supertext-polylang' ) );
		}

		return $body;
	}

	/**
	 * Logs a diagnostic line when WP_DEBUG is on.
	 *
	 * @param string $message The message.
	 * @return void
	 */
	private function debug_log( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[supertext-polylang][vibeboost] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
