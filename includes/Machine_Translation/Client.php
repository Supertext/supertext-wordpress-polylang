<?php
/**
 * @package Supertext_Polylang
 */

namespace Supertext\Polylang\Machine_Translation;

defined( 'ABSPATH' ) || exit;

use DOMDocument;
use DOMXPath;
use Translations;
use PLL_Language;
use WP_Error;
use WP_Syntex\Polylang_Pro\Modules\Machine_Translation\Clients\Client_Interface;

/**
 * Machine translation client: Supertext (AI file translation).
 *
 * Polylang's MT contract is synchronous (`translate()` must return the filled
 * Translations object), but Supertext's file endpoint is asynchronous. We bridge
 * the two by submitting one HTML file per entity, polling until it is `done`, and
 * mapping the translated HTML back — all within a single (blocking) call.
 *
 * File translation (not the text endpoint) is used deliberately: it accepts up to
 * 1,000,000 characters in a single request, so a whole post goes in one call
 * instead of being chopped into many requests that would hit the text endpoint's
 * 10k-char / 5-req-per-second limits.
 *
 * @see https://www.supertext.com/de-CH/dokumentation/api
 *
 * @since 0.1.0
 */
class Client implements Client_Interface {
	/**
	 * Default API base URL (with trailing slash).
	 *
	 * @var string
	 */
	const DEFAULT_ROUTE = 'https://api.supertext.com/v1/';

	/**
	 * Selectable AI API environments => base URL.
	 *
	 * @var array<string, string>
	 */
	const ENVIRONMENTS = array(
		'live'    => 'https://api.supertext.com/v1/',
		'staging' => 'https://api.staging.supertext.com/v1/',
		'testing' => 'https://api.testing.supertext.com/v1/',
	);

	/**
	 * The Supertext API key.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Base route (with trailing slash).
	 *
	 * @var string
	 */
	private $route;

	/**
	 * Map of Polylang language slug => Supertext language code.
	 *
	 * @var array<string, string>
	 */
	private $languages;

	/**
	 * Constructor.
	 *
	 * @param array $options {
	 *     The service options.
	 *
	 *     @type string $api_key   The Supertext API key.
	 *     @type string $endpoint  Optional. Base URL of the API. Defaults to Supertext.
	 *     @type array  $languages Optional. Map of Polylang language slug => Supertext code.
	 * }
	 */
	public function __construct( array $options ) {
		$this->api_key   = $options['api_key'] ?? '';
		$this->languages = isset( $options['languages'] ) && is_array( $options['languages'] ) ? $options['languages'] : array();

		$endpoint = ! empty( $options['endpoint'] ) ? $options['endpoint'] : self::DEFAULT_ROUTE;
		/** @var string $endpoint */
		$endpoint    = apply_filters( 'supertext_polylang_endpoint', $endpoint, $options );
		$this->route = trailingslashit( $endpoint );
	}

	/**
	 * Resolves the Supertext language code for a Polylang language.
	 *
	 * Uses the admin's explicit mapping first, falling back to the default
	 * (BCP-47 / `w3c`) suggestion.
	 *
	 * @param PLL_Language $language The language.
	 * @return string Supertext language code, empty if none can be determined.
	 */
	private function resolve_code( PLL_Language $language ): string {
		if ( ! empty( $this->languages[ $language->slug ] ) ) {
			return (string) $this->languages[ $language->slug ];
		}

		return Service::get_default_code( $language );
	}

