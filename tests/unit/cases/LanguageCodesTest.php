<?php
/**
 * @package Supertext_Polylang
 */

namespace Supertext\Polylang\Tests\Cases;

use PHPUnit\Framework\TestCase;
use Supertext\Polylang\Machine_Translation\Language_Codes;
use Supertext\Polylang\Sdk\V1\SupertextException;

/**
 * No guessing: the mapping, else an exact match of the locale, else unsupported_language.
 */
class LanguageCodesTest extends TestCase {
	public function test_target_exact_match_ignores_case_and_underscores(): void {
		$this->assertSame( 'de-CH', Language_Codes::target( 'de_CH' ) );
		$this->assertSame( 'en-US', Language_Codes::target( 'en_us' ) );
		$this->assertSame( 'es', Language_Codes::target( 'es' ) );
		$this->assertSame( 'zh-Hant', Language_Codes::target( 'zh_hant' ) );
	}

	public function test_target_without_match_is_unsupported_and_names_the_locale(): void {
		foreach ( array( 'de', 'de_DE_formal', 'ca_valencia', 'es_ES', 'fi_FI', 'pt_PT_ao90' ) as $locale ) {
			try {
				Language_Codes::target( $locale );
				$this->fail( $locale . ' should not resolve without a mapping' );
			} catch ( SupertextException $e ) {
				$this->assertSame( SupertextException::UNSUPPORTED_LANGUAGE, $e->errorCode );
				$this->assertSame( $locale, $e->targetLang );
			}
		}
		$this->assertNull( Language_Codes::resolve( 'de_DE_formal' ) );
	}

	public function test_mapping_wins_and_is_sent_as_given(): void {
		$this->assertSame( 'de-DE', Language_Codes::target( 'de_DE_formal', array( 'de_DE_formal' => 'de-DE' ) ) );
		$this->assertSame( 'de-AT', Language_Codes::target( 'de_CH', array( 'de_CH' => ' de-AT ' ) ) );
		$this->assertSame( 'xx-YY', Language_Codes::target( 'de_CH', array( 'de_CH' => 'xx-YY' ) ), 'a code Supertext adds later works before the SDK knows it' );
		$this->assertSame( 'de-CH', Language_Codes::target( 'de_CH', array( 'de_CH' => '  ' ) ), 'a blank mapping means automatic' );
	}

	public function test_source_is_bare_and_one_mapping_serves_both_directions(): void {
		$this->assertSame( 'en', Language_Codes::source( 'en_US' ) );
		$this->assertSame( 'de', Language_Codes::source( 'de_DE_formal' ) );
		$this->assertSame( 'de', Language_Codes::source( 'de_DE_formal', array( 'de_DE_formal' => 'de-CH' ) ) );
		$this->assertSame( 'en', Language_Codes::source( 'en', array( 'en' => 'en-US' ) ) );
		$this->assertSame( '', Language_Codes::source( '' ) );
	}

	public function test_politeness_only_where_supported(): void {
		$this->assertSame( 'more', Language_Codes::politeness( 'de-CH', '', 'de_CH_formal' ) );
		$this->assertSame( 'less', Language_Codes::politeness( 'de-DE', '', 'de_DE_informal' ) );
		$this->assertSame( 'less', Language_Codes::politeness( 'de-DE', 'less', 'de_DE_formal' ), 'the setting wins' );
		$this->assertSame( 'default', Language_Codes::politeness( 'fr-CH', '', 'fr_CH' ) );
		$this->assertSame( 'default', Language_Codes::politeness( 'en-US', 'more', 'en_US' ), 'English has no formal/informal' );
		$this->assertSame( 'default', Language_Codes::politeness( 'xx-YY', 'more', 'xx_YY' ), 'unknown codes get none' );
		$this->assertTrue( Language_Codes::supports_politeness( 'de-CH' ) );
		$this->assertFalse( Language_Codes::supports_politeness( 'en-US' ) );
	}

	public function test_targets_list_for_the_drop_down(): void {
		$targets = Language_Codes::targets();
		$this->assertArrayHasKey( 'de-CH', $targets );
		$this->assertArrayHasKey( 'es', $targets );
		$this->assertArrayNotHasKey( 'es-ES', $targets );
	}
}
