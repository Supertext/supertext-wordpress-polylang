<?php
/**
 * @package Supertext_Polylang
 */

namespace Supertext\Polylang\Integrations\GravityForms;

defined( 'ABSPATH' ) || exit;

use GFAPI;
use Supertext\Polylang\Admin\Integrations;
use Supertext\Polylang\Polylang\String_Store;

/**
 * Bridges Gravity Forms strings into Polylang's native String Translations.
 *
 * Instead of keeping form translations in our own option (the legacy {@see Store}),
 * we register each form's source strings with Polylang (`pll_register_string`) so
 * they appear under Languages → String translations, and we read/write the actual
 * translations through Polylang's own per-language store (`PLL_MO`) — the exact
 * data Polylang's "Save changes" button writes. That way a translation produced by
 * Supertext AI, an edit made in Polylang's grid, and an edit made in our own editor
 * are all the same record: nothing forks.
 *
 * Because `PLL_MO` is keyed by the *source string value* (gettext msgid), two fields
 * with identical text share one translation — this is inherent to Polylang's model.
 *
 * @since 0.6.0
 */
class Strings {
	/**
	 * Registers the hooks that keep Polylang's string list populated.
	 *
	 * @return void
	 */
	public static function init(): void {
		// Populate the list when the user is on a Polylang admin page (see maybe_register_all).
		add_action( 'admin_init', array( self::class, 'maybe_register_all' ) );
		// Keep a form's strings fresh the moment it's edited.
		add_action( 'gform_after_save_form', array( self::class, 'on_form_saved' ), 10, 2 );
	}

