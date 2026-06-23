<?php
/**
 * @package Supertext_Polylang
 */

namespace Supertext\Polylang\Human_Translation;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;

/**
 * REST callback that Supertext calls when a human-translation order is done.
 *
 * The request body looks like the order object(s); we only need the order `Id`.
 * For now this is a placeholder that authenticates the request and extracts the
 * order id(s) — fetching and writing back the translated files is the next step.
 *
 * The endpoint is public (Supertext's servers call it), so it is protected by a
 * shared token embedded in the callback URL.
 *
 * @since 0.6.0
 */
class Callback {
	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	const NS = 'supertext-polylang/v1';

	/**
	 * REST route.
	 *
	 * @var string
	 */
	const ROUTE = '/order-callback';

	/**
	 * Option storing the shared callback token.
	 *
	 * @var string
	 */
	const TOKEN_OPTION = 'supertext_polylang_callback_token';

	/**
	 * Registers the REST route.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( self::class, 'register_route' ) );
	}

	/**
	 * Registers the callback route.
	 *
	 * @return void
	 */
	public static function register_route(): void {
		register_rest_route(
			self::NS,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'handle' ),
				'permission_callback' => array( self::class, 'verify_token' ),
			)
		);
	}

	/**
	 * Returns the full callback URL (including the security token) to send with orders.
	 *
	 * @return string
	 */
	public static function url(): string {
		return add_query_arg( 'token', self::token(), rest_url( self::NS . self::ROUTE ) );
	}

	/**
	 * Returns the shared token, creating it on first use.
	 *
	 * @return string
	 */
	public static function token(): string {
		$token = get_option( self::TOKEN_OPTION );
		if ( ! is_string( $token ) || '' === $token ) {
			$token = wp_generate_password( 32, false );
			update_option( self::TOKEN_OPTION, $token, false );
		}
		return $token;
	}

	/**
	 * Verifies the request token.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public static function verify_token( WP_REST_Request $request ): bool {
		$provided = (string) $request->get_param( 'token' );
		return '' !== $provided && hash_equals( self::token(), $provided );
	}

	/**
	 * Handles the callback: extracts the order id(s) from the body.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle( WP_REST_Request $request ): WP_REST_Response {
		$body      = $request->get_json_params();
		$order_ids = self::extract_order_ids( $body );

		self::debug_log( 'callback received, order ids: ' . wp_json_encode( $order_ids ) );

		if ( empty( $order_ids ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => 'No order id found in request body.' ), 400 );
		}

		/**
		 * Fires when Supertext reports a completed human-translation order.
		 *
		 * Next step will hook this to fetch the translated files for each order id
		 * and write them back into the corresponding posts.
		 *
		 * @since 0.6.0
		 *
		 * @param int[] $order_ids The completed order id(s).
		 * @param mixed $body      The raw decoded request body.
		 */
		do_action( 'supertext_polylang_order_completed', $order_ids, $body );

		return new WP_REST_Response( array( 'ok' => true, 'received' => $order_ids ), 200 );
	}

	/**
	 * Extracts order id(s) from the callback body, which may be a single order
	 * object or an array of them.
	 *
	 * @param mixed $body Decoded JSON body.
	 * @return int[]
	 */
	private static function extract_order_ids( $body ): array {
		$ids = array();

		if ( ! is_array( $body ) ) {
			return $ids;
		}

		if ( isset( $body['Id'] ) ) {
			$ids[] = (int) $body['Id'];
			return $ids;
		}

		foreach ( $body as $entry ) {
			if ( is_array( $entry ) && isset( $entry['Id'] ) ) {
				$ids[] = (int) $entry['Id'];
			}
		}

		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Logs a diagnostic line when WP_DEBUG is on.
	 *
	 * @param string $message The message.
	 * @return void
	 */
	private static function debug_log( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[supertext-polylang][callback] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
