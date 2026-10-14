<?php
/**
 * @package Supertext_Polylang
 */

namespace Supertext\Polylang\Tests\Cases;

use PHPUnit\Framework\TestCase;

/**
 * The German, French and Italian files: the same strings, complete, with the same
 * placeholders, URLs and "Supertext"; the .mo files compiled from them; and the
 * plugin's Commando Center and language strings all translated.
 */
class TranslationFilesTest extends TestCase {
	const LOCALES = array( 'de_DE', 'fr_FR', 'it_IT' );

	/**
	 * @return array<string, string>
	 */
	private static function po( string $locale ): array {
		$entries = array();
		$id      = null;
		$field   = null;
		$values  = array();
		$file    = dirname( __DIR__, 3 ) . '/languages/supertext-polylang-' . $locale . '.po';
		foreach ( file( $file, FILE_IGNORE_NEW_LINES ) as $line ) {
			if ( preg_match( '/^(msgid|msgstr) "(.*)"$/', $line, $m ) ) {
				if ( 'msgid' === $m[1] && isset( $values['msgid'], $values['msgstr'] ) ) {
					$entries[ $values['msgid'] ] = $values['msgstr'];
				}
				$field            = $m[1];
				$values[ $field ] = stripcslashes( $m[2] );
			} elseif ( preg_match( '/^"(.*)"$/', $line, $m ) && null !== $field ) {
				$values[ $field ] .= stripcslashes( $m[1] );
			}
		}
		if ( isset( $values['msgid'], $values['msgstr'] ) ) {
			$entries[ $values['msgid'] ] = $values['msgstr'];
		}
		unset( $entries[''], $id );

		return $entries;
	}

	/**
	 * @return array<int, string>
	 */
	private static function tokens( string $text ): array {
		preg_match_all( '/%(?:\d+\$)?[sd]|\{[a-z_]+\}|https?:\/\/[^\s)]+|Supertext|SUPERTEXT_[A-Z_]+/', $text, $m );
		sort( $m[0] );

		return $m[0];
	}

	public function test_all_languages_have_the_same_complete_strings(): void {
		$de = self::po( 'de_DE' );
		$this->assertNotEmpty( $de );
		foreach ( self::LOCALES as $locale ) {
			$entries = self::po( $locale );
			$this->assertSame( array_keys( $de ), array_keys( $entries ), $locale );
			foreach ( $entries as $id => $str ) {
				$this->assertNotSame( '', $str, "$locale: $id" );
				$this->assertSame( self::tokens( $id ), self::tokens( $str ), "$locale: placeholders, URLs and names in: $id" );
			}
		}
	}

	public function test_mo_files_are_compiled_from_the_po_files(): void {
		$dir = dirname( __DIR__, 3 ) . '/languages/';
		foreach ( self::LOCALES as $locale ) {
			$mo = (string) file_get_contents( $dir . 'supertext-polylang-' . $locale . '.mo' );
			foreach ( self::po( $locale ) as $id => $str ) {
				$this->assertStringContainsString( "\0" . $id . "\0", "\0" . $mo, "$locale.mo is outdated: run php scripts/compile-translations.php" );
				$this->assertStringContainsString( "\0" . $str . "\0", $mo, "$locale.mo is outdated: run php scripts/compile-translations.php" );
			}
		}
	}

	public function test_new_interface_strings_are_translated(): void {
		$root    = dirname( __DIR__, 3 );
		$entries = self::po( 'de_DE' );
		$files   = array(
			'/includes/Reporting/Commando.php',
			'/includes/Machine_Translation/Settings.php',
			'/includes/Machine_Translation/Client.php',
		);
		foreach ( $files as $file ) {
			$source = (string) file_get_contents( $root . $file );
			preg_match_all( "/(?:__|esc_html__|esc_html_e|esc_attr_e|esc_attr__)\\(\\s*'((?:[^'\\\\]|\\\\.)*)',\\s*'supertext-polylang'/", $source, $m );
			foreach ( $m[1] as $literal ) {
				$id = str_replace( array( "\\'", '\\\\' ), array( "'", '\\' ), $literal );
				$this->assertArrayHasKey( $id, $entries, "$file: untranslated string: $id" );
			}
		}
	}
}
