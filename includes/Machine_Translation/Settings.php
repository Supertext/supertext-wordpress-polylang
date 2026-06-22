<?php
/**
 * @package Supertext_Polylang
 */

namespace Supertext\Polylang\Machine_Translation;

defined( 'ABSPATH' ) || exit;

use PLL_Model;
use WP_Error;
use WP_Syntex\Polylang_Pro\Modules\Machine_Translation\Settings\Settings_Interface;

/**
 * Machine translation settings: Supertext.
 *
 * Renders a self-contained settings form (API key + optional endpoint override).
 * Kept deliberately free of Polylang's AJAX views so it has no dependency on
 * DeepL-specific markup; the API key is all that's required for the service to
 * become active.
 *
 * @since 0.1.0
 */
class Settings implements Settings_Interface {
	/**
	 * Service.
	 *
	 * @var Service
	 */
	private $service;

	/**
	 * Polylang's model.
	 *
	 * @var PLL_Model
	 */
	private $model;

	/**
	 * Base of the name attribute used by the inputs, e.g. `machine_translation_services[supertext]`.
	 *
	 * @var string
	 */
	private $input_base_name;

	/**
	 * Service's stored options.
	 *
	 * @var array
	 */
	private $options;

	/**
	 * Constructor.
	 *
	 * @param string    $input_base_name Base of the name attribute used by the inputs. May contain `{slug}`.
	 * @param array     $options         Service's options.
	 * @param Service   $service         Service.
	 * @param PLL_Model $model           Polylang's model.
	 */
	public function __construct( string $input_base_name, array $options, Service $service, PLL_Model $model ) {
		$this->service         = $service;
		$this->model           = $model;
		$this->input_base_name = str_replace( '{slug}', $service::get_slug(), $input_base_name );
		$this->options         = $options;
	}

	/**
	 * Tells if the given service options contain a non-empty authentication key.
	 *
	 * @param array $options Options for this service.
	 * @return bool
	 */
	public function has_api_key( array $options ): bool {
		return ! empty( $options['api_key'] ) && is_string( $options['api_key'] ) && '' !== trim( $options['api_key'] );
	}

	/**
	 * Tells if the authentication key is valid by contacting the service.
	 *
	 * @param array $options Options for this service (already sanitized).
	 * @return WP_Error Empty on success; otherwise carries a `field_id` for the faulty field.
	 */
	public function is_api_key_valid( array $options ): WP_Error {
		if ( ! $this->has_api_key( $options ) ) {
			$options['api_key'] = '';
		}

		$client = ( new Service(
			array(
				'api_key'  => $options['api_key'],
				'endpoint' => $options['endpoint'] ?? '',
			),
			$this->model
		) )->get_client();

		$error = $client->is_api_key_valid();

		if ( ! $error->has_errors() ) {
			return $error;
		}

		$error->add_data(
			array(
				'type'     => 'supertext_authentication_failure' === $error->get_error_code() ? 'error' : 'warning',
				'field_id' => 'pll-supertext-api-key',
			)
		);

		return $error;
	}

	/**
	 * Prints the section heading.
	 *
	 * Polylang renders each service's fields one after another with no separation.
	 * This callback runs just before our settings table opens, so we use it to add a
	 * titled, spaced "Supertext" section header (with the logo) to mark where the
	 * Supertext settings begin.
	 *
	 * @return void
	 */
	public function print_notices() {
		printf(
			'<h2 class="title" style="margin-top:2.5em;padding-top:1.5em;border-top:1px solid #dcdcde;display:flex;align-items:center;gap:8px;">%s<span>%s</span></h2>',
			$this->service->get_icon(), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- safe markup built by the service.
			esc_html( $this->service->get_name() )
		);
	}

	/**
	 * Prints the settings fields.
	 *
	 * @return void
	 */
	public function print_settings_fields() {
		$api_key  = (string) ( $this->options['api_key'] ?? '' );
		$endpoint = (string) ( $this->options['endpoint'] ?? '' );
		?>
		<tr>
			<th scope="row"><label for="pll-supertext-api-key"><?php esc_html_e( 'API key', 'supertext-polylang' ); ?></label></th>
			<td>
				<input
					name="<?php echo esc_attr( $this->input_base_name . '[api_key]' ); ?>"
					id="pll-supertext-api-key"
					type="password"
					autocomplete="off"
					class="regular-text"
					value="<?php echo esc_attr( $api_key ); ?>"
				/>
				<p class="description">
					<?php esc_html_e( 'Your Supertext API key. The service becomes active once a key is saved.', 'supertext-polylang' ); ?>
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="pll-supertext-endpoint"><?php esc_html_e( 'API endpoint', 'supertext-polylang' ); ?></label></th>
			<td>
				<input
					name="<?php echo esc_attr( $this->input_base_name . '[endpoint]' ); ?>"
					id="pll-supertext-endpoint"
					type="url"
					class="regular-text"
					placeholder="<?php echo esc_attr( Client::DEFAULT_ROUTE ); ?>"
					value="<?php echo esc_attr( $endpoint ); ?>"
				/>
				<p class="description">
					<?php esc_html_e( 'Optional. Override the translation API base URL. Leave empty to use the default.', 'supertext-polylang' ); ?>
				</p>
			</td>
		</tr>
		<?php
		$this->print_language_mapping();
	}

	/**
	 * Prints the Polylang language → Supertext code mapping table.
	 *
	 * Each Polylang language gets one input. Leaving a field empty falls back to
	 * the default (BCP-47) code at translation time.
	 *
	 * @return void
	 */
	private function print_language_mapping() {
		$mapping   = isset( $this->options['languages'] ) && is_array( $this->options['languages'] ) ? $this->options['languages'] : array();
		$languages = $this->model->get_languages_list();
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Language mapping', 'supertext-polylang' ); ?></th>
			<td>
				<p class="description" style="margin-bottom:8px;">
					<?php esc_html_e( 'Map each Polylang language to the code Supertext expects. Leave a field empty to use the suggested default.', 'supertext-polylang' ); ?>
				</p>
				<table class="widefat striped" style="max-width:520px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Polylang language', 'supertext-polylang' ); ?></th>
							<th><?php esc_html_e( 'Supertext code', 'supertext-polylang' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $languages as $language ) : ?>
							<?php
							$slug    = $language->slug;
							$value   = (string) ( $mapping[ $slug ] ?? '' );
							$default = Service::get_default_code( $language );
							?>
							<tr>
								<td>
									<label for="<?php echo esc_attr( 'pll-supertext-lang-' . $slug ); ?>">
										<?php echo esc_html( $language->name ); ?>
										<code><?php echo esc_html( $language->locale ); ?></code>
									</label>
								</td>
								<td>
									<input
										name="<?php echo esc_attr( $this->input_base_name . '[languages][' . $slug . ']' ); ?>"
										id="<?php echo esc_attr( 'pll-supertext-lang-' . $slug ); ?>"
										type="text"
										class="small-text"
										value="<?php echo esc_attr( $value ); ?>"
										placeholder="<?php echo esc_attr( $default ); ?>"
									/>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</td>
		</tr>
		<?php
	}
}
