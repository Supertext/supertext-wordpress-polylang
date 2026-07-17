<?php
/**
 * @package Supertext_Polylang
 */

namespace Supertext\Polylang\Admin;

defined( 'ABSPATH' ) || exit;

use Supertext\Polylang\Human_Translation\Callback;
use Supertext\Polylang\Machine_Translation\Service;

/**
 * The "Supertext" admin pages: Settings (with the status panel merged in),
 * Orders and Debug.
 *
 * @since 0.2.0
 */
class Page {
	/**
	 * Top-level / Settings page slug.
	 *
	 * @var string
	 */
	const SLUG = 'supertext-polylang';

	/**
	 * Settings page slug. Kept as an alias of {@see self::SLUG} for back-compat
	 * now that Status is merged into the (top-level) Settings page.
	 *
	 * @var string
	 */
	const SETTINGS_SLUG = self::SLUG;

	/**
	 * Debug page slug.
	 *
	 * @var string
	 */
	const DEBUG_SLUG = 'supertext-polylang-debug';

	/**
	 * admin-post action name for applying the patch.
	 *
	 * @var string
	 */
	const PATCH_ACTION = 'supertext_polylang_apply_patch';

	/**
	 * admin-post action name for clearing the callback log.
	 *
	 * @var string
	 */
	const CLEAR_LOG_ACTION = 'supertext_polylang_clear_callback_log';