	/**
	 * Registers every form's strings, but only while viewing a Polylang admin page.
	 *
	 * Registering means loading and walking all forms, so we gate it to the
	 * `mlang*` screens (Languages, String translations, …) to avoid that cost on
	 * every admin request. Polylang's string table lists whatever is registered by
	 * the time it renders, so this is the moment they need to exist.
	 *
	 * @return void
	 */
	public static function maybe_register_all(): void {
		if ( ! Integrations::enabled( 'gravityforms' ) ) {
			return;
		}
		// Read-only page detection; no state change, so no nonce needed.
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 0 !== strpos( $page, 'mlang' ) ) {
			return;
		}
		self::register_all();
	}

	/**
	 * Re-registers a single form's strings after it is saved in the form editor.
	 *
	 * @param array $form   The saved form.
	 * @param bool  $is_new Whether the form is new (unused).
	 * @return void
	 */
	public static function on_form_saved( $form, $is_new = false ): void {
		if ( ! Integrations::enabled( 'gravityforms' ) || ! is_array( $form ) ) {
			return;
		}
		self::register_form( $form );
	}

	/**
	 * Registers the strings of every Gravity Forms form with Polylang.
	 *
	 * @return void
	 */
	public static function register_all(): void {
		if ( ! class_exists( 'GFAPI' ) || ! function_exists( 'pll_register_string' ) ) {
			return;
		}
		foreach ( GFAPI::get_forms() as $form ) {
			if ( is_array( $form ) ) {
				self::register_form( $form );
			}
		}
	}

	/**
	 * Registers one form's translatable strings with Polylang, grouped by form.
	 *
	 * @param array $form Gravity Forms form.
	 * @return void
	 */
	public static function register_form( array $form ): void {
		if ( ! function_exists( 'pll_register_string' ) ) {
			return;
		}
		$group = self::group_name( $form );
		foreach ( self::unique_strings( $form ) as $name => $value ) {
			pll_register_string( $name, $value, $group, self::is_multiline( $value ) );
		}
	}

	/**
	 * Writes source => translation pairs into Polylang's store for a language.
	 *
	 * This is the same store Polylang's String translations screen saves to, so the
	 * results show up (and can be edited) there immediately.
	 *
	 * @param string                $lang_slug Target language slug.
	 * @param array<string, string> $pairs     Map of source string => translation.
	 * @return bool True if anything was written.
	 */
	public static function save_translations( string $lang_slug, array $pairs ): bool {
		return String_Store::save_translations( $lang_slug, $pairs );
	}

	/**
	 * Returns the form's unique translatable strings in a stable order.
	 *
	 * Both the string editor and its save handler use this so a row index maps to
	 * the same source string on render and on save.
	 *
	 * @param array $form Gravity Forms form.
	 * @return array<int, array{name: string, source: string}>
	 */
	public static function collect_unique( array $form ): array {
		$out  = array();
		$seen = array();
		foreach ( Fields::collect( $form ) as $path => $value ) {
			if ( in_array( $value, $seen, true ) ) {
				continue;
			}
			$seen[] = $value;
			$out[]  = array(
				'name'   => self::string_name( $path ),
				'source' => $value,
			);
		}
		return $out;
	}

	/**
	 * Returns the Polylang languages other than the default (i.e. translation targets).
	 *
	 * @return array<int, array{slug: string, name: string}>
	 */
	public static function target_languages(): array {
		return String_Store::target_languages();
	}

	/**
	 * Reads translations of many source strings for one language in a single pass.
	 *
	 * Imports the language's PLL_MO once (unlike {@see get_translation()} which
	 * imports per call), so the editor can build a whole column cheaply.
	 *
	 * @param string   $lang_slug Language slug.
	 * @param string[] $sources   Source strings.
	 * @return array<string, string> source => translation ('' when untranslated).
	 */
	public static function translations_for( string $lang_slug, array $sources ): array {
		return String_Store::translations_for( $lang_slug, $sources );
	}

	/**
	 * Reads the current translation of a single source string for a language.
	 *
	 * @param string $source    Source string.
	 * @param string $lang_slug Language slug.
	 * @return string The translation, or '' if none (never falls back to source).
	 */
	public static function get_translation( string $source, string $lang_slug ): string {
		return String_Store::get_translation( $source, $lang_slug );
	}

	/**
	 * Counts how many of a form's strings are translated into a language.
	 *
	 * @param array  $form      Gravity Forms form.
	 * @param string $lang_slug Language slug.
	 * @return array{total: int, translated: int}
	 */
	public static function translation_status( array $form, string $lang_slug ): array {
		$strings = array_values( array_unique( array_values( Fields::collect( $form ) ) ) );
		$total   = count( $strings );
		if ( 0 === $total ) {
			return array(
				'total'      => 0,
				'translated' => 0,
			);
		}

		$map        = String_Store::translations_for( $lang_slug, $strings );
		$translated = 0;
		foreach ( $strings as $source ) {
			if ( '' !== ( $map[ $source ] ?? '' ) ) {
				++$translated;
			}
		}

		return array(
			'total'      => $total,
			'translated' => $translated,
		);
	}

	/**
	 * Returns the unique translatable strings of a form, keyed by a stable name.
	 *
	 * Dedupes by value so each distinct source string is registered once; the name
	 * is derived from the first path that produced it, giving readable context in
	 * Polylang's "Name" column.
	 *
	 * @param array $form Gravity Forms form.
	 * @return array<string, string> name => source string.
	 */
	private static function unique_strings( array $form ): array {
		$out = array();
		foreach ( self::collect_unique( $form ) as $item ) {
			$out[ $item['name'] ] = $item['source'];
		}
		return $out;
	}

	/**
	 * Turns a collect() path into a readable Polylang string name.
	 *
	 * `field.7.label` => `Field 7 label`, `button.text` => `Button text`.
	 *
	 * @param string $path Path key from {@see Fields::collect()}.
	 * @return string
	 */
	private static function string_name( string $path ): string {
		$label = str_replace( array( '.', '_' ), ' ', $path );
		return ucfirst( trim( $label ) );
	}

	/**
	 * Builds the Polylang group label for a form.
	 *
	 * @param array $form Gravity Forms form.
	 * @return string
	 */
	private static function group_name( array $form ): string {
		$id    = (int) ( $form['id'] ?? 0 );
		$title = trim( (string) ( $form['title'] ?? '' ) );
		return '' !== $title
			? sprintf( 'Gravity Forms: %s (#%d)', $title, $id )
			: sprintf( 'Gravity Forms #%d', $id );
	}

	/**
	 * Whether a string should be registered as multiline (bigger textarea in the UI).
	 *
	 * @param string $value Source string.
	 * @return bool
	 */
	private static function is_multiline( string $value ): bool {
		return mb_strlen( $value ) > 40 || false !== strpos( $value, "\n" );
	}
}
