<?php
/**
 * @package Supertext_Polylang
 */

namespace Supertext\Polylang\Integrations\GravityForms;

defined( 'ABSPATH' ) || exit;

use GFAPI;
use WP_Syntex\Polylang_Pro\Modules\Machine_Translation\Factory;
use Supertext\Polylang\Admin\Integrations;
use Supertext\Polylang\Admin\Page;

/**
 * "Gravity Forms" submenu under Supertext (shown only when the integration is
 * detected & enabled). Lists forms and lets you translate each one, per language,
 * with Supertext AI. The translations are stored and injected at render time by
 * {@see Integration}.
 *
 * @since 0.3.0
 */
class Admin_Page {
	/**
	 * Submenu slug.
	 *
	 * @var string
	 */
	const SLUG = 'supertext-polylang-gravityforms';

	/**
	 * admin-post action: translate one form into one language.
	 *
	 * @var string
	 */
	const TRANSLATE_ACTION = 'supertext_polylang_gf_translate';

	/**
	 * Transient key for one-off notices.
	 *
	 * @var string
	 */
	const NOTICE_TRANSIENT = 'supertext_polylang_gf_notice';

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( self::class, 'register_menu' ), 11 );
		add_action( 'admin_post_' . self::TRANSLATE_ACTION, array( self::class, 'handle_translate' ) );
	}

	/**
	 * Registers the submenu when the integration is enabled and Gravity Forms is up.
	 *
	 * @return void
	 */
	public static function register_menu(): void {
		if ( ! Integrations::enabled( 'gravityforms' ) || ! class_exists( 'GFAPI' ) ) {
			return;
		}

		add_submenu_page(
			Page::SLUG,
			__( 'Gravity Forms', 'supertext-polylang' ),
			__( 'Gravity Forms', 'supertext-polylang' ),
			'manage_options',
			self::SLUG,
			array( self::class, 'render' )
		);
	}

	/**
	 * Renders the forms overview.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) || ! class_exists( 'GFAPI' ) ) {
			return;
		}

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

		$forms     = GFAPI::get_forms();
		$languages = self::target_languages();
		$default   = function_exists( 'pll_default_language' ) ? (string) pll_default_language( 'name' ) : '';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Gravity Forms — Supertext', 'supertext-polylang' ); ?></h1>
			<p class="description" style="max-width:720px;">
				<?php
				if ( '' !== $default ) {
					printf(
						/* translators: %s is the default language name. */
						esc_html__( 'Forms are authored in your default language (%s). Translate each form into the other languages with Supertext AI; the translation is shown automatically on the front end for that language.', 'supertext-polylang' ),
						esc_html( $default )
					);
				} else {
					esc_html_e( 'Translate each form into your other languages with Supertext AI.', 'supertext-polylang' );
				}
				?>
			</p>

			<?php if ( empty( $languages ) ) : ?>
				<p><em><?php esc_html_e( 'Add at least one non-default language in Polylang to translate forms.', 'supertext-polylang' ); ?></em></p>
			<?php elseif ( empty( $forms ) ) : ?>
				<p><?php esc_html_e( 'No Gravity Forms forms found.', 'supertext-polylang' ); ?></p>
			<?php else : ?>
				<table class="widefat striped" style="max-width:900px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Form', 'supertext-polylang' ); ?></th>
							<?php foreach ( $languages as $lang ) : ?>
								<th><?php echo esc_html( $lang['name'] ); ?></th>
							<?php endforeach; ?>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $forms as $form ) : ?>
							<?php $form_id = (int) $form['id']; ?>
							<tr>
								<td>
									<strong><?php echo esc_html( (string) $form['title'] ); ?></strong>
									<div style="color:#787c82;">#<?php echo (int) $form_id; ?></div>
								</td>
								<?php foreach ( $languages as $lang ) : ?>
									<td>
										<?php if ( Store::has( $form_id, $lang['slug'] ) ) : ?>
											<span style="color:#00a32a;">&#10003; <?php esc_html_e( 'Translated', 'supertext-polylang' ); ?></span><br />
										<?php endif; ?>
										<?php self::translate_button( $form_id, $lang['slug'], Store::has( $form_id, $lang['slug'] ) ); ?>
									</td>
								<?php endforeach; ?>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Renders a translate button for one form + language.
	 *
	 * @param int    $form_id   Form id.
	 * @param string $lang      Target language slug.
	 * @param bool   $retranslate Whether a translation already exists.
	 * @return void
	 */
	private static function translate_button( int $form_id, string $lang, bool $retranslate ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::TRANSLATE_ACTION ); ?>" />
			<input type="hidden" name="form_id" value="<?php echo esc_attr( (string) $form_id ); ?>" />
			<input type="hidden" name="lang" value="<?php echo esc_attr( $lang ); ?>" />
			<?php wp_nonce_field( self::TRANSLATE_ACTION . '_' . $form_id . '_' . $lang ); ?>
			<?php
			submit_button(
				$retranslate ? __( 'Re-translate (AI)', 'supertext-polylang' ) : __( 'Translate (AI)', 'supertext-polylang' ),
				'small',
				'submit',
				false
			);
			?>
		</form>
		<?php
	}

	/**
	 * Handles a translate request: collect strings, translate via Supertext AI, store.
	 *
	 * @return void
	 */
	public static function handle_translate(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'supertext-polylang' ) );
		}

		$form_id = isset( $_POST['form_id'] ) ? (int) $_POST['form_id'] : 0;
		$lang    = isset( $_POST['lang'] ) ? sanitize_key( wp_unslash( $_POST['lang'] ) ) : '';
		check_admin_referer( self::TRANSLATE_ACTION . '_' . $form_id . '_' . $lang );

		$result = self::translate_form( $form_id, $lang );

		self::notice(
			is_wp_error( $result ) ? 'error' : 'success',
			is_wp_error( $result )
				? $result->get_error_message()
				/* translators: 1: form id, 2: language slug. */
				: sprintf( __( 'Form #%1$d translated to %2$s.', 'supertext-polylang' ), $form_id, $lang )
		);

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}

	/**
	 * Collects a form's strings, translates them with Supertext AI, and stores them.
	 *
	 * @param int    $form_id Form id.
	 * @param string $lang    Target language slug.
	 * @return true|\WP_Error
	 */
	private static function translate_form( int $form_id, string $lang ) {
		if ( ! class_exists( 'GFAPI' ) || ! function_exists( 'PLL' ) || ! isset( PLL()->model ) || ! class_exists( Factory::class ) ) {
			return new \WP_Error( 'supertext_gf_unavailable', __( 'Gravity Forms or Polylang is not available.', 'supertext-polylang' ) );
		}

		$model  = PLL()->model;
		$target = $model->get_language( $lang );
		$source = function_exists( 'pll_default_language' ) ? $model->get_language( (string) pll_default_language( 'slug' ) ) : null;
		if ( ! $target ) {
			return new \WP_Error( 'supertext_gf_bad_lang', __( 'Unknown target language.', 'supertext-polylang' ) );
		}

		$form = GFAPI::get_form( $form_id );
		if ( empty( $form ) ) {
			return new \WP_Error( 'supertext_gf_no_form', __( 'Form not found.', 'supertext-polylang' ) );
		}

		$strings = Fields::collect( $form );
		if ( empty( $strings ) ) {
			return new \WP_Error( 'supertext_gf_empty', __( 'This form has no translatable text.', 'supertext-polylang' ) );
		}

		$factory = new Factory( $model );
		$service = $factory->get_active_service();
		if ( null === $service ) {
			return new \WP_Error( 'supertext_gf_no_service', __( 'No active Supertext AI service. Configure it in Polylang → Languages → Settings → Machine Translation.', 'supertext-polylang' ) );
		}

		$client = $service->get_client();
		if ( ! method_exists( $client, 'translate_strings' ) ) {
			return new \WP_Error( 'supertext_gf_client', __( 'The active machine-translation client does not support string translation.', 'supertext-polylang' ) );
		}

		$translated = $client->translate_strings( $strings, $target, $source );
		if ( is_wp_error( $translated ) ) {
			return $translated;
		}

		Store::save( $form_id, $lang, array_filter( (array) $translated, static fn( $v ) => '' !== (string) $v ) );

		return true;
	}

	/**
	 * Returns the Polylang languages other than the default (targets to translate to).
	 *
	 * @return array<int, array{slug: string, name: string}>
	 */
	private static function target_languages(): array {
		if ( ! function_exists( 'pll_the_languages' ) || ! function_exists( 'PLL' ) || ! isset( PLL()->model ) ) {
			return array();
		}

		$default = function_exists( 'pll_default_language' ) ? (string) pll_default_language( 'slug' ) : '';
		$out     = array();
		foreach ( PLL()->model->get_languages_list() as $lang ) {
			if ( $lang->slug === $default ) {
				continue;
			}
			$out[] = array( 'slug' => $lang->slug, 'name' => $lang->name );
		}
		return $out;
	}

	/**
	 * Stores a one-off notice.
	 *
	 * @param string $type    'success' or 'error'.
	 * @param string $message Message.
	 * @return void
	 */
	private static function notice( string $type, string $message ): void {
		set_transient( self::NOTICE_TRANSIENT . '_' . get_current_user_id(), array( 'type' => $type, 'text' => $message ), 60 );
	}
}
