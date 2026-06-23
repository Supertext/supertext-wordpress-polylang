<?php
/**
 * @package Supertext_Polylang
 */

namespace Supertext\Polylang\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin-owned settings for the Supertext human / professional translation order
 * API — environment + Basic-auth credentials (account email + Legacy API Key).
 *
 * Stored in the plugin's own option (not in Polylang's machine-translation
 * settings, which only hold the AI service config). Rendered on the Supertext
 * admin page via the WordPress Settings API.
 *
 * @since 0.5.0
 */
class Settings {
	/**
	 * Option name.
	 *
	 * @var string
	 */
	const OPTION = 'supertext_polylang_settings';

	/**
	 * Settings group / section.
	 *
	 * @var string
	 */
	const GROUP = 'supertext_polylang_settings_group';

	/**
	 * Supertext environment slug => base URL.
	 *
	 * @var array<string, string>
	 */
	const ENVIRONMENTS = array(
		'live'    => 'https://www.supertext.com/',
		'staging' => 'https://staging.supertext.com/',
		'testing' => 'https://testing.supertext.com/',
	);

	/**
	 * Registers the setting.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_init', array( self::class, 'register' ) );
	}

	/**
	 * Registers the option with the Settings API.
	 *
	 * @return void
	 */
	public static function register(): void {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Default values.
	 *
	 * @return array{environment: string, human_email: string, human_api_key: string}
	 */
	public static function defaults(): array {
		return array(
			'environment'   => 'live',
			'human_email'   => '',
			'human_api_key' => '',
		);
	}

	/**
	 * Sanitizes the submitted settings.
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public static function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();
		$out   = self::defaults();

		$env = (string) ( $input['environment'] ?? 'live' );
		$out['environment'] = isset( self::ENVIRONMENTS[ $env ] ) ? $env : 'live';

		$out['human_email']   = sanitize_email( (string) ( $input['human_email'] ?? '' ) );
		$out['human_api_key'] = sanitize_text_field( (string) ( $input['human_api_key'] ?? '' ) );

		return $out;
	}

	/**
	 * Returns the stored settings, merged with defaults.
	 *
	 * @return array
	 */
	public static function get(): array {
		$stored = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Returns the configured environment slug.
	 *
	 * @return string
	 */
	public static function environment(): string {
		$env = self::get()['environment'];
		return isset( self::ENVIRONMENTS[ $env ] ) ? $env : 'live';
	}

	/**
	 * Returns the base URL of the configured environment.
	 *
	 * @return string
	 */
	public static function base_url(): string {
		return self::ENVIRONMENTS[ self::environment() ];
	}

	/**
	 * Returns the URL of an order's detail page in the configured environment,
	 * e.g. https://www.supertext.com/en/orders/737522.
	 *
	 * @param int $order_id The order id.
	 * @return string
	 */
	public static function order_url( int $order_id ): string {
		/** @var string $locale */
		$locale = apply_filters( 'supertext_polylang_order_url_locale', 'en' );
		return self::base_url() . $locale . '/orders/' . $order_id;
	}

	/**
	 * Returns the human/order API account email.
	 *
	 * @return string
	 */
	public static function email(): string {
		return (string) self::get()['human_email'];
	}

	/**
	 * Returns the human/order API Legacy API Key.
	 *
	 * @return string
	 */
	public static function api_key(): string {
		return (string) self::get()['human_api_key'];
	}

	/**
	 * Tells whether the human/order API is configured (email + key present).
	 *
	 * @return bool
	 */
	public static function is_configured(): bool {
		return '' !== self::email() && '' !== self::api_key();
	}

	/**
	 * Renders the settings form (for the Supertext admin page).
	 *
	 * @return void
	 */
	public static function render_form(): void {
		$current = self::get();
		?>
		<form method="post" action="options.php">
			<?php settings_fields( self::GROUP ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="supertext-environment"><?php esc_html_e( 'Environment', 'supertext-polylang' ); ?></label></th>
					<td>
						<select name="<?php echo esc_attr( self::OPTION . '[environment]' ); ?>" id="supertext-environment">
							<?php
							$labels = array(
								'live'    => __( 'Live (www.supertext.com)', 'supertext-polylang' ),
								'staging' => __( 'Staging (staging.supertext.com)', 'supertext-polylang' ),
								'testing' => __( 'Testing (testing.supertext.com)', 'supertext-polylang' ),
							);
							foreach ( $labels as $value => $label ) :
								?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current['environment'], $value ); ?>>
									<?php echo esc_html( $label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Supertext environment used for professional (human) translation orders.', 'supertext-polylang' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="supertext-email"><?php esc_html_e( 'Account email', 'supertext-polylang' ); ?></label></th>
					<td>
						<input
							name="<?php echo esc_attr( self::OPTION . '[human_email]' ); ?>"
							id="supertext-email"
							type="email"
							autocomplete="off"
							class="regular-text"
							value="<?php echo esc_attr( $current['human_email'] ); ?>"
						/>
						<p class="description"><?php esc_html_e( 'The email (username) of your Supertext account.', 'supertext-polylang' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="supertext-legacy-key"><?php esc_html_e( 'Legacy API Key', 'supertext-polylang' ); ?></label></th>
					<td>
						<input
							name="<?php echo esc_attr( self::OPTION . '[human_api_key]' ); ?>"
							id="supertext-legacy-key"
							type="password"
							autocomplete="off"
							class="regular-text"
							value="<?php echo esc_attr( $current['human_api_key'] ); ?>"
						/>
						<p class="description"><?php esc_html_e( 'Your Supertext "Legacy API Key" (used with the account email, via HTTP Basic auth, for order requests).', 'supertext-polylang' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save human translation settings', 'supertext-polylang' ) ); ?>
		</form>
		<?php
	}
}
