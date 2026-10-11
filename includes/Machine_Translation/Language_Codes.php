<?php
/**
 * @package Supertext_Polylang
 */

namespace Supertext\Polylang\Machine_Translation;

defined( 'ABSPATH' ) || exit;

/**
 * Polylang locales → Supertext language codes.
 *
 * Supertext wants the source as a bare language (`en`) and the target with a
 * region (`de-DE`, `en-US`); a bare target such as `de` is rejected with
 * INVALID_LANGUAGE_PAIR. Polylang has bare locales too (`fi`, `ja`, `el`, …),
 * and WordPress variants such as `de_DE_formal` or `pt_PT_ao90`. Locales
 * without a region get the default region below (same table as the other
 * Supertext plugins); unknown bare codes are sent unchanged, so Supertext's
 * error names them.
 *
 * Has no WordPress dependency (unit-tested on its own).
 */
class Language_Codes {
	/**
	 * The region Supertext uses when a locale has none.
	 *
	 * @var array<string, string>
	 */
	const DEFAULT_REGION = array(
		'ar' => 'ar-SA',
		'bg' => 'bg-BG',
		'cs' => 'cs-CZ',
		'da' => 'da-DK',
		'de' => 'de-DE',
		'el' => 'el-GR',
		'en' => 'en-US',
		'es' => 'es-ES',
		'et' => 'et-EE',
		'fi' => 'fi-FI',
		'fr' => 'fr-FR',
		'hr' => 'hr-HR',
		'hu' => 'hu-HU',
		'it' => 'it-IT',
		'ja' => 'ja-JP',
		'ko' => 'ko-KR',
		'lt' => 'lt-LT',
		'lv' => 'lv-LV',
		'nb' => 'nb-NO',
		'nl' => 'nl-NL',
		'pl' => 'pl-PL',
		'pt' => 'pt-PT',
		'ro' => 'ro-RO',
		'ru' => 'ru-RU',
		'sk' => 'sk-SK',
		'sl' => 'sl-SI',
		'sr' => 'sr-RS',
		'sv' => 'sv-SE',
		'th' => 'th-TH',
		'tr' => 'tr-TR',
		'uk' => 'uk-UA',
		'vi' => 'vi-VN',
		'zh' => 'zh-CN',
	);

	/**
	 * Normalizes a code: `de_ch` → `de-CH`, `DE` → `de`, `zh_hant` → `zh-Hant`,
	 * `es-419` stays. WordPress variants are dropped: `de_DE_formal` → `de-DE`,
	 * `pt_PT_ao90` → `pt-PT`.
	 *
	 * @param string $code Locale or language code.
	 * @return string
	 */
	public static function normalize( string $code ): string {
		$parts = array_values(
			array_filter(
				explode( '-', str_replace( '_', '-', trim( $code ) ) ),
				static function ( string $part ): bool {
					return '' !== $part;
				}
			)
		);
		if ( array() === $parts ) {
			return '';
		}

		$out = array( strtolower( (string) array_shift( $parts ) ) );
		foreach ( $parts as $part ) {
			if ( preg_match( '/^[A-Za-z]{2}$/', $part ) ) {
				$out[] = strtoupper( $part );
			} elseif ( preg_match( '/^[A-Za-z]{4}$/', $part ) ) {
				$out[] = ucfirst( strtolower( $part ) );
			} elseif ( preg_match( '/^\d{3}$/', $part ) ) {
				$out[] = $part;
			}
			// Anything else is a variant (formal, informal, ao90, valencia): not a Supertext subtag.
		}

		return implode( '-', $out );
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
		$code = self::normalize( '' !== trim( $override ) ? $override : $locale );

		return explode( '-', $code )[0];
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
		$code = self::normalize( '' !== trim( $override ) ? $override : $locale );
		if ( '' === $code || str_contains( $code, '-' ) ) {
			return $code;
		}

		return self::DEFAULT_REGION[ $code ] ?? $code;
	}
}
