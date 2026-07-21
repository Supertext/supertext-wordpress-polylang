<?php
/**
 * @package Supertext_Polylang
 */

namespace Supertext\Polylang\Integrations\GravityForms;

defined( 'ABSPATH' ) || exit;

use Supertext\Polylang\Admin\Integrations;

/**
 * Injects Gravity Forms translations at render time, for the current Polylang
 * language, reading them from Polylang's own String Translations store.
 *
 * Because forms aren't posts, we can't translate them through Polylang's pipeline.
 * Instead we swap the visible strings on the in-memory form via `gform_pre_render`
 * (display) and `gform_pre_validation` (so validation shows the same labels), only
 * on the front end and only when the current language differs from the form's
 * source (default) language. Each string is looked up with `pll__()`, so whatever
 * lives in Polylang — a Supertext AI/human translation or a manual edit — is what
 * renders. See {@see Strings} for how translations get into that store.
 *
 * @since 0.3.0
 */
class Integration {
	/**
	 * Registers the render hooks. Harmless when Gravity Forms is inactive (the
	 * filters simply never fire).
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'gform_pre_render', array( self::class, 'translate_form' ) );
		add_filter( 'gform_pre_validation', array( self::class, 'translate_form' ) );
	}

	/**
	 * Swaps the form's strings for the current language's translations.
	 *
	 * @param mixed $form Gravity Forms form array.
	 * @return mixed
	 */
	public static function translate_form( $form ) {
		if ( ! is_array( $form ) || empty( $form['id'] ) ) {
			return $form;
		}
		if ( is_admin() || ! Integrations::enabled( 'gravityforms' ) ) {
			return $form;
		}
		if ( ! function_exists( 'pll_current_language' ) || ! function_exists( 'pll_default_language' ) || ! function_exists( 'pll__' ) ) {
			return $form;
		}

		$current = (string) pll_current_language( 'slug' );
		$default = (string) pll_default_language( 'slug' );

		// The form is authored in the default language; on that language `pll__()`
		// just returns the source, so skip the walk entirely.
		if ( '' === $current || $current === $default ) {
			return $form;
		}

		// Each source string is replaced by its Polylang translation for the current
		// language; untranslated strings fall back to the source.
		return Fields::apply_callback(
			$form,
			static function ( $source ) {
				$translated = pll__( $source );
				return ( is_string( $translated ) && '' !== $translated ) ? $translated : $source;
			}
		);
	}
}
