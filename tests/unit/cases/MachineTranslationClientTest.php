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
					),
				),
			);

			$clean    = Service::sanitize_saved_options( $value );
			$supertext = $clean['machine_translation_services']['supertext'];

			$this->assertSame( array( 'de' => 'de-CH', 'en' => 'en-US', 'es' => '', 'xx' => '', 'ie' => '' ), $supertext['languages'] );
			$this->assertSame( array( 'de' => 'more', 'en' => '', 'es' => '', 'ie' => 'less' ), $supertext['politeness'] );
			$this->assertSame( 'k', $supertext['api_key'] );
			$this->assertSame( 'de', $clean['default_lang'] );
			$this->assertSame( 'other', Service::sanitize_saved_options( 'other' ) );
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
