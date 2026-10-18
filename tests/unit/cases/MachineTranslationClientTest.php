<?php
/**
 * @package Supertext_Polylang
 */

namespace WP_Syntex\Polylang_Pro\Modules\Machine_Translation\Clients {
	if ( ! interface_exists( Client_Interface::class ) ) {
		interface Client_Interface {
		}
	}
}

namespace WP_Syntex\Polylang_Pro\Modules\Machine_Translation\Services {
	if ( ! interface_exists( Service_Interface::class ) ) {
		interface Service_Interface {
		}
	}
}

namespace {
	if ( ! class_exists( 'PLL_Language' ) ) {
		class PLL_Language {
			public $slug;
			public $name;
			public $locale;

			public function __construct( string $slug, string $name, string $locale ) {
				$this->slug   = $slug;
				$this->name   = $name;
				$this->locale = $locale;
			}
		}
	}
}

namespace Supertext\Polylang\Tests\Cases {

	use Brain\Monkey\Functions;
	use Supertext\Polylang\Machine_Translation\Client;
	use Supertext\Polylang\Machine_Translation\Service;
	use Supertext\Polylang\Sdk\V1\SupertextException;
	use Supertext\Polylang\Tests\TestCase;

	/**
	 * AI client: languages without a Supertext code are refused before anything is sent.
	 */
	class MachineTranslationClientTest extends TestCase {
		public function test_unmapped_language_is_refused_without_a_request(): void {
			$called = false;
			Functions\when( 'wp_remote_request' )->alias(
				function () use ( &$called ) {
					$called = true;
					return array( 'response' => array( 'code' => 200 ), 'body' => '{}' );
				}
			);

			$client = new Client( array( 'api_key' => 'k' ) );
			$result = $client->translate_strings( array( 'Hello' ), new \PLL_Language( 'gsw', 'Schwiizerdütsch', 'gsw' ), new \PLL_Language( 'en', 'English', 'en_US' ) );

			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertSame( 'supertext_unsupported_language', $result->get_error_code() );
			$this->assertStringContainsString( 'Schwiizerdütsch (gsw)', $result->get_error_message() );
			$this->assertStringContainsString( 'Language mapping', $result->get_error_message() );
			$this->assertFalse( $called, 'nothing is sent to Supertext' );
		}

		public function test_mapping_resolves_the_target(): void {
			$language = new \PLL_Language( 'de', 'Deutsch (Sie)', 'de_DE_formal' );

			$this->assertSame( 'de-AT', Service::resolve_target( $language, array( 'de' => 'de-AT' ) ) );
			$this->assertSame( 'de-DE', Service::resolve_target( $language, array( 'de' => '' ) ), 'unmapped: the default of the locale without its variant' );
			$this->assertSame( 'de-CH', Service::resolve_target( new \PLL_Language( 'ch', 'Schweiz', 'de_CH' ), array() ) );

			$this->expectException( SupertextException::class );
			Service::resolve_target( new \PLL_Language( 'ie', 'English (Ireland)', 'en_IE' ), array( 'ie' => '' ) );
		}

		public function test_saved_options_keep_only_supertext_codes(): void {
			$value = array(
				'default_lang'                 => 'de',
				'machine_translation_services' => array(
					'supertext' => array(
						'api_key'    => 'k',
						'languages'  => array(
							'de' => 'de_ch',
							'en' => 'en-US',
							'es' => 'es-ES',
							'xx' => array( 'nope' ),
							'ie' => '',
						),
						'politeness' => array(
							'de' => 'more',
							'en' => 'more',
							'es' => 'sometimes',
							'ie' => 'less',
						),
						'review'     => array(
							'de' => '1',
							'en' => '0',
							'es' => true,
							'ie' => array( 'x' ),
						),
					),
				),
			);

			$clean    = Service::sanitize_saved_options( $value );
			$supertext = $clean['machine_translation_services']['supertext'];

			$this->assertSame( array( 'de' => 'de-CH', 'en' => 'en-US', 'es' => '', 'xx' => '', 'ie' => '' ), $supertext['languages'] );
			$this->assertSame( array( 'de' => 'more', 'en' => '', 'es' => '', 'ie' => 'less' ), $supertext['politeness'] );
			$this->assertSame( array( 'de' => true, 'en' => false, 'es' => true, 'ie' => false ), $supertext['review'] );
			$this->assertSame( 'k', $supertext['api_key'] );
			$this->assertSame( 'de', $clean['default_lang'] );
			$this->assertSame( 'other', Service::sanitize_saved_options( 'other' ) );
		}

		/**
		 * A fake Supertext API on wp_remote_request: records every call, echoes the uploaded
		 * document as its translation.
		 *
		 * @return array<int, array{method: string, url: string, agent: string}> The calls (by reference).
		 */
		private function fake_api(): array {
			$this->calls = array();
			$uploaded    = '';
			Functions\when( 'wp_remote_retrieve_headers' )->justReturn( array() );
			Functions\when( 'wp_remote_request' )->alias(
				function ( $url, $args ) use ( &$uploaded ) {
					$this->calls[] = array(
						'method' => (string) $args['method'],
						'url'    => (string) $url,
						'agent'  => (string) ( $args['headers']['User-Agent'] ?? '' ),
					);
					$body = '{}';
					if ( 'POST' === $args['method'] ) {
						preg_match( '#<!DOCTYPE.*</html>#si', (string) $args['body'], $m );
						$uploaded = $m[0] ?? '';
						$body     = '{"file_id":"f1"}';
					} elseif ( str_ends_with( (string) $url, '/status' ) ) {
						$body = '{"status":"done"}';
					} elseif ( str_ends_with( (string) $url, '/translation' ) ) {
						$body = $uploaded;
					}
					return array( 'response' => array( 'code' => 200 ), 'body' => $body );
				}
			);
			return $this->calls;
		}