	/**
	 * Translates a Translations object in place and returns it.
	 *
	 * @param Translations      $translations    Translations object.
	 * @param PLL_Language      $target_language Target language.
	 * @param PLL_Language|null $source_language Source language, null for auto-detection.
	 * @return Translations|WP_Error
	 */
	public function translate( Translations $translations, PLL_Language $target_language, $source_language = null ) {
		// Collect the source strings in a stable order.
		$entries  = array_values( $translations->entries );
		$sources  = array();
		foreach ( $entries as $i => $entry ) {
			$sources[ $i ] = (string) $entry->singular;
		}

		if ( empty( $sources ) ) {
			return $translations;
		}

		$target_code = $this->resolve_code( $target_language );
		if ( empty( $target_code ) ) {
			return new WP_Error(
				'supertext_target_language_unavailable',
				sprintf(
					/* translators: %1$s is a language name, %2$s is a language locale. */
					__( '%1$s (%2$s) has no Supertext language code. Set one in the Supertext settings language mapping.', 'supertext-polylang' ),
					$target_language->name,
					sprintf( '<code>%s</code>', $target_language->locale )
				),
				'warning'
			);
		}

		// Supertext expects the source language as a primary (2-letter) subtag,
		// e.g. "de" — not a regional code like "de-CH" — otherwise the language pair
		// is rejected (INVALID_LANGUAGE_PAIR). The target keeps its regional code.
		$source_code = $source_language instanceof PLL_Language ? $this->resolve_code( $source_language ) : '';
		if ( '' !== $source_code ) {
			$source_code = (string) strtok( $source_code, '-' );
		}

		// 1. Submit the whole entity as a single HTML file.
		$file_id = $this->submit_file(
			$this->build_html( $sources ),
			$target_code,
			$source_code,
			$this->get_politeness( $target_language )
		);
		if ( is_wp_error( $file_id ) ) {
			return $file_id;
		}

		// 2. Poll until the translation is ready (blocking, bounded).
		$ready = $this->wait_until_done( $file_id );
		if ( is_wp_error( $ready ) ) {
			$this->delete_file( $file_id );
			return $ready;
		}

		// 3. Download the translated HTML.
		$translated_html = $this->download( $file_id );

		// 4. Best-effort cleanup (file auto-expires after 24h anyway).
		$this->delete_file( $file_id );

		if ( is_wp_error( $translated_html ) ) {
			return $translated_html;
		}

		// 5. Map the translated strings back onto the entries, in order.
		$translated = $this->parse_html( $translated_html );

		if ( count( $translated ) !== count( $sources ) ) {
			return new WP_Error( 'supertext_incomplete_response', __( 'The Supertext translation is incomplete.', 'supertext-polylang' ) );
		}

		foreach ( $entries as $i => $entry ) {
			$entry->translations = array( $translated[ $i ] ?? '' );
		}

		return $translations;
	}

	/**
	 * Translates a set of strings via the AI file endpoint, preserving the caller's
	 * keys.
	 *
	 * A reusable entry point for integrations (e.g. Gravity Forms) that live outside
	 * Polylang's post/term translation pipeline. Returns the translations keyed the
	 * same as `$sources`.
	 *
	 * @param array<int|string, string> $sources         Strings to translate.
	 * @param PLL_Language               $target_language Target language.
	 * @param PLL_Language|null          $source_language Source language (null = auto-detect).
	 * @return array<int|string, string>|WP_Error Translations keyed like `$sources`.
	 */
	public function translate_strings( array $sources, PLL_Language $target_language, $source_language = null ) {
		$sources = array_map( 'strval', $sources );
		if ( empty( $sources ) ) {
			return array();
		}

		$target_code = $this->resolve_code( $target_language );
		if ( '' === $target_code ) {
			return new WP_Error(
				'supertext_target_language_unavailable',
				sprintf(
					/* translators: %1$s is a language name, %2$s is a language locale. */
					__( '%1$s (%2$s) has no Supertext language code. Set one in the Supertext settings language mapping.', 'supertext-polylang' ),
					$target_language->name,
					$target_language->locale
				)
			);
		}

		$source_code = $source_language instanceof PLL_Language ? $this->resolve_code( $source_language ) : '';
		if ( '' !== $source_code ) {
			$source_code = (string) strtok( $source_code, '-' );
		}

		// Send an index-ordered document, but map the result back onto caller keys.
		$keys   = array_keys( $sources );
		$values = array_values( $sources );

		$file_id = $this->submit_file( $this->build_html( $values ), $target_code, $source_code, $this->get_politeness( $target_language ) );
		if ( is_wp_error( $file_id ) ) {
			return $file_id;
		}

		$ready = $this->wait_until_done( $file_id );
		if ( is_wp_error( $ready ) ) {
			$this->delete_file( $file_id );
			return $ready;
		}

		$html = $this->download( $file_id );
		$this->delete_file( $file_id );
		if ( is_wp_error( $html ) ) {
			return $html;
		}

		$translated = $this->parse_html( $html );
		if ( count( $translated ) !== count( $values ) ) {
			return new WP_Error( 'supertext_incomplete_response', __( 'The Supertext translation is incomplete.', 'supertext-polylang' ) );
		}

		$out = array();
		foreach ( $keys as $i => $key ) {
			$out[ $key ] = (string) ( $translated[ $i ] ?? '' );
		}

		return $out;
	}

