<?php
/**
 * @package Supertext_Polylang
 */

namespace Supertext\Polylang\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Detects supported third-party plugins (e.g. Gravity Forms) and remembers which
 * of them the site wants Supertext to integrate with.
 *
 * A "Detect plugins" button on the Settings page runs the detection; each enabled
 * integration can then register its own menu entry / hooks (guarded by
 * {@see self::enabled()}).
 *
 * @since 0.3.0
 */
class Integrations {
	/**
	 * Option storing the enabled integration slugs (slug => bool).
	 *
	 * @var string
	 */
	const OPTION = 'supertext_polylang_integrations';

	/**
	 * admin-post action for the detection button.
	 *
	 * @var string
	 */
	const DETECT_ACTION = 'supertext_polylang_detect_plugins';

	/**
	 * Transient key for the one-off detection notice.
	 *
	 * @var string
	 */
	const NOTICE_TRANSIENT = 'supertext_polylang_integrations_notice';

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_post_' . self::DETECT_ACTION, array( self::class, 'handle_detect' ) );
	}

	/**
	 * The integrations this plugin supports and how to detect each.
	 *
	 * @return array<string, array{label: string, detected: bool}>
	 */
	public static function supported(): array {
		return array(
			'gravityforms' => array(
				'label'    => __( 'Gravity Forms', 'supertext-polylang' ),
				'detected' => class_exists( 'GFForms' ) || class_exists( 'GFAPI' ),
			),
		);
	}

	/**
	 * Whether the given integration is enabled (detected and switched on).
	 *
	 * @param string $slug Integration slug.
	 * @return bool
	 */
	public static function enabled( string $slug ): bool {
		$option = get_option( self::OPTION, array() );
		return is_array( $option ) && ! empty( $option[ $slug ] );
	}

	/**
	 * Runs detection: enables every supported plugin that is currently active.
	 *
	 * @return void
	 */
	public static function handle_detect(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'supertext-polylang' ) );
		}
		check_admin_referer( self::DETECT_ACTION );

		$enabled = array();
		$found   = array();
		foreach ( self::supported() as $slug => $info ) {
			$enabled[ $slug ] = (bool) $info['detected'];
			if ( $info['detected'] ) {
				$found[] = $info['label'];
			}
		}

		update_option( self::OPTION, $enabled, false );

		$message = ! empty( $found )
			/* translators: %s is a comma-separated list of plugin names. */
			? sprintf( __( 'Detected and enabled: %s. Its menu is now available under Supertext.', 'supertext-polylang' ), implode( ', ', $found ) )
			: __( 'No supported plugins were detected.', 'supertext-polylang' );

		set_transient( self::NOTICE_TRANSIENT . '_' . get_current_user_id(), $message, 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . Page::SLUG ) );
		exit;
	}

	/**
	 * Renders the "Integrations" section (detect button + current status) on the
	 * Settings page.
	 *
	 * @return void
	 */
	public static function render_section(): void {
		$notice_key = self::NOTICE_TRANSIENT . '_' . get_current_user_id();
		$notice     = get_transient( $notice_key );
		if ( is_string( $notice ) && '' !== $notice ) {
			delete_transient( $notice_key );
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $notice ) );
		}
		?>
		<h2 style="margin-top:2em;"><?php esc_html_e( 'Integrations', 'supertext-polylang' ); ?></h2>
		<p class="description" style="max-width:640px;">
			<?php esc_html_e( 'Some page builders and form plugins store content outside of posts, so Supertext integrates with them directly. Click detect to scan for supported plugins; each one found gets its own entry under the Supertext menu.', 'supertext-polylang' ); ?>
		</p>

		<table class="widefat striped" style="max-width:640px;margin:1em 0;">
			<tbody>
				<?php foreach ( self::supported() as $slug => $info ) : ?>
					<tr>
						<td><?php echo esc_html( $info['label'] ); ?></td>
						<td>
							<?php if ( self::enabled( $slug ) ) : ?>
								<span style="color:#00a32a;font-weight:600;">&#10003; <?php esc_html_e( 'Detected & enabled', 'supertext-polylang' ); ?></span>
							<?php elseif ( $info['detected'] ) : ?>
								<span style="color:#dba617;"><?php esc_html_e( 'Active — click detect to enable', 'supertext-polylang' ); ?></span>
							<?php else : ?>
								<span style="color:#787c82;"><?php esc_html_e( 'Not detected', 'supertext-polylang' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::DETECT_ACTION ); ?>" />
			<?php wp_nonce_field( self::DETECT_ACTION ); ?>
			<?php submit_button( __( 'Detect plugins', 'supertext-polylang' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}
}
