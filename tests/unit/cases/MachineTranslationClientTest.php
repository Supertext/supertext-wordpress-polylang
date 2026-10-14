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
			$result = $client->translate_strings( array( 'Hello' ), new \PLL_Language( 'de', 'Deutsch (Sie)', 'de_DE_formal' ), new \PLL_Language( 'en', 'English', 'en_US' ) );

			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertSame( 'supertext_unsupported_language', $result->get_error_code() );
			$this->assertStringContainsString( 'Deutsch (Sie) (de_DE_formal)', $result->get_error_message() );
			$this->assertStringContainsString( 'Language mapping', $result->get_error_message() );
			$this->assertFalse( $called, 'nothing is sent to Supertext' );
		}

		public function test_mapping_resolves_the_target(): void {
			$language = new \PLL_Language( 'de', 'Deutsch (Sie)', 'de_DE_formal' );

			$this->assertSame( 'de-DE', Service::resolve_target( $language, array( 'de' => 'de-DE' ) ) );
			$this->assertSame( 'de-CH', Service::resolve_target( new \PLL_Language( 'ch', 'Schweiz', 'de_CH' ), array() ) );

			$this->expectException( SupertextException::class );
			Service::resolve_target( $language, array( 'de' => '' ) );
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