	/**
	 * Submits the HTML document to the AI file-translation endpoint.
	 *
	 * @param string $html        The HTML document to translate.
	 * @param string $target_lang BCP-47 target language code.
	 * @param string $source_lang BCP-47 source language code, empty for auto-detection.
	 * @param string $politeness  `default`, `more`, or `less`.
	 * @return string|WP_Error The file id on success.
	 */
	private function submit_file( string $html, string $target_lang, string $source_lang, string $politeness ) {
		$fields = array( 'target_lang' => $target_lang );
		if ( '' !== $source_lang ) {
			$fields['source_lang'] = $source_lang;
		}
		if ( 'default' !== $politeness ) {
			$fields['politeness'] = $politeness;
		}

		/** @var array<string, string> $fields */
		$fields = apply_filters( 'supertext_polylang_file_fields', $fields, $target_lang, $source_lang );

		list( $boundary, $body ) = $this->build_multipart(
			$fields,
			array(
				'name'     => 'file',
				'filename' => 'content.html',
				// Supertext matches the part's Content-Type against an allow-list verbatim,
				// so it must be exactly "text/html" — a "; charset=..." suffix triggers
				// 415 FILETYPE_NOT_ALLOWED. (The document itself declares UTF-8 via <meta>.)
				'type'     => 'text/html',
				'content'  => $html,
			)
		);

		$response = $this->http(
			'POST',
			'translate/ai/file',
			array(
				'headers' => array( 'Content-Type' => 'multipart/form-data; boundary=' . $boundary ),
				'body'    => $body,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data['file_id'] ) ) {
			return new WP_Error( 'supertext_no_file_id', __( 'Supertext did not return a file id.', 'supertext-polylang' ) );
		}

		return (string) $data['file_id'];
	}

	/**
	 * Polls the file-translation status until it is done, errors, or times out.
	 *
	 * Status values: `translating` (keep polling), `done`, `error`,
	 * `limit_exceeded`, `deleted`.
	 *
	 * @param string $file_id The file id.
	 * @return true|WP_Error
	 */
	private function wait_until_done( string $file_id ) {
		/** @var int $interval */
		$interval = max( 1, (int) apply_filters( 'supertext_polylang_poll_interval', 2 ) );
		/** @var int $timeout */
		$timeout = max( $interval, (int) apply_filters( 'supertext_polylang_poll_timeout', 180 ) );

		// Best-effort: give PHP enough time to finish the blocking poll.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( $timeout + 30 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$deadline = time() + $timeout;

		do {
			$response = $this->http( 'GET', "translate/ai/file/{$file_id}/status" );
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$data   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			$status = is_array( $data ) ? ( $data['status'] ?? '' ) : '';

			switch ( $status ) {
				case 'done':
					return true;
				case 'error':
					return new WP_Error( 'supertext_translation_error', __( 'Supertext failed to translate the document.', 'supertext-polylang' ) );
				case 'limit_exceeded':
					return new WP_Error( 'supertext_quota_exceeded', __( 'Your Supertext translation limit is exceeded. Please upgrade your subscription.', 'supertext-polylang' ) );
				case 'deleted':
					return new WP_Error( 'supertext_file_deleted', __( 'The Supertext translation file was deleted before it could be downloaded.', 'supertext-polylang' ) );
			}

			// `translating` (or an unknown transient status): wait and retry.
			if ( time() + $interval < $deadline ) {
				sleep( $interval );
			} else {
				break;
			}
		} while ( time() < $deadline );

		return new WP_Error( 'supertext_timeout', __( 'Timed out waiting for the Supertext translation to finish.', 'supertext-polylang' ) );
	}