		/** @var array<int, array{method: string, url: string, agent: string}> */
		private $calls = array();

		/**
		 * @param array<int, array{method: string, url: string, agent: string}> $calls
		 * @return array<string, list<string>> host => methods
		 */
		private static function by_host( array $calls ): array {
			$hosts = array();
			foreach ( $calls as $call ) {
				$hosts[ (string) parse_url( $call['url'], PHP_URL_HOST ) ][] = $call['method'];
			}
			return $hosts;
		}

		public function test_review_languages_go_through_the_review_service(): void {
			$this->fake_api();
			$client = new Client(
				array(
					'api_key'    => 'k',
					'endpoint'   => 'https://api.test/v1/',
					'review'     => array( 'de' => true, 'fr' => false ),
					'review_url' => 'https://review.test/v1/',
				)
			);
			$en = new \PLL_Language( 'en', 'English', 'en_US' );

			$de = $client->translate_strings( array( 'Hello' ), new \PLL_Language( 'de', 'Deutsch', 'de_CH' ), $en );
			$fr = $client->translate_strings( array( 'Hello' ), new \PLL_Language( 'fr', 'Français', 'fr_CH' ), $en );
			$it = $client->translate_strings( array( 'Hello' ), new \PLL_Language( 'it', 'Italiano', 'it_CH' ), $en );

			$this->assertSame( array( 'Hello' ), $de );
			$this->assertSame( array( 'Hello' ), $fr );
			$this->assertSame( array( 'Hello' ), $it );
			$hosts = self::by_host( $this->calls );
			$this->assertSame( array( 'POST', 'GET', 'GET', 'DELETE' ), $hosts['review.test'] ?? null, 'German: upload, status, download and delete through the review service' );
			$this->assertSame( array( 'POST', 'GET', 'GET', 'DELETE', 'POST', 'GET', 'GET', 'DELETE' ), $hosts['api.test'] ?? null, 'French (review off) and Italian (not set): directly to Supertext' );
			$this->assertStringStartsWith( 'https://review.test/v1/translate/ai/file', $this->calls[0]['url'] );
			$this->assertStringContainsString( 'supertext-wordpress-polylang/', $this->calls[0]['agent'], 'the plugin and its version in the User-Agent' );

			// The key check always goes directly to Supertext.
			$this->calls = array();
			$client->is_api_key_valid();
			$this->assertSame( array( 'api.test' ), array_keys( self::by_host( $this->calls ) ) );
		}

		public function test_empty_review_url_turns_review_off(): void {
			$this->fake_api();
			$de = new \PLL_Language( 'de', 'Deutsch', 'de_CH' );
			foreach ( array( '', 'https://api.test/v1' ) as $review_url ) {
				$client = new Client(
					array(
						'api_key'    => 'k',
						'endpoint'   => 'https://api.test/v1/',
						'review'     => array( 'de' => true ),
						'review_url' => $review_url,
					)
				);
				$this->assertSame( 'https://api.test/v1/', $client->route_for( $de ), var_export( $review_url, true ) );
			}
			$client->translate_strings( array( 'Hello' ), $de );
			$this->assertSame( array( 'api.test' ), array_keys( self::by_host( $this->calls ) ) );
		}

		public function test_the_default_is_supertexts_review_service(): void {
			putenv( 'SUPERTEXT_REVIEW_URL' );
			$this->assertSame( Client::REVIEW_URL, Client::review_url() );
			$this->assertSame( 'https://localizationreview-production.up.railway.app/v1/', Client::REVIEW_URL );
			$client = new Client( array( 'api_key' => 'k', 'review' => array( 'de' => true ) ) );
			$this->assertSame( Client::REVIEW_URL, $client->route_for( new \PLL_Language( 'de', 'Deutsch', 'de_CH' ) ) );
			$this->assertSame( Client::DEFAULT_ROUTE, $client->route_for( new \PLL_Language( 'fr', 'Français', 'fr_CH' ) ), 'review off by default' );

			putenv( 'SUPERTEXT_REVIEW_URL=' );
			try {
				$this->assertSame( '', Client::review_url(), 'SUPERTEXT_REVIEW_URL="" turns review off' );
				putenv( 'SUPERTEXT_REVIEW_URL=http://localhost:3000/v1/' );
				$this->assertSame( 'http://localhost:3000/v1/', Client::review_url() );
			} finally {
				putenv( 'SUPERTEXT_REVIEW_URL' );
			}
		}

		public function test_new_sdk_errors_have_messages(): void {
			$feature = Client::to_wp_error( new SupertextException( SupertextException::UNSUPPORTED_FEATURE, feature: 'politeness' ) );
			$this->assertSame( 'supertext_unsupported_feature', $feature->get_error_code() );
			$this->assertStringContainsString( 'politeness', $feature->get_error_message() );

			$auth = Client::to_wp_error( new SupertextException( SupertextException::AUTHENTICATION_FAILURE, status: 401 ) );
			$this->assertStringContainsString( 'https://www.supertext.com/person/en/account/signin', $auth->get_error_message() );
			$this->assertStringContainsString( 'https://www.supertext.com/en/integrations/api', $auth->get_error_message() );
		}
	}
}
