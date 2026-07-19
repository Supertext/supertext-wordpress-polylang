<?php
/**
 * @package Supertext_Polylang
 */

namespace Supertext\Polylang\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin-owned settings for the Supertext human / professional translation order
 * API — environment + Basic-auth credentials (account email + Order API Key).
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
			'environment'               => 'live',
			'human_email'               => '',
			'human_api_key'             => '',
			'allow_multiple_writebacks' => false,
			'writeback_status'          => 'draft',
			'screenshots_enabled'       => true,
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

		$out['allow_multiple_writebacks'] = ! empty( $input['allow_multiple_writebacks'] );

		$status                  = (string) ( $input['writeback_status'] ?? 'draft' );
		$out['writeback_status'] = in_array( $status, array( 'draft', 'publish' ), true ) ? $status : 'draft';

		$out['screenshots_enabled'] = ! empty( $input['screenshots_enabled'] );

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
	 * Returns the URL of the customer's orders overview in the configured
	 * environment, e.g. https://www.supertext.com/en/orders.
	 *
	 * @return string
	 */
	public static function orders_url(): string {
		/** @var string $locale */
		$locale = apply_filters( 'supertext_polylang_order_url_locale', 'en' );
		return self::base_url() . $locale . '/orders';
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
	 * Returns the Order API Key.
	 *
	 * @return string
	 */
	public static function api_key(): string {
		return (string) self::get()['human_api_key'];
	}

	/**
	 * Whether a translation may be written back more than once (re-applied on each
	 * callback). When false, the first write-back wins and later ones are skipped.
	 *
	 * @return bool
	 */
	public static function allow_multiple_writebacks(): bool {
		return (bool) self::get()['allow_multiple_writebacks'];
	}

	/**
	 * The post status applied to a translation when it is written back.
	 *
	 * @return string 'draft' or 'publish'.
	 */
	public static function writeback_status(): string {
		$status = (string) self::get()['writeback_status'];
		return in_array( $status, array( 'draft', 'publish' ), true ) ? $status : 'draft';
	}

	/**
	 * Whether VibeBoost Screenshots is enabled — attach a page screenshot to each
	 * human-translation order for the translator's visual reference.
	 *
	 * @return bool
	 */
	public static function screenshots_enabled(): bool {
		return (bool) self::get()['screenshots_enabled'];
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
					<th scope="row"><label for="supertext-api-key"><?php esc_html_e( 'Order API Key', 'supertext-polylang' ); ?></label></th>
					<td>
						<input
							name="<?php echo esc_attr( self::OPTION . '[human_api_key]' ); ?>"
							id="supertext-api-key"
							type="password"
							autocomplete="off"
							class="regular-text"
							value="<?php echo esc_attr( $current['human_api_key'] ); ?>"
						/>
						<p class="description">
							<?php
							printf(
								/* translators: %s is a link to the Supertext account settings page. */
								esc_html__( 'Your Supertext "Order API Key" (used with the account email, via HTTP Basic auth, for order requests). Find it in your %s.', 'supertext-polylang' ),
								sprintf(
									'<a href="%s" target="_blank" rel="noopener">%s</a>',
									esc_url( self::base_url() . 'services/customer/accountsettings' ),
									esc_html__( 'Supertext account settings', 'supertext-polylang' )
								)
							); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- link built from escaped parts.
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Write-back', 'supertext-polylang' ); ?></th>
					<td>
						<label for="supertext-allow-multiple">
							<input
								type="checkbox"
								name="<?php echo esc_attr( self::OPTION . '[allow_multiple_writebacks]' ); ?>"
								id="supertext-allow-multiple"
								value="1"
								<?php checked( ! empty( $current['allow_multiple_writebacks'] ) ); ?>
							/>
							<?php esc_html_e( 'Allow multiple write-backs', 'supertext-polylang' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Re-apply the translation each time a completed-order callback arrives. When off, only the first write-back is applied (later callbacks are ignored), protecting manual edits.', 'supertext-polylang' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="supertext-writeback-status"><?php esc_html_e( 'Translation status', 'supertext-polylang' ); ?></label></th>
					<td>
						<select name="<?php echo esc_attr( self::OPTION . '[writeback_status]' ); ?>" id="supertext-writeback-status">
							<option value="draft" <?php selected( $current['writeback_status'], 'draft' ); ?>><?php esc_html_e( 'Draft', 'supertext-polylang' ); ?></option>
							<option value="publish" <?php selected( $current['writeback_status'], 'publish' ); ?>><?php esc_html_e( 'Published', 'supertext-polylang' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Status applied to the translated post when a translation is written back.', 'supertext-polylang' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Page screenshots', 'supertext-polylang' ); ?></th>
					<td>
						<label for="supertext-screenshots">
							<input
								type="checkbox"
								name="<?php echo esc_attr( self::OPTION . '[screenshots_enabled]' ); ?>"
								id="supertext-screenshots"
								value="1"
								<?php checked( ! empty( $current['screenshots_enabled'] ) ); ?>
							/>
							<?php esc_html_e( 'Attach a page screenshot to each human-translation order (VibeBoost Screenshots)', 'supertext-polylang' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'When enabled, each page sent for human translation is captured through its secret preview link and uploaded to the order as a visual reference for the translator. Powered by VibeBoost Screenshots (still in development); heavier use may require a subscription. Best-effort — an order still goes through if the screenshot cannot be produced.', 'supertext-polylang' ); ?>
						</p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save human translation settings', 'supertext-polylang' ) ); ?>
		</form>
		<?php
	}
}
