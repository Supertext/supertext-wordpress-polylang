<?php
/**
 * @package Supertext_Polylang
 */

namespace Supertext\Polylang\Tests\Cases;

use Brain\Monkey\Functions;
use Supertext\Polylang\Human_Translation\Callback;
use Supertext\Polylang\Tests\TestCase;

class CallbackTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		// secret() reads this option; keep it stable so HMACs are deterministic.
		Functions\when( 'get_option' )->justReturn( 'unit-test-secret' );
	}

	public function test_reference_data_round_trips(): void {
		$ref = Callback::reference_data( 165, 'fr' );

		$this->assertStringStartsWith( '165:fr:', $ref );

		$parsed = self::callPrivate( Callback::class, 'parse_reference_data', array( $ref ) );
		$this->assertSame( array( 'post_id' => 165, 'lang' => 'fr' ), $parsed );
	}

	public function test_tampered_reference_data_is_rejected(): void {
		$ref      = Callback::reference_data( 165, 'fr' );
		$tampered = $ref . 'x';
		$this->assertNull( self::callPrivate( Callback::class, 'parse_reference_data', array( $tampered ) ) );

		// Wrong post id with the same (now invalid) signature.
		$forged = '999:fr:' . substr( $ref, strrpos( $ref, ':' ) + 1 );
		$this->assertNull( self::callPrivate( Callback::class, 'parse_reference_data', array( $forged ) ) );
	}

	public function test_malformed_reference_data_is_rejected(): void {
		$this->assertNull( self::callPrivate( Callback::class, 'parse_reference_data', array( '' ) ) );
		$this->assertNull( self::callPrivate( Callback::class, 'parse_reference_data', array( '165:fr' ) ) );
	}

	public function test_extract_order_ids_from_single_and_array(): void {
		$this->assertSame( array( 5 ), self::callPrivate( Callback::class, 'extract_order_ids', array( array( 'Id' => 5 ) ) ) );
		$this->assertSame(
			array( 1, 2 ),
			self::callPrivate( Callback::class, 'extract_order_ids', array( array( array( 'Id' => 1 ), array( 'Id' => 2 ) ) ) )
		);
		$this->assertSame( array(), self::callPrivate( Callback::class, 'extract_order_ids', array( array() ) ) );
	}

	public function test_extract_reference_data(): void {
		$this->assertSame( 'a', self::callPrivate( Callback::class, 'extract_reference_data', array( array( 'ReferenceData' => 'a' ) ) ) );
		$this->assertSame( 'b', self::callPrivate( Callback::class, 'extract_reference_data', array( array( array( 'ReferenceData' => 'b' ) ) ) ) );
		$this->assertSame( '', self::callPrivate( Callback::class, 'extract_reference_data', array( array( 'foo' => 'bar' ) ) ) );
	}
}
