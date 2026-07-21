<?php
/**
 * @package Supertext_Polylang
 */

namespace Supertext\Polylang\Polylang;

defined( 'ABSPATH' ) || exit;

use WP_Error;
use WP_Syntex\Polylang_Pro\Modules\Machine_Translation\Factory;

/**
 * Neutral read/write access to Polylang's string-translation store (`PLL_MO`).
 *
 * This is the same store Polylang's Languages → String translations screen uses.
 * Keeping the access in one place means the Gravity Forms editor, the general
 * String Translation page, Supertext AI, and manual edits are all the same record.
 *
 * Everything is keyed by the source string value (gettext msgid).
 *
 * @since 0.9.0
 */
class String_Store {
	/**
	 * Returns the Polylang languages other than the default (translation targets).
	 *
	 * @return array<int, array{slug: string, name: string}>
	 */
	public static function target_languages(): array {
		if ( ! function_exists( 'PLL' ) || ! isset( PLL()->model ) ) {
			return array();
		}
		$default = function_exists( 'pll_default_language' ) ? (string) pll_default_language( 'slug' ) : '';
		$out     = array();
		foreach ( PLL()->model->get_languages_list() as $lang ) {
			if ( $lang->slug === $default ) {
				continue;
			}
			$out[] = array(
				'slug' => $lang->slug,
				'name' => $lang->name,
			);
		}
		return $out;
	}

	/**
	 * Writes source => translation pairs into Polylang's store for a language.
	 *
	 * @param string                $lang_slug Target language slug.
	 * @param array<string, string> $pairs     source => translation.
	 * @return bool True if anything was written.
	 */
	public static function save_translations( string $lang_slug, array $pairs ): bool {
		$language = self::language( $lang_slug );
		if ( null === $language || ! class_exists( 'PLL_MO' ) ) {
			return false;
		}

		$mo = new \PLL_MO();
		$mo->import_from_db( $language );

		$changed = false;
		foreach ( $pairs as $source => $translation ) {
			$source      = (string) $source;
			$translation = (string) $translation;
			if ( '' === $source || '' === $translation ) {
				continue;
			}
			$mo->add_entry( $mo->make_entry( $source, $translation ) );
			$changed = true;
		}

		if ( $changed ) {
			$mo->export_to_db( $language );
		}
		return $changed;
	}

	/**
	 * Reads translations of many source strings for one language in a single pass.
	 *
	 * @param string   $lang_slug Language slug.
	 * @param string[] $sources   Source strings.
	 * @return array<string, string> source => translation ('' when untranslated).
	 */
	public static function translations_for( string $lang_slug, array $sources ): array {
		$out      = array();
		$language = self::language( $lang_slug );
		if ( null === $language || ! class_exists( 'PLL_MO' ) ) {
			foreach ( $sources as $source ) {
				$out[ (string) $source ] = '';
			}
			return $out;
		}

		$mo = new \PLL_MO();
		$mo->import_from_db( $language );
		foreach ( $sources as $source ) {
			$source         = (string) $source;
			$translation    = $mo->translate( $source );
			$out[ $source ] = ( is_string( $translation ) && $translation !== $source ) ? $translation : '';
		}
		return $out;
	}

	/**
	 * Reads the current translation of one source string.
	 *
	 * @param string $source    Source string.
	 * @param string $lang_slug Language slug.
	 * @return string The translation, or '' if none.
	 */
	public static function get_translation( string $source, string $lang_slug ): string {
		$map = self::translations_for( $lang_slug, array( $source ) );
		return (string) ( $map[ $source ] ?? '' );
	}

	/**
	 * Translates one source string into a language via Polylang's active Supertext
	 * MT service. Does not persist anything.
	 *
	 * @param string $source      Source string.
	 * @param string $target_slug Target language slug.
	 * @return string|WP_Error The translation on success.
	 */
	public static function translate_one( string $source, string $target_slug ) {
		$result = self::translate_many( array( $source ), $target_slug );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return (string) ( $result[ $source ] ?? '' );
	}

	/**
	 * Translates many source strings into a language in a single Supertext call.
	 *
	 * @param string[] $sources     Source strings.
	 * @param string   $target_slug Target language slug.
	 * @return array<string, string>|WP_Error source => translation on success.
	 */
	public static function translate_many( array $sources, string $target_slug ) {
		$sources = array_values( array_unique( array_filter( array_map( 'strval', $sources ), static fn( $s ) => '' !== $s ) ) );
		if ( empty( $sources ) || '' === $target_slug ) {
			return new WP_Error( 'supertext_missing', __( 'Nothing to translate, or no target language.', 'supertext-polylang' ) );
		}
		if ( ! function_exists( 'PLL' ) || ! isset( PLL()->model ) || ! class_exists( Factory::class ) ) {
			return new WP_Error( 'supertext_no_pll', __( 'Polylang is not available.', 'supertext-polylang' ) );
		}

		$model       = PLL()->model;
		$target      = $model->get_language( $target_slug );
		$source_lang = function_exists( 'pll_default_language' ) ? $model->get_language( (string) pll_default_language( 'slug' ) ) : null;
		if ( ! $target ) {
			return new WP_Error( 'supertext_bad_lang', __( 'Unknown target language.', 'supertext-polylang' ) );
		}

		$service = ( new Factory( $model ) )->get_active_service();
		if ( null === $service ) {
			return new WP_Error( 'supertext_no_service', __( 'No active Supertext AI service.', 'supertext-polylang' ) );
		}

		$client = $service->get_client();
		if ( ! method_exists( $client, 'translate_strings' ) ) {
			return new WP_Error( 'supertext_client', __( 'The active service cannot translate strings.', 'supertext-polylang' ) );
		}

		// Key each string by index so we can map translations back to their source.
		$input = array();
		foreach ( $sources as $i => $source ) {
			$input[ 's' . $i ] = $source;
		}

		$result = $client->translate_strings( $input, $target, $source_lang );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$out = array();
		foreach ( $sources as $i => $source ) {
			$out[ $source ] = isset( $result[ 's' . $i ] ) ? (string) $result[ 's' . $i ] : '';
		}
		return $out;
	}

	/**
	 * Resolves a language slug to a Polylang language object.
	 *
	 * @param string $slug Language slug.
	 * @return \PLL_Language|null
	 */
	private static function language( string $slug ) {
		if ( '' === $slug || ! function_exists( 'PLL' ) || ! isset( PLL()->model ) ) {
			return null;
		}
		$language = PLL()->model->get_language( $slug );
		return $language ?: null;
	}
}
