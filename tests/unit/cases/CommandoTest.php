<?php
/**
 * @package Supertext_Polylang
 */

namespace Supertext\Polylang\Tests\Cases;

use Brain\Monkey\Functions;
use Supertext\Polylang\Reporting\Commando;
use Supertext\Polylang\Sdk\Http\HttpRequest;
use Supertext\Polylang\Sdk\Http\HttpResponse;
use Supertext\Polylang\Sdk\Http\HttpTransport;
use Supertext\Polylang\Sdk\V1\ApiKeyCheck;
use Supertext\Polylang\Tests\TestCase;

/**
 * WordPress side of the Commando Center: options, ping, messages, once-per-request.
 */
class CommandoTest extends TestCase {
	/** @var array<string, mixed> */
	private $options = array();

	/** @var array<int, HttpRequest> */
	public $requests = array();

	protected function setUp(): void {
		parent::setUp();
		putenv( 'SUPERTEXT_REPORT_URL=http://127.0.0.1:9/report' );
		Commando::reset();
		$this->options = array(
			'polylang' => array(
				'machine_translation_services' => array(
					'supertext' => array(
						'api_key'  => 'the-key',
						'endpoint' => '',
					),
				),
			),
		);
		Functions\when( 'get_option' )->alias( fn( $name, $default = false ) => $this->options[ $name ] ?? $default );
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'home_url' )->justReturn( 'https://www.example.ch/' );
		Functions\when( 'rest_url' )->alias( static fn( $path ) => 'https://www.example.ch/wp-json/' . $path );
		Functions\when( 'get_bloginfo' )->justReturn( '6.9' );
		Functions\when( 'add_action' )->justReturn( true );

		$test                = $this;
		Commando::$transport = new class( $test ) implements HttpTransport {
			private $test;

			public function __construct( $test ) {
				$this->test = $test;
			}

			public function send( HttpRequest $request ): HttpResponse {
				$this->test->requests[] = $request;
				return str_contains( $request->url, '/report' )
					? new HttpResponse( 202, '{"ok":true}', array() )
					: new HttpResponse( 200, '[]', array() );
			}
		};
	}

	protected function tearDown(): void {
		Commando::$transport = null;
		Commando::reset();
		putenv( 'SUPERTEXT_REPORT_URL' );
		parent::tearDown();
	}

	public function test_after_settings_saved_checks_the_stored_key_and_stores_the_state(): void {
		$check = Commando::after_settings_saved();

		$this->assertSame( ApiKeyCheck::OK, $check->status );
		$this->assertStringStartsWith( 'https://api.supertext.com/v1/features', $this->requests[0]->url );
		$this->assertSame( 'http://127.0.0.1:9/report', $this->requests[1]->url );
		$this->assertIsString( $this->options[ Commando::STATE_OPTION ] );
		$this->assertStringNotContainsString( 'the-key', $this->options[ Commando::STATE_OPTION ] );
		$this->assertSame( ApiKeyCheck::OK, Commando::last_check()->status );

		$this->assertNull( Commando::after_settings_saved(), 'once per request' );
	}

	public function test_the_key_being_saved_and_its_environment_are_used(): void {
		Commando::after_settings_saved( 'new-key', 'https://api.staging.supertext.com/v1/' );

		$this->assertStringStartsWith( 'https://api.staging.supertext.com/v1/features', $this->requests[0]->url );
		$this->assertSame( 'Supertext-Auth-Key new-key', $this->requests[0]->headers['Authorization'] );
	}

	public function test_version_reports_off_sends_no_report(): void {
		$this->options['supertext_polylang_settings'] = array( 'version_reports' => false );

		Commando::after_settings_saved();

		$this->assertCount( 1, $this->requests, 'only the key check' );
	}

	public function test_ping_answers_with_the_sdk_response(): void {
		$request = new class() {
			public function get_param( $name ) {
				return 'challenge' === $name ? 'abcdefghijklmnop' : null;
			}
		};
		$response = Commando::handle_ping( $request );

		$this->assertSame( 200, $response->status );
		$this->assertSame( array( 'ok' => true, 'plugin' => 'supertext-wordpress-polylang', 'challenge' => 'abcdefghijklmnop' ), $response->data );
		$this->assertSame( 'no-store', $response->headers['Cache-Control'] );

		$this->options['supertext_polylang_settings'] = array( 'version_reports' => false );
		$this->assertSame( 404, Commando::handle_ping( $request )->status );
	}

	public function test_messages_carry_both_links_for_invalid_and_missing(): void {
		foreach ( array( ApiKeyCheck::INVALID, ApiKeyCheck::MISSING ) as $status ) {
			$html = Commando::message( new ApiKeyCheck( $status, '2026-10-14T08:00:00Z' ), true );
			$this->assertSame( 'error', $html['type'] );
			$this->assertStringContainsString( 'href="https://www.supertext.com/person/en/account/signin" target="_blank" rel="noopener"', $html['text'] );
			$this->assertStringContainsString( 'href="https://www.supertext.com/en/integrations/api" target="_blank" rel="noopener"', $html['text'] );

			$plain = Commando::message( new ApiKeyCheck( $status, '2026-10-14T08:00:00Z' ), false );
			$this->assertStringNotContainsString( '<a', $plain['text'] );
			$this->assertStringContainsString( 'https://www.supertext.com/en/integrations/api', $plain['text'] );
		}
		$this->assertSame( 'success', Commando::message( new ApiKeyCheck( ApiKeyCheck::OK, '2026-10-14T08:00:00Z' ), true )['type'] );
		$error = Commando::message( new ApiKeyCheck( ApiKeyCheck::ERROR, '2026-10-14T08:00:00Z', 500 ), true );
		$this->assertStringContainsString( '(500)', $error['text'] );
	}

	public function test_polylang_save_without_supertext_change_does_nothing(): void {
		$same = array( 'machine_translation_services' => array( 'supertext' => array( 'api_key' => 'a' ) ) );
		Commando::polylang_options_updated( $same, $same );
		Commando::run_deferred();
		$this->assertSame( array(), $this->requests );

		Commando::polylang_options_updated( $same, array( 'machine_translation_services' => array( 'supertext' => array( 'api_key' => 'b' ) ) ) );
		Commando::run_deferred();
		$this->assertNotEmpty( $this->requests );
	}

	public function test_authentication_failure_wins_over_on_request(): void {
		Commando::after_settings_saved();
		Commando::reset();
		$this->requests = array();

		Commando::authentication_failed( 401 );
		Commando::on_request();
		Commando::run_deferred();

		$this->assertSame( ApiKeyCheck::INVALID, Commando::last_check()->status );
		$this->assertCount( 1, $this->requests, 'the api_key_check report, no request to Supertext' );
	}
}
