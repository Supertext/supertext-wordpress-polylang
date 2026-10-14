<?php
/**
 * @package Supertext_Polylang
 */

namespace Supertext\Polylang\Machine_Translation;

defined( 'ABSPATH' ) || exit;

use Supertext\Polylang\Sdk\V1\Languages;
use Supertext\Polylang\Sdk\V1\Politeness;
use Supertext\Polylang\Sdk\V1\SupertextException;

/**
 * Polylang languages → Supertext language codes, without guessing.
 *
 * Supertext accepts only the codes of its `GET features` list (the SDK's
 * `Languages`): the source as a bare language (`en`), the target as listed
 * (`de-CH`, `en-US`, `zh-Hans`, but `es`, `nl`). A Polylang language is
 * resolved by its locale (`de_CH`, `de_DE_formal`): the administrator's
 * mapping (Supertext → Language mapping) wins, else an exact match of the
 * locale (case and `_`/`-` ignored: `de_CH` → `de-CH`), else the SDK error
 * `unsupported_language` and nothing is sent. There are no default regions:
 * whether `de_DE_formal` or a bare `fi` should go to Supertext as `de-DE`
 * or `fi` is the administrator's decision. One mapping serves both
 * directions (a mapped `de-CH` is sent as `de` when it is the source).
 *
 * Has no WordPress dependency (unit-tested on its own).
 */
class Language_Codes {
	/**
	 * Target code to send.
	 *
	 * @param string                $locale  The Polylang language's locale (`de_CH`, `de_DE_formal`).
	 * @param array<string, string> $mapping Locale → Supertext code (the settings' language mapping).
	 * @return string
	 * @throws SupertextException `unsupported_language` (with targetLang) when the locale has neither a mapping nor a match.
	 */
	public static function target( string $locale, array $mapping = array() ): string {
		return Languages::target( $locale, self::clean( $mapping ) );
	}

	/**
	 * Source code to send (bare: `de_CH` → `de`); '' stays '' (auto-detect).
	 *
	 * @param string                $locale  The Polylang language's locale.
	 * @param array<string, string> $mapping Locale → Supertext code.
	 * @return string
	 * @throws SupertextException `unsupported_language` (with sourceLang).
	 */
	public static function source( string $locale, array $mapping = array() ): string {
		return Languages::source( $locale, self::clean( $mapping ) );
	}

	/**
	 * The target code a locale resolves to, or null when it would fail with `unsupported_language`.
	 *
	 * @param string                $locale  The locale.
	 * @param array<string, string> $mapping Locale → Supertext code.
	 * @return string|null
	 */
	public static function resolve( string $locale, array $mapping = array() ): ?string {
		try {
			return self::target( $locale, $mapping );
		} catch ( SupertextException $e ) {
			return null;
		}
	}

	/**
	 * Whether the formal/informal choice is offered for a target (only where Supertext supports it).
	 *
	 * @param string $target Supertext target code.
	 * @return bool
	 */
	public static function supports_politeness( string $target ): bool {
		return true === Languages::supportsPoliteness( $target );
	}

	/**
	 * The politeness to send: the language's setting (`more`/`less`), else what the
	 * WordPress locale says (`*_formal` → `more`, `*_informal` → `less`), else `default`.
	 * Always `default` for a target without formal/informal (sending it would fail with
	 * `unsupported_feature`).
	 *
	 * @param string $target  Supertext target code.
	 * @param string $setting The language's politeness setting: `more`, `less` or '' (automatic).
	 * @param string $locale  The WordPress locale.
	 * @return string `default`, `more` or `less`.
	 */
	public static function politeness( string $target, string $setting, string $locale ): string {
		if ( ! self::supports_politeness( $target ) ) {
			return Politeness::DEFAULT;
		}
		if ( in_array( $setting, array( Politeness::MORE, Politeness::LESS ), true ) ) {
			return $setting;
		}
		if ( str_ends_with( $locale, '_formal' ) ) {
			return Politeness::MORE;
		}
		if ( str_ends_with( $locale, '_informal' ) ) {
			return Politeness::LESS;
		}

		return Politeness::DEFAULT;
	}

	/**
	 * The targets for the mapping drop-down: code => name.
	 *
	 * @return array<string, string>
	 */
	public static function targets(): array {
		$out = array();
		foreach ( Languages::TARGETS as $target ) {
			$out[ (string) $target['code'] ] = (string) $target['name'];
		}

		return $out;
	}

	/**
	 * Drops blank mapping values (a blank select means "exact match").
	 *
	 * @param array<mixed> $mapping Raw mapping.
	 * @return array<string, string>
	 */
	private static function clean( array $mapping ): array {
		$out = array();
		foreach ( $mapping as $key => $value ) {
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				$out[ (string) $key ] = trim( $value );
			}
		}

		return $out;
	}
}