	/**
	 * Downloads the translated document.
	 *
	 * @param string $file_id The file id.
	 * @return string|WP_Error The translated HTML on success.
	 */
	private function download( string $file_id ) {
		$response = $this->http( 'GET', "translate/ai/file/{$file_id}/translation" );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = (string) wp_remote_retrieve_body( $response );
		if ( '' === $body ) {
			return new WP_Error( 'supertext_empty_download', __( 'The translated document was empty.', 'supertext-polylang' ) );
		}

		return $body;
	}

	/**
	 * Deletes a translation file (best effort; ignores failures).
	 *
	 * @param string $file_id The file id.
	 * @return void
	 */
	private function delete_file( string $file_id ): void {
		$this->http( 'DELETE', "translate/ai/file/{$file_id}" );
	}

	/**
	 * Wraps each source string in an identifiable block so the translated HTML can
	 * be split back apart. Supertext preserves markup and attributes, translating
	 * only the text nodes.
	 *
	 * @param string[] $sources Source strings keyed by sequential index.
	 * @return string
	 */
	private function build_html( array $sources ): string {
		$html = "<!DOCTYPE html>\n<html><head><meta charset=\"utf-8\"></head><body>\n";
		foreach ( $sources as $i => $source ) {
			$html .= '<div data-pll-id="' . (int) $i . '">' . $source . "</div>\n";
		}
		$html .= '</body></html>';

		return $html;
	}

	/**
	 * Splits the translated HTML back into per-entry strings keyed by index.
	 *
	 * @param string $html Translated HTML document.
	 * @return array<int, string>
	 */
	private function parse_html( string $html ): array {
		$dom = new DOMDocument();

		$previous = libxml_use_internal_errors( true );
		$dom->loadHTML(
			'<?xml encoding="utf-8" ?>' . $html,
			LIBXML_NOERROR | LIBXML_NOWARNING
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$xpath = new DOMXPath( $dom );
		$nodes = $xpath->query( '//div[@data-pll-id]' );

		$out = array();
		if ( false === $nodes ) {
			return $out;
		}

		foreach ( $nodes as $node ) {
			if ( ! $node instanceof \DOMElement ) {
				continue;
			}
			$index = (int) $node->getAttribute( 'data-pll-id' );

			$inner = '';
			foreach ( $node->childNodes as $child ) {
				$inner .= (string) $dom->saveHTML( $child );
			}

			$out[ $index ] = trim( $inner );
		}

		return $out;
	}

	/**
	 * Derives the politeness setting from the target locale's formality suffix.
	 *
	 * @param PLL_Language $language Target language.
	 * @return string `default`, `more`, or `less`.
	 */
	private function get_politeness( PLL_Language $language ): string {
		if ( str_ends_with( $language->locale, '_formal' ) ) {
			return 'more';
		}
		if ( str_ends_with( $language->locale, '_informal' ) ) {
			return 'less';
		}

		return 'default';
	}

	/**
	 * Builds a multipart/form-data request body.
	 *
	 * @param array<string, string> $fields Simple form fields.
	 * @param array                 $file   {
	 *     The file part.
	 *
	 *     @type string $name     Field name.
	 *     @type string $filename File name.
	 *     @type string $type     MIME type.
	 *     @type string $content  Raw file content.
	 * }
	 * @return array{0: string, 1: string} The boundary and the body.
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
	 * Performs an authenticated HTTP request against the Supertext API.
	 *
	 * @param string $method   HTTP method.
	 * @param string $endpoint Endpoint relative to the base route.
	 * @param array  $args     Request args (headers, body).
	 * @return array|WP_Error
	 */
	private function http( string $method, string $endpoint, array $args = array() ) {
		if ( empty( $this->api_key ) ) {
			return $this->check_status_code( 403 );
		}

		$args = array_merge_recursive(
			array(
				'headers' => $this->get_auth_headers(),
				'method'  => $method,
				'timeout' => 30,
			),
			$args
		);

		$url      = $this->route . ltrim( $endpoint, '/' );
		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			$this->debug_log( sprintf( '%s %s -> transport error: %s', $method, $url, $response->get_error_message() ) );
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );

		$error = $this->check_status_code( $code, $body );
		if ( $error->has_errors() ) {
			$this->debug_log( sprintf( '%s %s -> HTTP %d | %s', $method, $url, $code, substr( $body, 0, 1000 ) ) );
			return $error;
		}

		return $response;
	}

