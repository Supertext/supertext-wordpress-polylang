<?php
/**
 * @package Supertext_Polylang
 */

namespace Supertext\Polylang\Integrations\GravityForms;

defined( 'ABSPATH' ) || exit;

/**
 * Deprecated. Human translation of form strings now goes through the shared
 * {@see \Supertext\Polylang\Human_Translation\Human_Strings} core, driven from the
 * checkbox + bottom-action-bar UI in {@see Editor} (and the general
 * {@see \Supertext\Polylang\Admin\String_Translations_Page}). This class is kept as
 * an inert stub for back-compat and is no longer initialized.
 *
 * @since      0.8.0
 * @deprecated 0.9.0 Use Human_Strings.
 */
class Human {
	/**
	 * No-op. Retained so any stray reference doesn't fatal.
	 *
	 * @return void
	 */
	public static function init(): void {}
}
