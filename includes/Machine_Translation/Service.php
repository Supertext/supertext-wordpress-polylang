<?php
/**
 * @package Supertext_Polylang
 */

namespace Supertext\Polylang\Machine_Translation;

defined( 'ABSPATH' ) || exit;

use PLL_Language;
use PLL_Model;
use WP_Syntex\Polylang_Pro\Modules\Machine_Translation\Clients\Client_Interface;
use WP_Syntex\Polylang_Pro\Modules\Machine_Translation\Services\Service_Interface;
use WP_Syntex\Polylang_Pro\Modules\Machine_Translation\Settings\Settings_Interface;

/**
 * Machine translation service: Supertext.
 *
 * Implements Polylang Pro's `Service_Interface` so Supertext appears as a
 * first-class MT service alongside DeepL. The heavy lifting happens in {@see Client}.
 *
 * @since 0.1.0
 */
class Service implements Service_Interface {
	/**
	 * Service's options (api_key, endpoint).
	 *
	 * @var array
	 */
	private $service_options;

	/**
	 * Polylang's model.
	 *
	 * @var PLL_Model
	 */
	private $model;

	/**
	 * Constructor.
	 *
	 * Polylang builds the service with `$options['machine_translation_services']['supertext']`,
	 * which may be missing (and thus `null`) until the option has been saved once — so
	 * we accept any value and coerce to an array rather than type-hinting `array`.
	 *
	 * @param mixed     $options Service's options (array, or null/missing before first save).
	 * @param PLL_Model $model   Polylang's model.
	 */
	public function __construct( $options, PLL_Model $model ) {
		$this->service_options = array_merge(
			array(
				'api_key'   => '',
				'endpoint'  => '',
				'languages' => array(),
			),
			is_array( $options ) ? $options : array()
		);
		$this->model = $model;
	}

	/**
	 * Tells if the service is active (selected & configured).
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return ! empty( $this->service_options['api_key'] );
	}

	/**
	 * Returns a unique identifier of the service.
	 *
	 * @return string
	 *
	 * @phpstan-return non-falsy-string
	 */
	public static function get_slug(): string {
		return 'supertext';
	}

	/**
	 * Returns the name of the service.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'Supertext';
	}

	/**
	 * Returns the svg properties of the service's logo.
	 *
	 * @return string[]
	 */
	public function get_icon_properties(): array {
		// Simple rounded-square "S" mark — replace with the official Supertext logo path.
		return array(
			'width'   => '20',
			'height'  => '20',
			'xmlns'   => 'http://www.w3.org/2000/svg',
			'viewBox' => '0 0 20 20',
			'path_d'  => 'M4 2.5h12A1.5 1.5 0 0 1 17.5 4v12A1.5 1.5 0 0 1 16 17.5H4A1.5 1.5 0 0 1 2.5 16V4A1.5 1.5 0 0 1 4 2.5Zm8.7 4.2c-.7-.5-1.6-.8-2.7-.8-1.9 0-3.2 1-3.2 2.5 0 1.4 1 2 2.7 2.4 1.3.3 1.7.5 1.7 1 0 .5-.5.8-1.3.8-.9 0-1.7-.4-2.3-1l-1.1 1.3c.8.8 2 1.3 3.3 1.3 2 0 3.4-1 3.4-2.6 0-1.5-1.1-2.1-2.8-2.5-1.2-.3-1.6-.4-1.6-.9 0-.4.4-.7 1.1-.7.8 0 1.5.3 2 .8l1.1-1.3Z',
		);
	}

	/**
	 * Returns the service's logo as an svg vector.
	 *
	 * @return string
	 */
	public function get_icon(): string {
		$p = $this->get_icon_properties();
		return sprintf(
			'<svg width="%s" height="%s" xmlns="%s" viewBox="%s"><path d="%s"/></svg>',
			$p['width'],
			$p['height'],
			$p['xmlns'],
			$p['viewBox'],
			$p['path_d']
		);
	}

	/**
	 * Returns the client that performs the translation.
	 *
	 * @return Client_Interface
	 */
	public function get_client(): Client_Interface {
		return new Client( $this->service_options );
	}

	/**
	 * Returns the object that prints the settings form.
	 *
	 * @param string $input_base_name Base of the name attribute used by the inputs.
	 * @return Settings_Interface
	 *
	 * @phpstan-param non-falsy-string $input_base_name
	 */
	public function get_settings( string $input_base_name ): Settings_Interface {
		return new Settings( $input_base_name, $this->service_options, $this, $this->model );
	}

	/**
	 * Returns the schema of the service's stored options.
	 *
	 * @return array
	 */
	public static function get_option_schema(): array {
		return array(
			'api_key'   => array(
				'type' => 'string',
			),
			'endpoint'  => array(
				'type' => 'string',
			),
			'languages' => array(
				'type'                 => 'object',
				// Map of Polylang language slug => Supertext language code.
				'additionalProperties' => array(
					'type' => 'string',
				),
			),
		);
	}

	/**
	 * Returns the suggested default Supertext code for a language.
	 *
	 * Used to pre-fill the language-mapping settings. Supertext uses BCP-47 codes
	 * (e.g. `de-CH`, `en-US`, `fr`), which matches Polylang's `PLL_Language::$w3c`
	 * tag. The authoritative value at translation time is the admin's explicit
	 * mapping (see {@see \Supertext\Polylang\Machine_Translation\Client}); this is
	 * only the fallback/suggestion.
	 *
	 * @param PLL_Language $language Language to check.
	 * @return string BCP-47 language code, empty if it cannot be determined.
	 */
	public static function get_default_code( PLL_Language $language ): string {
		$code = ! empty( $language->w3c ) ? $language->w3c : str_replace( '_', '-', $language->locale );

		/** @var string $code */
		$code = apply_filters( 'supertext_polylang_language_code', $code, $language );

		return (string) $code;
	}
}
