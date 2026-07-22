<?php
/**
 * @package Supertext_Polylang
 */

namespace Supertext\Polylang\Human_Translation;

defined( 'ABSPATH' ) || exit;

use DOMDocument;
use DOMXPath;
use WP_Error;
use Supertext\Polylang\Admin\Bulk_Actions;
use Supertext\Polylang\Admin\Settings;
use Supertext\Polylang\Polylang\String_Store;

/**
 * Places human-translation orders for a set of Polylang **source strings** and
 * writes the completed translations back into Polylang's store.
 *
 * This is the shared core behind both the Gravity Forms per-form order (type `gf`)
 * and the general String Translation page (type `str`): a batch of source strings
 * is packaged as the same `<div data-pll-id="base64(source)">source</div>` HTML the
 * post path uses, uploaded, and ordered with a typed ReferenceData so the callback
 * can route it back. Because everything is keyed by the source string value, the
 * writeback simply saves each returned translation via {@see String_Store}.
 *
 * @since 0.9.0
 */
class Human_Strings {
	/** AJAX action for the live string price quote. */
	const QUOTE_ACTION = 'supertext_polylang_str_quote';

	/**
	 * Registers the generic (`str`) writeback and the price-quote endpoint.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'supertext_polylang_order_completed', array( self::class, 'writeback' ), 10, 5 );
		add_action( 'wp_ajax_' . self::QUOTE_ACTION, array( self::class, 'handle_quote' ) );
	}

	/**
	 * AJAX: returns a live price quote (word count + per-delivery prices) for the
	 * checked source strings + chosen service, so the String Translation table can
	 * offer the same delivery/price picker as the Posts/Pages Human workflow.
	 *
	 * @return void
	 */
	public static function handle_quote(): void {
		check_ajax_referer( self::QUOTE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'supertext-polylang' ) ), 403 );
		}
		if ( ! Settings::is_configured() ) {
			wp_send_json_error( array( 'message' => __( 'Supertext human-translation credentials are not configured.', 'supertext-polylang' ) ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce checked above.
		$target_lang = sanitize_key( wp_unslash( $_POST['target_lang'] ?? '' ) );
		$service_id  = (int) ( $_POST['service_id'] ?? 0 );
		$sources     = ( isset( $_POST['sources'] ) && is_array( $_POST['sources'] ) ) ? wp_unslash( $_POST['sources'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- source strings are sent verbatim to the quote API.
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$sources = array_values( array_filter( array_map( 'strval', (array) $sources ), static fn( $s ) => '' !== trim( $s ) ) );

		if ( '' === $target_lang || ! isset( Bulk_Actions::HUMAN_SERVICES[ $service_id ] ) || empty( $sources ) ) {
			wp_send_json_error( array( 'message' => __( 'Select a translation type, a target language, and at least one row.', 'supertext-polylang' ) ) );
		}

		$quote = self::quote( $sources, $target_lang, $service_id );
		if ( is_wp_error( $quote ) ) {
			wp_send_json_error( array( 'message' => $quote->get_error_message() ) );
		}

		wp_send_json_success( $quote );
	}

	/**
	 * Uploads the given source strings and requests a price quote for one service +
	 * target language, returning the aggregated word count and per-delivery prices.
	 *
	 * @param string[] $sources    Source strings.
	 * @param string   $lang       Target language slug.
	 * @param int      $service_id OrderTypeConfigurationId.
	 * @return array{currency:string, currencySymbol:string, wordCount:int, deliveries:array[]}|WP_Error
	 */
	public static function quote( array $sources, string $lang, int $service_id ) {
		if ( ! function_exists( 'PLL' ) || ! isset( PLL()->model ) ) {
			return new WP_Error( 'supertext_no_pll', __( 'Polylang is not available.', 'supertext-polylang' ) );
		}

		$model  = PLL()->model;
		$target = $model->get_language( $lang );
		$source = function_exists( 'pll_default_language' ) ? $model->get_language( (string) pll_default_language( 'slug' ) ) : null;
		if ( ! $target || ! $source ) {
			return new WP_Error( 'supertext_bad_lang', __( 'Unknown source or target language.', 'supertext-polylang' ) );
		}

		$sources = array_values( array_unique( array_filter( array_map( 'strval', $sources ), static fn( $s ) => '' !== trim( $s ) ) ) );
		if ( empty( $sources ) ) {
			return new WP_Error( 'supertext_empty', __( 'No strings selected to translate.', 'supertext-polylang' ) );
		}

		$client      = new Client();
		$document_id = $client->upload_file( self::build_html( $sources ), 'strings-quote.html' );
		if ( is_wp_error( $document_id ) ) {
			return $document_id;
		}

		$currency_req = (string) apply_filters( 'supertext_polylang_quote_currency', '' );

		$body = array(
			'OrderTypeConfigurationId' => $service_id,
			'OrderTypeId'              => Bulk_Actions::order_type_id( $service_id ),
			'SourceLang'               => (string) $source->w3c,
			'TargetLanguages'          => array( (string) $target->w3c ),
			'Files'                    => array( array( 'Id' => (int) $document_id, 'Comment' => 'Polylang strings' ) ),
		);
		if ( '' !== $currency_req ) {
			$body['Currency'] = $currency_req;
		}

		$quote = $client->get_quote( $body );
		if ( is_wp_error( $quote ) ) {
			return $quote;
		}

		$symbol     = (string) ( $quote['CurrencySymbol'] ?? '' );
		$currency   = (string) ( $quote['Currency'] ?? '' );
		$option     = ( isset( $quote['Options'][0] ) && is_array( $quote['Options'][0] ) ) ? $quote['Options'][0] : array();
		$deliveries = array();
		foreach ( (array) ( $option['DeliveryOptions'] ?? array() ) as $do ) {
			$did = (int) ( $do['DeliveryId'] ?? 0 );
			if ( $did <= 0 ) {
				continue;
			}
			$deliveries[ $did ] = array(
				'delivery_id' => $did,
				'name'        => (string) ( $do['Name'] ?? '' ),
				'price'       => (float) ( $do['Price'] ?? 0 ),
				'date'        => (string) ( $do['DeliveryDate'] ?? '' ),
				'is_default'  => (bool) ( $do['IsDefaultDeliveryOption'] ?? false ),
			);
		}
		ksort( $deliveries );

		return array(
			'currency'       => $currency,
			'currencySymbol' => '' !== $symbol ? $symbol : $currency,
			'wordCount'      => (int) ( $quote['WordCount'] ?? 0 ),
			'deliveries'     => array_values( $deliveries ),
		);
	}

	/**
	 * Places a human order for the given source strings.
	 *
	 * @param string[] $sources    Source strings to translate.
	 * @param string   $lang       Target language slug.
	 * @param int      $service_id OrderTypeConfigurationId.
	 * @param string   $express    DeliveryId.
	 * @param string   $order_name Human-readable order name.
	 * @param string   $type       ReferenceData entity type (e.g. `gf`, `str`).
	 * @param int      $entity_id  ReferenceData entity id (e.g. form id, or 0).
	 * @return int[]|WP_Error Order id(s) on success.
	 */
	public static function place_order( array $sources, string $lang, int $service_id, string $express, string $order_name, string $type, int $entity_id ) {
		if ( ! Settings::is_configured() ) {
			return new WP_Error( 'supertext_not_configured', __( 'Supertext human-translation credentials are not configured.', 'supertext-polylang' ) );
		}
		if ( ! function_exists( 'PLL' ) || ! isset( PLL()->model ) ) {
			return new WP_Error( 'supertext_no_pll', __( 'Polylang is not available.', 'supertext-polylang' ) );
		}

		$model  = PLL()->model;
		$target = $model->get_language( $lang );
		$source = function_exists( 'pll_default_language' ) ? $model->get_language( (string) pll_default_language( 'slug' ) ) : null;
		if ( ! $target || ! $source ) {
			return new WP_Error( 'supertext_bad_lang', __( 'Unknown source or target language.', 'supertext-polylang' ) );
		}

		$sources = array_values( array_unique( array_filter( array_map( 'strval', $sources ), static fn( $s ) => '' !== trim( $s ) ) ) );
		if ( empty( $sources ) ) {
			return new WP_Error( 'supertext_empty', __( 'No strings selected to translate.', 'supertext-polylang' ) );
		}

		$html        = self::build_html( $sources );
		$client      = new Client();
		$filename    = sanitize_file_name( '' !== $order_name ? $order_name : 'strings' ) . '.html';
		$document_id = $client->upload_file( $html, $filename );
		if ( is_wp_error( $document_id ) ) {
			return $document_id;
		}

		$order = array(
			'DeliveryId'               => (int) $express,
			'OrderName'                => $order_name,
			'OrderTypeConfigurationId' => $service_id,
			'ContentType'              => 'text/html',
			'Referrer'                 => 'Supertext for Polylang',
			'SystemName'               => 'WordPress',
			'SystemVersion'            => get_bloginfo( 'version' ),
			'ComponentName'            => 'supertext-polylang',
			'ComponentVersion'         => SUPERTEXT_POLYLANG_VERSION,
			'SourceLang'               => (string) strtok( (string) $source->w3c, '-' ),
			'TargetLanguages'          => array( (string) $target->w3c ),
			'ReferenceData'            => Callback::reference_data_for( $type, $entity_id, $lang ),
			'CallbackUrl'              => Callback::url(),
			'Files'                    => array(
				array(
					'Comment' => 'Polylang strings',
					'Id'      => (int) $document_id,
				),
			),
		);

		$result = $client->create_order( $order );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$order_ids = array();
		if ( is_array( $result ) ) {
			foreach ( $result as $entry ) {
				if ( is_array( $entry ) && isset( $entry['Id'] ) ) {
					$order_ids[] = (int) $entry['Id'];
				}
			}
		}

		foreach ( $order_ids as $oid ) {
			Orders::record(
				array(
					'order_id'    => $oid,
					'post_id'     => 0,
					'kind'        => $type,
					'entity_id'   => $entity_id,
					'lang'        => $lang,
					'target'      => (string) $target->w3c,
					'type_id'     => $service_id,
					'delivery_id' => (int) $express,
					'order_name'  => $order_name,
					'status'      => 'New',
				)
			);
		}

		return $order_ids;
	}

	/**
	 * Generic (`str`) completion writeback: saves the returned translations.
	 *
	 * @param int[]  $order_ids Completed order id(s).
	 * @param int    $entity_id Entity id (unused for `str`).
	 * @param string $lang      Target language slug.
	 * @param mixed  $body      Raw decoded callback body.
	 * @param string $type      Entity type.
	 * @return void
	 */
	public static function writeback( $order_ids, $entity_id, $lang, $body, $type = 'post' ): void {
		if ( 'str' !== $type ) {
			return;
		}

		$pairs = self::pairs_from_body( $body );
		if ( ! empty( $pairs ) ) {
			String_Store::save_translations( (string) $lang, $pairs );
		}

		$status = is_array( $body ) && ! empty( $body['Status'] ) ? (string) $body['Status'] : 'Completed';
		foreach ( (array) $order_ids as $oid ) {
			Orders::update( (int) $oid, array( 'status' => $status, 'completed_at' => gmdate( 'c' ) ) );
		}
	}

	/**
	 * Downloads a completed order's Final file(s) and parses them into pairs.
	 *
	 * @param mixed $body Raw decoded callback body.
	 * @return array<string, string> source => translation.
	 */
	public static function pairs_from_body( $body ): array {
		$files = self::final_files( $body );
		if ( empty( $files ) ) {
			return array();
		}

		$client = new Client();
		$pairs  = array();
		foreach ( $files as $file ) {
			$html = $client->download_file( (int) $file['Id'], (string) $file['Name'] );
			if ( is_wp_error( $html ) ) {
				continue;
			}
			$pairs += self::parse_html( $html );
		}
		return $pairs;
	}

	/**
	 * Builds the upload HTML: each source string wrapped by its own base64 id.
	 *
	 * @param string[] $sources Source strings.
	 * @return string
	 */
	private static function build_html( array $sources ): string {
		$entries = array();
		foreach ( $sources as $source ) {
			$entries[] = array(
				'context'  => (string) $source,
				'singular' => (string) $source,
			);
		}
		return Content::render( $entries );
	}

	/**
	 * Parses translated HTML into a source => translation map.
	 *
	 * @param string $html Translated HTML.
	 * @return array<string, string>
	 */
	private static function parse_html( string $html ): array {
		$out = array();

		$dom      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$xpath = new DOMXPath( $dom );
		$nodes = $xpath->query( '//div[@data-pll-id]' );
		if ( false === $nodes ) {
			return $out;
		}

		foreach ( $nodes as $node ) {
			if ( ! $node instanceof \DOMElement ) {
				continue;
			}
			$source = base64_decode( $node->getAttribute( 'data-pll-id' ), true );
			if ( false === $source || '' === $source ) {
				continue;
			}
			$inner = '';
			foreach ( $node->childNodes as $child ) {
				$inner .= (string) $dom->saveHTML( $child );
			}
			$translation = trim( $inner );
			if ( '' !== $translation ) {
				$out[ $source ] = $translation;
			}
		}

		return $out;
	}

	/**
	 * Returns the Final (translated) files from the callback body.
	 *
	 * @param mixed $body Decoded callback body.
	 * @return array[] List of { Id, Name }.
	 */
	private static function final_files( $body ): array {
		$files = array();
		if ( ! is_array( $body ) || empty( $body['Files'] ) || ! is_array( $body['Files'] ) ) {
			return $files;
		}
		foreach ( $body['Files'] as $file ) {
			if ( ! is_array( $file ) || ! isset( $file['Id'] ) ) {
				continue;
			}
			if ( ( $file['DocumentType'] ?? '' ) !== 'Final' ) {
				continue;
			}
			$files[] = array(
				'Id'   => (int) $file['Id'],
				'Name' => (string) ( $file['Name'] ?? ( $file['Id'] . '.html' ) ),
			);
		}
		return $files;
	}
}
