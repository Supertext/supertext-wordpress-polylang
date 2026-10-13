<?php
/**
 * @package Supertext_Polylang
 */

namespace Supertext\Polylang\Machine_Translation;

defined( 'ABSPATH' ) || exit;

use Supertext\Polylang\Sdk\Client as SdkClient;
use Supertext\Polylang\Sdk\Segment;
use Supertext\Polylang\Sdk\SupertextException;
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
 * The protocol (upload, poll, download, delete, auth header, HTTP 429 retries,
 * language codes, one `data-st-id` element per string) is the Supertext PHP SDK's,
 * bundled in includes/Sdk; HTTP goes through WordPress (Wp_Http_Transport).
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
		$this->api_key   = (string) preg_replace( '/^\s*Supertext-Auth-Key\s+/i', '', trim( (string) ( $options['api_key'] ?? '' ) ) );
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
	 * (BCP-47 / `w3c`) suggestion. Either way the code carries a region
	 * (`de` → `de-DE`, see Language_Codes): this is the target code; the
	 * source is sent as its bare language.
	 *
	 * @param PLL_Language $language The language.
	 * @return string Supertext language code, empty if none can be determined.
	 */
	private function resolve_code( PLL_Language $language ): string {
		$override = ! empty( $this->languages[ $language->slug ] ) ? (string) $this->languages[ $language->slug ] : '';

		return Language_Codes::target( Service::get_default_code( $language ), $override );
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

		// Supertext expects the source language as a bare language, e.g. "de" — not
		// a regional code like "de-CH" — and the target with a region, e.g. "de-CH" or
		// "de-DE", otherwise the language pair is rejected (INVALID_LANGUAGE_PAIR).
		$source_code = $source_language instanceof PLL_Language ? Language_Codes::source( $this->resolve_code( $source_language ) ) : '';

		// One document per entity (split by the SDK only above its size limit).
		$translated = $this->translate_segments( $sources, $target_code, $source_code, $this->get_politeness( $target_language ) );
		if ( is_wp_error( $translated ) ) {
			return $translated;
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

		$source_code = $source_language instanceof PLL_Language ? Language_Codes::source( $this->resolve_code( $source_language ) ) : '';

		return $this->translate_segments( $sources, $target_code, $source_code, $this->get_politeness( $target_language ) );
	}

	/**
	 * Translates keyed strings (HTML) with the Supertext SDK and returns them under
	 * the same keys.
	 *
	 * @param array<int|string, string> $sources     Strings to translate.
	 * @param string                    $target_lang Target code (regional).
	 * @param string                    $source_lang Source code, empty for auto-detection.
	 * @param string                    $politeness  `default`, `more`, or `less`.
	 * @return array<int|string, string>|WP_Error
	 */
	private function translate_segments( array $sources, string $target_lang, string $source_lang, string $politeness ) {
		$segments = array();
		foreach ( $sources as $key => $source ) {
			$segments[ $key ] = Segment::html( (string) $source );
		}

		/** @var int $timeout */
		$timeout = max( 1, (int) apply_filters( 'supertext_polylang_poll_timeout', 180 ) );

		// Best-effort: give PHP enough time to finish the blocking poll.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( $timeout + 60 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		try {
			return $this->sdk( $timeout )->translateTexts( $segments, $target_lang, '' !== $source_lang ? $source_lang : null, $politeness );
		} catch ( SupertextException $e ) {
			return self::to_wp_error( $e );
		}
	}

	/**
	 * The SDK client for one call.
	 *
	 * @param int $timeout Seconds to wait for a translation.
	 * @return SdkClient
	 */
	private function sdk( int $timeout = 180 ): SdkClient {
		/** @var int $interval */
		$interval = max( 1, (int) apply_filters( 'supertext_polylang_poll_interval', 2 ) );

		return new SdkClient(
			$this->api_key,
			baseUrl: $this->route,
			pollInterval: (float) $interval,
			timeout: (float) max( $interval, $timeout ),
			requestTimeout: 30.0,
			userAgent: 'supertext-wordpress-polylang/' . ( defined( 'SUPERTEXT_POLYLANG_VERSION' ) ? SUPERTEXT_POLYLANG_VERSION : 'dev' ),
			transport: new Wp_Http_Transport( $this->api_key ),
		);
	}

	/**
	 * Turns an SDK error into the WP_Error this plugin has always returned
	 * (`supertext_<code>`, a translatable message, the API's detail appended).
	 *
	 * @param SupertextException $e The SDK error.
	 * @return WP_Error
	 */
	public static function to_wp_error( SupertextException $e ): WP_Error {
		switch ( $e->errorCode ) {
			case SupertextException::MISSING_API_KEY:
			case SupertextException::AUTHENTICATION_FAILURE:
				$errcode = 'supertext_authentication_failure';
				$message = __( 'Authentication failure. Please check your Supertext API key.', 'supertext-polylang' );
				break;
			case SupertextException::INVALID_LANGUAGE_PAIR:
				$errcode = 'supertext_invalid_language_pair';
				$message = sprintf(
					/* translators: %1$s is the source language code, %2$s the target language code. */
					__( 'Supertext does not support this language pair (%1$s → %2$s). Check the Supertext language mapping in the settings.', 'supertext-polylang' ),
					'' !== (string) $e->sourceLang ? (string) $e->sourceLang : '?',
					'' !== (string) $e->targetLang ? (string) $e->targetLang : '?'
				);
				break;
			case SupertextException::NOT_FOUND:
				$errcode = 'supertext_not_found';
				$message = __( 'The requested Supertext resource was not found.', 'supertext-polylang' );
				break;
			case SupertextException::PAYLOAD_TOO_LARGE:
				$errcode = 'supertext_payload_too_large';
				$message = __( 'The document is too large for Supertext to translate.', 'supertext-polylang' );
				break;
			case SupertextException::TOO_MANY_REQUESTS:
				$errcode = 'supertext_too_many_requests';
				$message = __( 'Too many requests to Supertext. Please try again shortly.', 'supertext-polylang' );
				break;
			case SupertextException::SERVICE_UNAVAILABLE:
				$errcode = 'supertext_service_unavailable';
				$message = __( 'Supertext service unavailable.', 'supertext-polylang' );
				break;
			case SupertextException::TRANSPORT_ERROR:
				$errcode = 'supertext_transport_error';
				$message = __( 'Could not reach Supertext.', 'supertext-polylang' );
				break;
			case SupertextException::NO_FILE_ID:
				$errcode = 'supertext_no_file_id';
				$message = __( 'Supertext did not return a file id.', 'supertext-polylang' );
				break;
			case SupertextException::TRANSLATION_ERROR:
				$errcode = 'supertext_translation_error';
				$message = __( 'Supertext failed to translate the document.', 'supertext-polylang' );
				break;
			case SupertextException::QUOTA_EXCEEDED:
				$errcode = 'supertext_quota_exceeded';
				$message = __( 'Your Supertext translation limit is exceeded. Please upgrade your subscription.', 'supertext-polylang' );
				break;
			case SupertextException::FILE_DELETED:
				$errcode = 'supertext_file_deleted';
				$message = __( 'The Supertext translation file was deleted before it could be downloaded.', 'supertext-polylang' );
				break;
			case SupertextException::TIMEOUT:
				$errcode = 'supertext_timeout';
				$message = __( 'Timed out waiting for the Supertext translation to finish.', 'supertext-polylang' );
				break;
			case SupertextException::INCOMPLETE_RESPONSE:
				$errcode = 'supertext_incomplete_response';
				$message = __( 'The Supertext translation is incomplete.', 'supertext-polylang' );
				break;
			default:
				$errcode = 'supertext_unexpected_status_code';
				/* translators: %d is an HTTP status code. */
				$message = sprintf( __( 'Supertext sent an unexpected status code %d.', 'supertext-polylang' ), (int) $e->status );
		}

		// Surface the server's own message (helps diagnose 404s / bad requests).
		$detail = (string) $e->detail;
		if ( '' !== $detail ) {
			$message .= ' — ' . $detail;
		}

		return new WP_Error( $errcode, $message );
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
	 * Tells whether the API key is valid by calling the (cost-free) features endpoint.
	 *
	 * @return WP_Error An empty WP_Error if valid, a filled WP_Error otherwise.
	 */
	public function is_api_key_valid(): WP_Error {
		try {
			$this->sdk()->validateApiKey();
		} catch ( SupertextException $e ) {
			return self::to_wp_error( $e );
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