	/**
	 * Transient key for one-off admin notices.
	 *
	 * @var string
	 */
	const NOTICE_TRANSIENT = 'supertext_polylang_notice';

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( self::class, 'register_menu' ), 10 );
		add_action( 'admin_menu', array( self::class, 'register_debug_menu' ), 12 );
		add_action( 'admin_post_' . self::PATCH_ACTION, array( self::class, 'handle_patch' ) );
		add_action( 'admin_post_' . self::CLEAR_LOG_ACTION, array( self::class, 'handle_clear_log' ) );
	}

	/**
	 * Registers the top-level menu and its Settings submenu.
	 *
	 * Status is merged into the Settings page, so the top-level page *is* the
	 * Settings page (the first submenu is just renamed to "Settings").
	 *
	 * @return void
	 */
	public static function register_menu(): void {
		add_menu_page(
			__( 'Supertext', 'supertext-polylang' ),
			__( 'Supertext', 'supertext-polylang' ),
			'manage_options',
			self::SLUG,
			array( self::class, 'render_settings' ),
			self::menu_icon(),
			81
		);

		add_submenu_page(
			self::SLUG,
			__( 'Settings', 'supertext-polylang' ),
			__( 'Settings', 'supertext-polylang' ),
			'manage_options',
			self::SLUG,
			array( self::class, 'render_settings' )
		);
	}

	/**
	 * Registers the Debug submenu (later, so it appears after Orders).
	 *
	 * @return void
	 */
	public static function register_debug_menu(): void {
		add_submenu_page(
			self::SLUG,
			__( 'Debug', 'supertext-polylang' ),
			__( 'Debug', 'supertext-polylang' ),
			'manage_options',
			self::DEBUG_SLUG,
			array( self::class, 'render_debug' )
		);
	}

	/**
	 * Returns the admin-menu icon (base64 SVG data URI).
	 *
	 * @return string
	 */
	private static function menu_icon(): string {
		if ( defined( 'SUPERTEXT_POLYLANG_DIR' ) ) {
			$svg = SUPERTEXT_POLYLANG_DIR . 'assets/Supertext_S_Glow_White_RGB.svg';
			if ( is_readable( $svg ) ) {
				$data = file_get_contents( $svg ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				if ( is_string( $data ) && '' !== $data ) {
					return 'data:image/svg+xml;base64,' . base64_encode( $data ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
				}
			}
		}

		return 'dashicons-translation';
	}

	/**
	 * Handles the "Patch Polylang" form submission.
	 *
	 * @return void
	 */
	public static function handle_patch(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'supertext-polylang' ) );
		}
		check_admin_referer( self::PATCH_ACTION );

		$result = Patch::apply();

		$notice = is_wp_error( $result )
			? array( 'type' => 'error', 'text' => $result->get_error_message() )
			: array( 'type' => 'success', 'text' => __( 'Polylang was patched successfully. Supertext can now register as a machine-translation service.', 'supertext-polylang' ) );

		set_transient( self::NOTICE_TRANSIENT . '_' . get_current_user_id(), $notice, 60 );

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ) );
		exit;
	}

	/**
	 * Clears the recorded callback log.
	 *
	 * @return void
	 */
	public static function handle_clear_log(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'supertext-polylang' ) );
		}
		check_admin_referer( self::CLEAR_LOG_ACTION );

		Callback::clear_log();

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::DEBUG_SLUG ) );
		exit;
	}

	/**
	 * Returns the URL of Polylang's settings page.
	 *
	 * @return string
	 */
	private static function polylang_settings_url(): string {
		return admin_url( 'admin.php?page=mlang_settings' );
	}

	/**
	 * Prints the page header (logo + title).
	 *
	 * @param string $title Page title.
	 * @return void
	 */
	private static function header( string $title ): void {
		$logo = defined( 'SUPERTEXT_POLYLANG_FILE' ) ? plugins_url( 'assets/icon-v2-64.png', SUPERTEXT_POLYLANG_FILE ) : '';
		echo '<h1 style="display:flex;align-items:center;gap:10px;">';
		if ( $logo ) {
			printf( '<img src="%s" width="32" height="32" alt="" style="border-radius:6px;" />', esc_url( $logo ) );
		}
		echo esc_html( $title ) . '</h1>';
	}

	/**
	 * Prints the status panel (Polylang / patch / service health).
	 *
	 * @return void
	 */
	private static function render_status_panel(): void {
		$polylang = Patch::polylang_available();
		$patched  = $polylang && Patch::is_patched();
		$active   = self::service_is_active();
		?>
		<h2><?php esc_html_e( 'Status', 'supertext-polylang' ); ?></h2>
		<table class="widefat striped" style="max-width:640px;">
			<tbody>
				<?php
				self::status_row(
					__( 'Polylang Pro (Machine Translation)', 'supertext-polylang' ),
					$polylang,
					$polylang ? __( 'Active', 'supertext-polylang' ) : __( 'Not active', 'supertext-polylang' )
				);
				self::status_row(
					__( 'Polylang patched (pll_mt_services filter)', 'supertext-polylang' ),
					$patched,
					$patched ? __( 'Patched', 'supertext-polylang' ) : __( 'Not patched', 'supertext-polylang' )
				);
				self::status_row(
					__( 'Supertext service configured & active', 'supertext-polylang' ),
					$active,
					$active ? __( 'Active', 'supertext-polylang' ) : __( 'Inactive (enter an API key in Polylang settings)', 'supertext-polylang' )
				);
				?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Settings page: status panel, setup (patch + configure) and human
	 * translation services.
	 *
	 * @return void
	 */
	public static function render_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$polylang = Patch::polylang_available();
		$patched  = $polylang && Patch::is_patched();

		// One-off notice from the patch handler.
		$notice_key = self::NOTICE_TRANSIENT . '_' . get_current_user_id();
		$notice     = get_transient( $notice_key );
		if ( is_array( $notice ) ) {
			delete_transient( $notice_key );
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				esc_attr( 'error' === $notice['type'] ? 'error' : 'success' ),
				esc_html( $notice['text'] )
			);
		}
		?>
		<div class="wrap">
			<?php
			self::header( __( 'Supertext for Polylang', 'supertext-polylang' ) );
			self::render_status_panel();
			?>

			<h2 style="margin-top:2em;"><?php esc_html_e( 'Setup', 'supertext-polylang' ); ?></h2>

			<p>
				<strong><?php esc_html_e( '1. Patch Polylang', 'supertext-polylang' ); ?></strong><br />
				<?php esc_html_e( 'Polylang Pro keeps its translation services in a fixed list. This adds a small filter so Supertext can register itself. It backs up the original file and is safe to run again after a Polylang update.', 'supertext-polylang' ); ?>
			</p>
			<?php if ( ! $polylang ) : ?>
				<p><em><?php esc_html_e( 'Activate Polylang Pro first.', 'supertext-polylang' ); ?></em></p>
			<?php elseif ( $patched ) : ?>
				<p style="margin-bottom:1em;">✅ <?php esc_html_e( 'Polylang is already patched.', 'supertext-polylang' ); ?></p>
				<?php self::patch_form( __( 'Re-apply patch', 'supertext-polylang' ), 'secondary' ); ?>
			<?php else : ?>
				<?php self::patch_form( __( 'Patch Polylang', 'supertext-polylang' ), 'primary' ); ?>
			<?php endif; ?>

			<p style="margin-top:2em;">
				<strong><?php esc_html_e( '2. Configure the AI service', 'supertext-polylang' ); ?></strong><br />
				<?php esc_html_e( 'Enable Machine Translation, choose Supertext, enter your API key, and map your languages in the Polylang settings.', 'supertext-polylang' ); ?>
			</p>
			<p>
				<a class="button button-secondary" href="<?php echo esc_url( self::polylang_settings_url() ); ?>">
					<?php esc_html_e( 'Open Polylang → Languages → Settings → Machine Translation', 'supertext-polylang' ); ?>
				</a>
			</p>

			<h2 style="margin-top:2em;"><?php esc_html_e( 'Translation Services (human)', 'supertext-polylang' ); ?></h2>
			<p class="description" style="max-width:640px;">
				<?php esc_html_e( 'Professional (human) translation orders use a separate credential from the AI translation API key. Enter your Supertext account email and Order API Key below.', 'supertext-polylang' ); ?>
			</p>
			<?php
			settings_errors( Settings::GROUP );
			Settings::render_form();
			?>
		</div>
		<?php
	}

	/**
	 * Debug page: order callback log.
	 *
	 * @return void
	 */
	public static function render_debug(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<?php
			self::header( __( 'Supertext Debug', 'supertext-polylang' ) );
			self::render_callback_log();
			?>
		</div>
		<?php
	}

	/**
	 * Renders the recorded order-callback payloads.
	 *
	 * @return void
	 */
	private static function render_callback_log(): void {
		$entries = Callback::get_log();
		?>
		<h2 style="margin-top:1em;"><?php esc_html_e( 'Order callbacks (debug)', 'supertext-polylang' ); ?></h2>
		<p class="description" style="max-width:640px;">
			<?php esc_html_e( 'The most recent payload Supertext POSTed to the callback URL is recorded here, so we can see exactly what the system sends when an order completes.', 'supertext-polylang' ); ?>
		</p>
		<p><code><?php echo esc_html( Callback::url() ); ?></code></p>

		<?php if ( empty( $entries ) ) : ?>
			<p><em><?php esc_html_e( 'No callbacks recorded yet.', 'supertext-polylang' ); ?></em></p>
		<?php else : ?>
			<?php foreach ( $entries as $entry ) : ?>
				<div style="margin:1em 0;padding:8px 12px;border:1px solid #dcdcde;background:#fff;max-width:900px;">
					<p style="margin:0 0 6px;">
						<strong><?php echo esc_html( (string) ( $entry['time'] ?? '' ) ); ?></strong>
						— <?php echo esc_html( (string) ( $entry['method'] ?? '' ) ); ?>
						<?php echo esc_html( (string) ( $entry['content_type'] ?? '' ) ); ?>
					</p>
					<pre style="white-space:pre-wrap;word-break:break-word;max-height:320px;overflow:auto;margin:0;"><?php echo esc_html( self::pretty_json( (string) ( $entry['body'] ?? '' ) ) ); ?></pre>
				</div>
			<?php endforeach; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::CLEAR_LOG_ACTION ); ?>" />
				<?php wp_nonce_field( self::CLEAR_LOG_ACTION ); ?>
				<?php submit_button( __( 'Clear callback log', 'supertext-polylang' ), 'secondary', 'submit', false ); ?>
			</form>
		<?php endif; ?>
		<?php
	}

	/**
	 * Pretty-prints a JSON string if possible.
	 *
	 * @param string $raw Raw body.
	 * @return string
	 */
	private static function pretty_json( string $raw ): string {
		$decoded = json_decode( $raw, true );
		if ( null === $decoded && JSON_ERROR_NONE !== json_last_error() ) {
			return $raw;
		}
		$pretty = wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false !== $pretty ? $pretty : $raw;
	}

	/**
	 * Renders the patch submission form.
	 *
	 * @param string $label       Button label.
	 * @param string $button_type 'primary' or 'secondary'.
	 * @return void
	 */
	private static function patch_form( string $label, string $button_type ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::PATCH_ACTION ); ?>" />
			<?php wp_nonce_field( self::PATCH_ACTION ); ?>
			<?php submit_button( $label, $button_type, 'submit', false ); ?>
		</form>
		<?php
	}

	/**
	 * Prints a status table row with a coloured dot.
	 *
	 * @param string $label Row label.
	 * @param bool   $ok    Whether the state is good.
	 * @param string $text  Status text.
	 * @return void
	 */
	private static function status_row( string $label, bool $ok, string $text ): void {
		printf(
			'<tr><td>%s</td><td><span style="color:%s;font-weight:600;">%s %s</span></td></tr>',
			esc_html( $label ),
			esc_attr( $ok ? '#00a32a' : '#d63638' ),
			$ok ? '&#10003;' : '&#10007;',
			esc_html( $text )
		);
	}

	/**
	 * Tells whether the Supertext service is registered and active.
	 *
	 * @return bool
	 */
	private static function service_is_active(): bool {
		if ( ! Patch::polylang_available() ) {
			return false;
		}

		$factory = '\WP_Syntex\Polylang_Pro\Modules\Machine_Translation\Factory';
		if ( ! in_array( Service::class, $factory::get_classnames(), true ) ) {
			return false;
		}

		if ( ! function_exists( 'PLL' ) || ! isset( PLL()->model ) ) {
			return false;
		}

		$instance = new Service(
			PLL()->options['machine_translation_services'][ Service::get_slug() ] ?? array(),
			PLL()->model
		);

		return $instance->is_active();
	}
}
