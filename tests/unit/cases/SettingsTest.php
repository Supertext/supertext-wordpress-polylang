<?php
/**
 * @package Supertext_Polylang
 */

namespace Supertext\Polylang\Tests\Cases;

use Brain\Monkey\Functions;
use Supertext\Polylang\Admin\Settings;
use Supertext\Polylang\Tests\TestCase;

class SettingsTest extends TestCase {
	public function test_defaults_enable_preview_links_and_screenshots(): void {
		$defaults = Settings::defaults();

		$this->assertTrue( $defaults['preview_links_enabled'] );
		$this->assertTrue( $defaults['screenshots_enabled'] );
	}

	public function test_sanitize_reads_toggle_checkboxes_on(): void {
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();

		$out = Settings::sanitize(
			array(
				'preview_links_enabled' => '1',
				'screenshots_enabled'   => '1',
			)
		);

		$this->assertTrue( $out['preview_links_enabled'] );
		$this->assertTrue( $out['screenshots_enabled'] );
	}

	public function test_sanitize_treats_absent_checkboxes_as_off(): void {
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();

		// Unchecked checkboxes are simply absent from the submitted form.
		$out = Settings::sanitize( array() );

		$this->assertFalse( $out['preview_links_enabled'] );
		$this->assertFalse( $out['screenshots_enabled'] );
	}

	public function test_screenshots_enabled_true_when_option_absent(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$this->assertTrue( Settings::screenshots_enabled() );
	}

	public function test_screenshots_enabled_false_when_disabled_in_option(): void {
		Functions\when( 'get_option' )->justReturn( array( 'screenshots_enabled' => false ) );

		$this->assertFalse( Settings::screenshots_enabled() );
	}

	public function test_preview_links_enabled_true_when_option_absent(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$this->assertTrue( Settings::preview_links_enabled() );
	}

	public function test_preview_links_enabled_false_when_disabled_in_option(): void {
		Functions\when( 'get_option' )->justReturn( array( 'preview_links_enabled' => false ) );

		$this->assertFalse( Settings::preview_links_enabled() );
	}
}
