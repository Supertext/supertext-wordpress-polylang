<?php
/**
 * @package Supertext_Polylang
 */

namespace Supertext\Polylang\Tests\Cases;

use PHPUnit\Framework\TestCase;
use Supertext\Polylang\Machine_Translation\Language_Codes;

class LanguageCodesTest extends TestCase {
	public function test_bare_source_is_sent_as_is(): void {
		$this->assertSame( 'en', Language_Codes::source( 'en' ) );
		$this->assertSame( 'de', Language_Codes::source( 'DE' ) );
	}

	public function test_regional_source_loses_its_region(): void {
		$this->assertSame( 'en', Language_Codes::source( 'en-US' ) );
		$this->assertSame( 'en', Language_Codes::source( 'en_GB' ) );
		$this->assertSame( 'de', Language_Codes::source( 'de-CH' ) );
		$this->assertSame( 'de', Language_Codes::source( 'de_DE_formal' ) );
		$this->assertSame( '', Language_Codes::source( '' ) );
	}

	public function test_bare_target_gets_the_default_region(): void {
		$this->assertSame( 'de-DE', Language_Codes::target( 'de' ) );
		$this->assertSame( 'en-US', Language_Codes::target( 'en' ) );
		$this->assertSame( 'fi-FI', Language_Codes::target( 'fi' ) );
		$this->assertSame( 'ja-JP', Language_Codes::target( 'ja' ) );
		$this->assertSame( 'el-GR', Language_Codes::target( 'el' ) );
	}

	public function test_regional_target_is_kept_and_normalized(): void {
		$this->assertSame( 'de-CH', Language_Codes::target( 'de_CH' ) );
		$this->assertSame( 'de-CH', Language_Codes::target( 'de-ch' ) );
		$this->assertSame( 'zh-Hant', Language_Codes::target( 'zh_hant' ) );
		$this->assertSame( 'es-419', Language_Codes::target( 'es-419' ) );
	}

	public function test_wordpress_variants_are_dropped(): void {
		$this->assertSame( 'de-DE', Language_Codes::target( 'de_DE_formal' ) );
		$this->assertSame( 'de-CH', Language_Codes::target( 'de_CH_informal' ) );
		$this->assertSame( 'pt-PT', Language_Codes::target( 'pt_PT_ao90' ) );
	}

	public function test_override_wins(): void {
		$this->assertSame( 'de-AT', Language_Codes::target( 'de-DE', 'de_at' ) );
		$this->assertSame( 'fr-CH', Language_Codes::target( 'fr-FR', ' fr-CH ' ) );
		$this->assertSame( 'fr-FR', Language_Codes::target( 'fr-CH', 'fr' ), 'a bare override gets the default region' );
		$this->assertSame( 'pt', Language_Codes::source( 'en-US', 'pt-BR' ) );
		$this->assertSame( 'de-DE', Language_Codes::target( 'de', '  ' ) );
	}

	public function test_unknown_bare_code_is_sent_unchanged(): void {
		$this->assertSame( 'xx', Language_Codes::target( 'xx' ) );
		$this->assertSame( 'gsw', Language_Codes::target( 'gsw' ) );
	}
}
