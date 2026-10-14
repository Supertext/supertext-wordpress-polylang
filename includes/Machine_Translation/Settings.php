<?php
/**
 * @package Supertext_Polylang
 */

namespace Supertext\Polylang\Machine_Translation;

defined( 'ABSPATH' ) || exit;

use PLL_Model;
use Supertext\Polylang\Reporting\Commando;
use Supertext\Polylang\Sdk\V1\ApiKeyCheck;
use Supertext\Polylang\Sdk\V1\SupertextException;
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
	 * Polylang calls this when its settings are saved, so this is the plugin's
	 * "settings saved" moment: the Commando Center's automatic key check runs
	 * (cost-free `GET features`) and, unless switched off, the version report is
	 * sent (see Reporting\Commando). The result is shown with the center's message.
	 *
	 * @param array $options Options for this service (already sanitized).
	 * @return WP_Error Empty on success; otherwise carries a `field_id` for the faulty field.
	 */
	public function is_api_key_valid( array $options ): WP_Error {
		$api_key = $this->has_api_key( $options ) ? (string) $options['api_key'] : '';
		$check   = Commando::after_settings_saved( $api_key, (string) ( $options['endpoint'] ?? '' ) );

		if ( null === $check ) {
			// Already checked in this request, or the SDK failed: the plain check.
			$error = ( new Service(
				array(
					'api_key'  => $api_key,
					'endpoint' => $options['endpoint'] ?? '',
				),
				$this->model
			) )->get_client()->is_api_key_valid();
			if ( $error->has_errors() ) {
				$error->add_data(
					array(
						'type'     => 'supertext_authentication_failure' === $error->get_error_code() ? 'error' : 'warning',
						'field_id' => 'pll-supertext-api-key',
					)
				);
			}
			return $error;
		}

		if ( ApiKeyCheck::OK === $check->status ) {
			return new WP_Error();
		}

		$message = Commando::message( $check, false );

		return new WP_Error(
			'supertext_key_check_' . $check->status,
			$message['text'],
			array(
				'type'     => 'error' === $message['type'] ? 'error' : 'warning',
				'field_id' => 'pll-supertext-api-key',
			)
		);
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

		$check = Commando::last_check();
		if ( null !== $check ) {
			$message = Commando::message( $check, true );
			printf(
				'<div class="notice notice-%s inline"><p>%s <small>(%s)</small></p></div>',
				esc_attr( $message['type'] ),
				wp_kses( $message['text'], array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) ) ),
				esc_html( self::checked_at( $check ) )
			);
		}
	}

	/**
	 * When a key check ran, in the site's date and time format.
	 *
	 * @param ApiKeyCheck $check The check.
	 * @return string
	 */
	public static function checked_at( ApiKeyCheck $check ): string {
		$time = strtotime( $check->checkedAt );
		if ( false === $time ) {
			return $check->checkedAt;
		}
		/* translators: %s: date and time of the last API key check. */
		return sprintf( __( 'checked %s', 'supertext-polylang' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time ) );
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
					<?php
					printf(
						/* translators: 1: link to create a Supertext account (or log in), 2: link to the Supertext API integrations page. */
						esc_html__( 'Your Supertext API key. The service becomes active once a key is saved. No Supertext account yet? Create one at %1$s. Generate your API key at %2$s (requires the Admin role).', 'supertext-polylang' ),
						sprintf(
							'<a href="%s" target="_blank" rel="noopener">%s</a>',
							esc_url( 'https://www.supertext.com/person/en/account/signin' ),
							esc_html__( 'supertext.com', 'supertext-polylang' )
						),
						sprintf(
							'<a href="%s" target="_blank" rel="noopener">%s</a>',
							esc_url( 'https://www.supertext.com/en/integrations/api' ),
							esc_html__( 'supertext.com → Integrations → API', 'supertext-polylang' )
						)
					); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- link built from escaped parts.
					?>
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="pll-supertext-endpoint"><?php esc_html_e( 'Environment', 'supertext-polylang' ); ?></label></th>
			<td>
				<select name="<?php echo esc_attr( $this->input_base_name . '[endpoint]' ); ?>" id="pll-supertext-endpoint">
					<?php foreach ( Client::ENVIRONMENTS as $key => $url ) : ?>
						<?php $is_selected = ( $endpoint === $url ) || ( '' === $endpoint && 'live' === $key ); ?>
						<option value="<?php echo esc_attr( $url ); ?>" <?php selected( $is_selected, true ); ?>>
							<?php echo esc_html( ucfirst( $key ) . ' (' . preg_replace( '#^https?://#', '', rtrim( $url, '/' ) ) . ')' ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<p class="description"><?php esc_html_e( 'Supertext environment for AI machine translation.', 'supertext-polylang' ); ?></p>
			</td>
		</tr>
		<?php
		$this->print_language_mapping();
	}

	/**
	 * Prints the Polylang language → Supertext language mapping table.
	 *
	 * Each Polylang language gets a drop-down of the languages Supertext offers.
	 * "Automatic" uses the language whose code equals the locale (`de_CH` →
	 * `de-CH`); locales without one (`de_DE_formal`, `ca_valencia`, a bare `fi`)
	 * must be mapped, otherwise translations into or from them stop with
	 * `unsupported_language`. The status column says which. Formal/informal is
	 * offered only where Supertext supports it for the chosen language.
	 *
	 * @return void
	 */
	private function print_language_mapping() {
		$mapping    = isset( $this->options['languages'] ) && is_array( $this->options['languages'] ) ? $this->options['languages'] : array();
		$politeness = isset( $this->options['politeness'] ) && is_array( $this->options['politeness'] ) ? $this->options['politeness'] : array();
		$languages  = $this->model->get_languages_list();
		$targets    = Language_Codes::targets();
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Language mapping', 'supertext-polylang' ); ?></th>
			<td>
				<p class="description" style="margin-bottom:8px;">
					<?php esc_html_e( 'Choose the Supertext language for each Polylang language. "Automatic" uses the Supertext language whose code equals the locale (de_CH → de-CH). Locales without one, such as de_DE_formal or a bare fi, must be mapped here; until then, translations into or from them stop with an error and nothing is sent to Supertext.', 'supertext-polylang' ); ?>
				</p>
				<table class="widefat striped" style="max-width:760px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Polylang language', 'supertext-polylang' ); ?></th>
							<th><?php esc_html_e( 'Supertext language', 'supertext-polylang' ); ?></th>
							<th><?php esc_html_e( 'Formality', 'supertext-polylang' ); ?></th>
							<th><?php esc_html_e( 'Status', 'supertext-polylang' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $languages as $language ) : ?>
							<?php
							$slug     = $language->slug;
							$value    = trim( (string) ( $mapping[ $slug ] ?? '' ) );
							$match    = Language_Codes::resolve( $language->locale );
							try {
								$resolved = Service::resolve_target( $language, $mapping );
							} catch ( SupertextException $e ) {
								$resolved = null;
							}
							$polite   = (string) ( $politeness[ $slug ] ?? '' );
							$offer    = null !== $resolved && Language_Codes::supports_politeness( $resolved );
							?>
							<tr>
								<td>
									<label for="<?php echo esc_attr( 'pll-supertext-lang-' . $slug ); ?>">
										<?php echo esc_html( $language->name ); ?>
										<code><?php echo esc_html( $language->locale ); ?></code>
									</label>
								</td>
								<td>
									<select
										name="<?php echo esc_attr( $this->input_base_name . '[languages][' . $slug . ']' ); ?>"
										id="<?php echo esc_attr( 'pll-supertext-lang-' . $slug ); ?>"
									>
										<option value="" <?php selected( $value, '' ); ?>>
											<?php
											echo esc_html(
												null !== $match
													/* translators: %s: the Supertext language code that equals the locale, e.g. de-CH. */
													? sprintf( __( 'Automatic (%s)', 'supertext-polylang' ), $match )
													: __( 'Automatic (no match: choose a language)', 'supertext-polylang' )
											);
											?>
										</option>
										<?php if ( '' !== $value && ! isset( $targets[ $value ] ) ) : ?>
											<option value="<?php echo esc_attr( $value ); ?>" selected="selected"><?php echo esc_html( $value ); ?></option>
										<?php endif; ?>
										<?php foreach ( $targets as $code => $name ) : ?>
											<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $value, $code ); ?>><?php echo esc_html( $code . ' – ' . $name ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
								<td>
									<?php if ( $offer ) : ?>
										<select name="<?php echo esc_attr( $this->input_base_name . '[politeness][' . $slug . ']' ); ?>" aria-label="<?php esc_attr_e( 'Formality', 'supertext-polylang' ); ?>">
											<option value="" <?php selected( $polite, '' ); ?>><?php esc_html_e( 'Automatic', 'supertext-polylang' ); ?></option>
											<option value="more" <?php selected( $polite, 'more' ); ?>><?php esc_html_e( 'Formal', 'supertext-polylang' ); ?></option>
											<option value="less" <?php selected( $polite, 'less' ); ?>><?php esc_html_e( 'Informal', 'supertext-polylang' ); ?></option>
										</select>
									<?php else : ?>
										<input type="hidden" name="<?php echo esc_attr( $this->input_base_name . '[politeness][' . $slug . ']' ); ?>" value="" />
										<span class="description">&ndash;</span>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( null !== $resolved ) : ?>
										<span style="color:#008a20;">&#10003; <?php echo esc_html( $resolved ); ?></span>
									<?php else : ?>
										<strong style="color:#d63638;"><?php esc_html_e( 'Not mapped: Supertext can\'t translate into or from this language yet', 'supertext-polylang' ); ?></strong>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description" style="margin-top:8px;">
					<?php esc_html_e( 'Formality is offered only for the languages where Supertext supports it. "Automatic" uses formal for locales ending in _formal, informal for _informal, and lets Supertext decide otherwise. Save once after changing a language to see the formality choice for it.', 'supertext-polylang' ); ?>
				</p>
			</td>
		</tr>
		<?php
	}
}
