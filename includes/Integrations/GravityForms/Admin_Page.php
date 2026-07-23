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
use Supertext\Polylang\Admin\String_Translations_Page;

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
		<div class="wrap supertext-admin">
			<?php
			\Supertext\Polylang\Admin\Page::hero(
				__( 'Gravity Forms — Supertext', 'supertext-polylang' ),
				__( 'Translate your Gravity Forms forms into every language.', 'supertext-polylang' )
			);
			?>
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
			<p class="description" style="max-width:720px;">
				<?php
				printf(
					/* translators: %s is a link to Polylang's String translations screen. */
					esc_html__( 'Translations are stored in Polylang and can be reviewed or edited under %s.', 'supertext-polylang' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=mlang_strings' ) ) . '">' . esc_html__( 'Languages → String translations', 'supertext-polylang' ) . '</a>'
				);
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
									<div>
										<a href="<?php echo esc_url( add_query_arg( array( 'page' => String_Translations_Page::SLUG, 'st_group' => Strings::group_name( $form ) ), admin_url( 'admin.php' ) ) ); ?>">
											<?php esc_html_e( 'Edit translations', 'supertext-polylang' ); ?>
										</a>
									</div>
								</td>
								<?php foreach ( $languages as $lang ) : ?>
									<?php $status = Strings::translation_status( $form, $lang['slug'] ); ?>
									<td>
										<?php if ( $status['total'] > 0 && $status['translated'] >= $status['total'] ) : ?>
											<span style="color:#00a32a;">&#10003; <?php esc_html_e( 'Translated', 'supertext-polylang' ); ?></span>
										<?php elseif ( $status['translated'] > 0 ) : ?>
											<span style="color:#dba617;" title="<?php echo esc_attr( sprintf( '%d / %d', $status['translated'], $status['total'] ) ); ?>">&#9888; <?php esc_html_e( 'Partly translated', 'supertext-polylang' ); ?></span>
										<?php else : ?>
											<?php self::translate_button( $form_id, $lang['slug'] ); ?>
										<?php endif; ?>
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
	 * Renders a "Translate (AI)" button for one untranslated form + language.
	 *
	 * Only shown when no translation exists yet; re-translating or editing an
	 * existing translation is done from the String Translation page.
	 *
	 * @param int    $form_id Form id.
	 * @param string $lang    Target language slug.
	 * @return void
	 */
	private static function translate_button( int $form_id, string $lang ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::TRANSLATE_ACTION ); ?>" />
			<input type="hidden" name="form_id" value="<?php echo esc_attr( (string) $form_id ); ?>" />
			<input type="hidden" name="lang" value="<?php echo esc_attr( $lang ); ?>" />
			<?php wp_nonce_field( self::TRANSLATE_ACTION . '_' . $form_id . '_' . $lang ); ?>
			<?php submit_button( __( 'Translate (AI)', 'supertext-polylang' ), 'small', 'submit', false ); ?>
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

		// Map path => translation back onto source => translation and write it into
		// Polylang's own store, so the result shows up (and stays editable) under
		// Languages → String translations.
		$pairs = array();
		foreach ( (array) $translated as $path => $value ) {
			$src = isset( $strings[ $path ] ) ? (string) $strings[ $path ] : '';
			if ( '' !== $src && '' !== (string) $value ) {
				$pairs[ $src ] = (string) $value;
			}
		}

		Strings::register_form( $form );
		Strings::save_translations( $lang, $pairs );

		return true;
	}

	/**
	 * Returns the Polylang languages other than the default (targets to translate to).
	 *
	 * @return array<int, array{slug: string, name: string}>
	 */
	private static function target_languages(): array {
		return Strings::target_languages();
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
