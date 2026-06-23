<?php
/**
 * @package Supertext_Polylang
 */

namespace Supertext\Polylang\Human_Translation;

defined( 'ABSPATH' ) || exit;

/**
 * Registry of human-translation orders placed through the plugin.
 *
 * Stored in the `supertext_polylang_orders` option, keyed by Supertext order id,
 * so the Orders admin page can list current and past orders, refresh their status,
 * and cancel ongoing ones.
 *
 * @since 0.8.0
 */
class Orders {
	/**
	 * Option name.
	 *
	 * @var string
	 */
	const OPTION = 'supertext_polylang_orders';

	/**
	 * Returns all orders, newest first.
	 *
	 * @return array<int, array>
	 */
	public static function all(): array {
		$orders = get_option( self::OPTION, array() );
		if ( ! is_array( $orders ) ) {
			return array();
		}

		uasort(
			$orders,
			static function ( $a, $b ) {
				return strcmp( (string) ( $b['created_at'] ?? '' ), (string) ( $a['created_at'] ?? '' ) );
			}
		);

		return $orders;
	}

	/**
	 * Returns one order, or null.
	 *
	 * @param int $id Order id.
	 * @return array|null
	 */
	public static function get( int $id ) {
		$orders = get_option( self::OPTION, array() );
		return is_array( $orders ) && isset( $orders[ $id ] ) ? $orders[ $id ] : null;
	}

	/**
	 * Records (or replaces) an order.
	 *
	 * @param array $order Order data (must contain `order_id`).
	 * @return void
	 */
	public static function record( array $order ): void {
		if ( empty( $order['order_id'] ) ) {
			return;
		}

		$orders = get_option( self::OPTION, array() );
		if ( ! is_array( $orders ) ) {
			$orders = array();
		}

		$id            = (int) $order['order_id'];
		$orders[ $id ] = array_merge(
			array(
				'order_id'     => $id,
				'post_id'      => 0,
				'lang'         => '',
				'target'       => '',
				'type_id'      => 0,
				'delivery_id'  => 0,
				'order_name'   => '',
				'status'       => 'New',
				'created_at'   => gmdate( 'c' ),
				'completed_at' => null,
			),
			$order
		);

		update_option( self::OPTION, $orders, false );
	}

	/**
	 * Updates fields of an existing order.
	 *
	 * @param int   $id    Order id.
	 * @param array $patch Fields to merge.
	 * @return void
	 */
	public static function update( int $id, array $patch ): void {
		$orders = get_option( self::OPTION, array() );
		if ( ! is_array( $orders ) || ! isset( $orders[ $id ] ) ) {
			return;
		}

		$orders[ $id ] = array_merge( $orders[ $id ], $patch );
		update_option( self::OPTION, $orders, false );
	}
}
