<?php
/**
 * @package Supertext_Polylang
 */

namespace Supertext\Polylang\Tests\Cases;

use Brain\Monkey\Functions;
use Supertext\Polylang\Human_Translation\Client;
use Supertext\Polylang\Tests\TestCase;

class ClientTest extends TestCase {
	const BASE  = 'https://staging.supertext.com/';
	const EMAIL = 'tester@supertext.com';
	const KEY   = 'legacy-key';

	/**
	 * Stubs wp_remote_request to capture the request and return a canned response.
	 *
	 * @param int    $code Response status code.
	 * @param string $body Response body.
	 * @return object Holds ->url, ->method, ->headers, ->body after the call.
	 */
	private function captureRequest( int $code, string $body ) {
		$captured = new \stdClass();
		Functions\when( 'wp_remote_request' )->alias(
			function ( $url, $args ) use ( $captured, $code, $body ) {
				$captured->url     = $url;
				$captured->method  = $args['method'] ?? '';
				$captured->headers = $args['headers'] ?? array();
				$captured->body    = $args['body'] ?? '';
				return array( 'response' => array( 'code' => $code ), 'body' => $body );
			}
		);
		return $captured;
	}

	public function test_upload_file_returns_highest_document_id(): void {
		$cap    = $this->captureRequest( 200, '[{"Id":111},{"Id":222}]' );
		$client = new Client( self::BASE, self::EMAIL, self::KEY );

		$id = $client->upload_file( '<html>x</html>', 'content.html' );

		$this->assertSame( 222, $id );
		$this->assertSame( self::BASE . 'api/v1/files/files', $cap->url );
		$this->assertSame( 'POST', $cap->method );
		$this->assertSame( 'Basic ' . base64_encode( self::EMAIL . ':' . self::KEY ), $cap->headers['Authorization'] );
		$this->assertStringContainsString( 'multipart/form-data; boundary=', $cap->headers['Content-Type'] );
	}

	public function test_create_order_posts_json_and_returns_decoded(): void {
		$cap    = $this->captureRequest( 200, '[{"Id":715113,"TargetLang":"de-CH"}]' );
		$client = new Client( self::BASE, self::EMAIL, self::KEY );

		$order  = array( 'DeliveryId' => 1, 'OrderName' => 'Test' );
		$result = $client->create_order( $order );

		$this->assertSame( array( array( 'Id' => 715113, 'TargetLang' => 'de-CH' ) ), $result );
		$this->assertSame( self::BASE . 'api/v1.1/translation/order', $cap->url );
		$this->assertSame( 'POST', $cap->method );
		$this->assertSame( json_encode( $order ), $cap->body );
	}

	public function test_download_file_url_encodes_name_and_returns_body(): void {
		$cap    = $this->captureRequest( 200, '<html>translated</html>' );
		$client = new Client( self::BASE, self::EMAIL, self::KEY );

		$body = $client->download_file( 4439915, 'A B.html' );

		$this->assertSame( '<html>translated</html>', $body );
		$this->assertSame( self::BASE . 'storage/file/4439915/A%20B.html', $cap->url );
		$this->assertSame( 'GET', $cap->method );
	}

	public function test_missing_credentials_returns_error_without_request(): void {
		$called = false;
		Functions\when( 'wp_remote_request' )->alias(
			function () use ( &$called ) {
				$called = true;
				return array( 'response' => array( 'code' => 200 ), 'body' => '[]' );
			}
		);

		$client = new Client( self::BASE, '', '' );
		$result = $client->upload_file( '<html></html>', 'content.html' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertFalse( $called, 'No HTTP request should be made without credentials' );
	}
}
