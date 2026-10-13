<?php
/**
 * @package Supertext_Polylang
 */

namespace Supertext\Polylang\Machine_Translation;

defined( 'ABSPATH' ) || exit;

use Supertext\Polylang\Sdk\LanguageCodes as SdkLanguageCodes;

/**
 * Polylang locales → Supertext language codes.
 *
 * Supertext wants the source as a bare language (`en`) and the target with a
 * region (`de-DE`, `en-US`); a bare target such as `de` is rejected with
 * INVALID_LANGUAGE_PAIR. Polylang has bare locales too (`fi`, `ja`, `el`, …),
 * and WordPress variants such as `de_DE_formal` or `pt_PT_ao90`. Locales
 * without a region get the Supertext SDK's default region; unknown bare codes
 * are sent unchanged, so Supertext's error names them. The SDK drops
 * WordPress variants (`de_DE_formal` → `de-DE`); the formality itself becomes
 * the politeness setting (see Client).
 *
 * Has no WordPress dependency (unit-tested on its own).
 */
class Language_Codes {
	/**
	 * The region Supertext uses when a locale has none (the Supertext SDK's table,
	 * the same in every Supertext plugin).
	 *
	 * @var array<string, string>
	 */
	const DEFAULT_REGION = SdkLanguageCodes::DEFAULT_REGIONS;

	/**
	 * Normalizes a code: `de_ch` → `de-CH`, `DE` → `de`, `zh_hant` → `zh-Hant`,
	 * `es-419` stays. WordPress variants are dropped: `de_DE_formal` → `de-DE`,
	 * `pt_PT_ao90` → `pt-PT`.
	 *
	 * @param string $code Locale or language code.
	 * @return string
	 */
	public static function normalize( string $code ): string {
		return SdkLanguageCodes::normalize( $code );
	}

	/**
	 * Source code to send: the bare language of the override, else of the
	 * locale (`en-GB` → `en`).
	 *
	 * @param string $locale   Polylang code (w3c or locale).
	 * @param string $override Code mapped in the settings, '' for none.
	 * @return string
	 */
	public static function source( string $locale, string $override = '' ): string {
		return SdkLanguageCodes::source( '' !== trim( $override ) ? $override : $locale );
	}

	/**
	 * Target code to send: the override, else the locale; without a region it
	 * gets the default one (`de` → `de-DE`).
	 *
	 * @param string $locale   Polylang code (w3c or locale).
	 * @param string $override Code mapped in the settings, '' for none.
	 * @return string
	 */
	public static function target( string $locale, string $override = '' ): string {
		return SdkLanguageCodes::target( '' !== trim( $override ) ? $override : $locale );
	}
}
