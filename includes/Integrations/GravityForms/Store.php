<?php
/**
 * @package Supertext_Polylang
 */

namespace Supertext\Polylang\Integrations\GravityForms;

defined( 'ABSPATH' ) || exit;

/**
 * Stores Gravity Forms translations, keyed by form id and language.
 *
 * Gravity Forms gives us no post to attach translations to, so we keep our own
 * store: one option per form holding `{ lang => { path => translation } }`.
 *
 * @since 0.3.0
 */
class Store {
	/**
	 * Option name prefix (one option per form).
	 *
	 * @var string
	 */
	const PREFIX = 'supertext_polylang_gf_';

	/**
	 * Returns the translation map for a form + language (path => translation).
	 *
	 * @param int    $form_id Form id.
	 * @param string $lang    Language slug.
	 * @return array<string, string>
	 */
	public static function get( int $form_id, string $lang ): array {
		$all = get_option( self::PREFIX . $form_id, array() );
		if ( ! is_array( $all ) || ! isset( $all[ $lang ] ) || ! is_array( $all[ $lang ] ) ) {
			return array();
		}
		return $all[ $lang ];
	}

	/**
	 * Saves the translation map for a form + language.
	 *
	 * @param int                   $form_id Form id.
	 * @param string                $lang    Language slug.
	 * @param array<string, string> $map     Translations keyed by path.
	 * @return void
	 */
	public static function save( int $form_id, string $lang, array $map ): void {
		$all = get_option( self::PREFIX . $form_id, array() );
		if ( ! is_array( $all ) ) {
			$all = array();
		}
		$all[ $lang ] = $map;
		update_option( self::PREFIX . $form_id, $all, false );
	}

	/**
	 * Tells whether a form has any stored translation for a language.
	 *
	 * @param int    $form_id Form id.
	 * @param string $lang    Language slug.
	 * @return bool
	 */
	public static function has( int $form_id, string $lang ): bool {
		return ! empty( self::get( $form_id, $lang ) );
	}
}