	/**
	 * Returns the authentication headers.
	 *
	 * @return array<string, string>
	 */
	private function get_auth_headers(): array {
		$headers = array(
			'Authorization' => 'Supertext-Auth-Key ' . $this->api_key,
			'Accept'        => 'application/json',
		);

		/** @var array<string, string> $headers */
		return apply_filters( 'supertext_polylang_auth_headers', $headers, $this->api_key );
	}

	/**
	 * Maps an HTTP status code to a WP_Error (empty WP_Error for 2xx).
	 *
	 * @param int    $code The HTTP response code.
	 * @param string $body The response body.
	 * @return WP_Error
	 */
	private function check_status_code( int $code, string $body = '' ): WP_Error {
		if ( $code >= 200 && $code < 300 ) {
			return new WP_Error();
		}

		switch ( $code ) {
			case 401:
			case 403:
				$errcode = 'supertext_authentication_failure';
				$message = __( 'Authentication failure. Please check your Supertext API key.', 'supertext-polylang' );
				break;
			case 404:
				$errcode = 'supertext_not_found';
				$message = __( 'The requested Supertext resource was not found.', 'supertext-polylang' );
				break;
			case 413:
				$errcode = 'supertext_payload_too_large';
				$message = __( 'The document is too large for Supertext to translate.', 'supertext-polylang' );
				break;
			case 429:
				$errcode = 'supertext_too_many_requests';
				$message = __( 'Too many requests to Supertext. Please try again shortly.', 'supertext-polylang' );
				break;
			case 500:
			case 502:
			case 503:
				$errcode = 'supertext_service_unavailable';
				$message = __( 'Supertext service unavailable.', 'supertext-polylang' );
				break;
			default:
				$errcode = 'supertext_unexpected_status_code';
				/* translators: %d is an HTTP status code. */
				$message = sprintf( __( 'Supertext sent an unexpected status code %d.', 'supertext-polylang' ), $code );
		}

		// Surface the server's own message (helps diagnose 404s / bad requests).
		$detail = wp_strip_all_tags( trim( $body ) );
		if ( '' !== $detail ) {
			$message .= ' — ' . mb_substr( $detail, 0, 200 );
		}

		return new WP_Error( $errcode, $message );
	}

	/**
	 * Logs a diagnostic line when WP_DEBUG is on.
	 *
	 * @param string $message The message.
	 * @return void
	 */
	private function debug_log( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[supertext-polylang] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/**
	 * Tells whether the API key is valid by calling the (cost-free) features endpoint.
	 *
	 * @return WP_Error An empty WP_Error if valid, a filled WP_Error otherwise.
	 */
	public function is_api_key_valid(): WP_Error {
		$response = $this->http( 'GET', 'features' );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return new WP_Error();
	}

	/**
	 * Returns current translation usage.
	 *
	 * The Supertext API exposes no character-usage endpoint, so this reports zeros
	 * (the value is only used by Polylang's optional consumption widget).
	 *
	 * @return array{character_count: int, character_limit: int}
	 */
	public function get_usage() {
		return array(
			'character_count' => 0,
			'character_limit' => 0,
		);
	}
}
