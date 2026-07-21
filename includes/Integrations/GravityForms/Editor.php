<?php
/**
 * @package Supertext_Polylang
 */

namespace Supertext\Polylang\Integrations\GravityForms;

defined( 'ABSPATH' ) || exit;

use GFAPI;
use WP_Syntex\Polylang_Pro\Modules\Machine_Translation\Factory;
use Supertext\Polylang\Admin\Integrations;

/**
 * Per-form string editor: source strings in rows, one editable column per target
 * language, with an inline "AI" button per cell that translates that single string
 * via Supertext. Everything reads and writes Polylang's own store ({@see Strings}),
 * so edits here, edits in Polylang's String translations grid, and Supertext output
 * are all the same record.
 *
 * Rendered by {@see Admin_Page} when its page is opened with a `form_id`; this class
 * owns the save (admin-post) and single-string translate (admin-ajax) endpoints plus
 * the small script that drives the inline buttons.
 *
 * @since 0.7.0
 */
class Editor {
	/**
	 * admin-post action: bulk-save the edited translations.
	 *
	 * @var string
	 */
	const SAVE_ACTION = 'supertext_polylang_gf_save_strings';

	/**
	 * admin-ajax action: translate a single string with Supertext AI.
	 *
	 * @var string
	 */
	const AJAX_ACTION = 'supertext_polylang_gf_translate_string';

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_post_' . self::SAVE_ACTION, array( self::class, 'handle_save' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( self::class, 'handle_ajax' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'maybe_enqueue' ) );
	}

	/**
	 * Whether the current admin request is the string-editor view.
	 *
	 * @return bool
	 */
	private static function is_editor_screen(): bool {
		// Read-only screen detection; no state change, so no nonce needed.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		return Admin_Page::SLUG === $page && isset( $_GET['form_id'] );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Enqueues the inline-translate script on the editor screen only.
	 *
	 * @return void
	 */
	public static function maybe_enqueue(): void {
		if ( ! self::is_editor_screen() ) {
			return;
		}

		wp_enqueue_script(
			'supertext-gf-string-editor',
			plugins_url( 'assets/js/gf-string-editor.js', SUPERTEXT_POLYLANG_FILE ),
			array(),
			SUPERTEXT_POLYLANG_VERSION,
			true
		);
		wp_localize_script(
			'supertext-gf-string-editor',
			'SupertextGFEditor',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => self::AJAX_ACTION,
				'nonce'   => wp_create_nonce( self::AJAX_ACTION ),
				'i18n'    => array(
					'busy'  => __( '…', 'supertext-polylang' ),
					'ai'    => __( 'AI', 'supertext-polylang' ),
					'error' => __( 'Failed', 'supertext-polylang' ),
				),
			)
		);
	}

	/**
	 * Renders the editor for one form.
	 *
	 * @param int $form_id Form id.
	 * @return void
	 */
	public static function render( int $form_id ): void {
		if ( ! current_user_can( 'manage_options' ) || ! class_exists( 'GFAPI' ) ) {
			return;
		}

		$back = admin_url( 'admin.php?page=' . Admin_Page::SLUG );
		$form = GFAPI::get_form( $form_id );
		if ( empty( $form ) ) {
			printf(
				'<div class="wrap"><h1>%s</h1><p>%s</p><p><a href="%s">%s</a></p></div>',
				esc_html__( 'Gravity Forms — Supertext', 'supertext-polylang' ),
				esc_html__( 'Form not found.', 'supertext-polylang' ),
				esc_url( $back ),
				esc_html__( '← Back to forms', 'supertext-polylang' )
			);
			return;
		}

		$languages = Strings::target_languages();
		$strings   = Strings::collect_unique( $form );
		$sources   = wp_list_pluck( $strings, 'source' );

		// Build each language column in one pass.
		$columns = array();
		foreach ( $languages as $lang ) {
			$columns[ $lang['slug'] ] = Strings::translations_for( $lang['slug'], $sources );
		}
		?>
		<div class="wrap">
			<h1>
				<?php
				/* translators: %s is the form title. */
				printf( esc_html__( 'Translate strings: %s', 'supertext-polylang' ), esc_html( (string) $form['title'] ) );
				?>
			</h1>

			<?php
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( isset( $_GET['saved'] ) ) :
				?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Translations saved.', 'supertext-polylang' ); ?></p></div>
			<?php endif; ?>
			<p><a href="<?php echo esc_url( $back ); ?>">&larr; <?php esc_html_e( 'Back to forms', 'supertext-polylang' ); ?></a></p>

			<?php if ( empty( $languages ) ) : ?>
				<p><em><?php esc_html_e( 'Add at least one non-default language in Polylang to translate this form.', 'supertext-polylang' ); ?></em></p>
			<?php elseif ( empty( $strings ) ) : ?>
				<p><?php esc_html_e( 'This form has no translatable text.', 'supertext-polylang' ); ?></p>
			<?php else : ?>
				<p class="description" style="max-width:820px;">
					<?php esc_html_e( 'Edit any translation and Save. Use the AI button in a cell to translate that single string with Supertext; the result fills the box for review before you save. These are the same translations shown under Languages → String translations.', 'supertext-polylang' ); ?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ACTION ); ?>" />
					<input type="hidden" name="form_id" value="<?php echo esc_attr( (string) $form_id ); ?>" />
					<?php wp_nonce_field( self::SAVE_ACTION . '_' . $form_id ); ?>

					<table class="widefat striped fixed">
						<thead>
							<tr>
								<th style="width:14%;"><?php esc_html_e( 'Field', 'supertext-polylang' ); ?></th>
								<th style="width:26%;"><?php esc_html_e( 'Source', 'supertext-polylang' ); ?></th>
								<?php foreach ( $languages as $lang ) : ?>
									<th><?php echo esc_html( $lang['name'] ); ?></th>
								<?php endforeach; ?>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $strings as $i => $item ) : ?>
								<tr>
									<td><span style="color:#787c82;"><?php echo esc_html( $item['name'] ); ?></span></td>
									<td>
										<?php echo esc_html( $item['source'] ); ?>
										<input type="hidden" name="src[<?php echo (int) $i; ?>]" value="<?php echo esc_attr( $item['source'] ); ?>" />
									</td>
									<?php foreach ( $languages as $lang ) : ?>
										<?php $slug = $lang['slug']; ?>
										<td>
											<textarea
												name="tr[<?php echo esc_attr( $slug ); ?>][<?php echo (int) $i; ?>]"
												rows="2"
												style="width:100%;box-sizing:border-box;"
											><?php echo esc_textarea( $columns[ $slug ][ $item['source'] ] ?? '' ); ?></textarea>
											<button
												type="button"
												class="button button-small st-gf-ai"
												data-i="<?php echo (int) $i; ?>"
												data-lang="<?php echo esc_attr( $slug ); ?>"
												data-source="<?php echo esc_attr( $item['source'] ); ?>"
											><?php esc_html_e( 'AI', 'supertext-polylang' ); ?></button>
										</td>
									<?php endforeach; ?>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>

					<?php submit_button( __( 'Save changes', 'supertext-polylang' ) ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Handles the bulk save: writes edited translations into Polylang per language.
	 *
	 * @return void
	 */
	public static function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'supertext-polylang' ) );
		}

		$form_id = isset( $_POST['form_id'] ) ? (int) $_POST['form_id'] : 0;
		check_admin_referer( self::SAVE_ACTION . '_' . $form_id );

		$src = ( isset( $_POST['src'] ) && is_array( $_POST['src'] ) ) ? wp_unslash( $_POST['src'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$tr  = ( isset( $_POST['tr'] ) && is_array( $_POST['tr'] ) ) ? wp_unslash( $_POST['tr'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		foreach ( $tr as $lang => $rows ) {
			$lang = sanitize_key( $lang );
			if ( '' === $lang || ! is_array( $rows ) ) {
				continue;
			}

			$pairs = array();
			foreach ( $rows as $i => $value ) {
				$source = isset( $src[ $i ] ) ? (string) $src[ $i ] : '';
				if ( '' === $source ) {
					continue;
				}
				// Admin-entered; allow safe HTML (field content), strip anything unsafe.
				$pairs[ $source ] = wp_kses_post( (string) $value );
			}

			Strings::save_translations( $lang, $pairs );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => Admin_Page::SLUG,
					'form_id' => $form_id,
					'saved'   => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Handles the single-string AI translation (admin-ajax).
	 *
	 * Returns the translation for the caller to place in the edit box; it does not
	 * persist anything — the user saves the form to commit.
	 *
	 * @return void
	 */
	public static function handle_ajax(): void {
		check_ajax_referer( self::AJAX_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'supertext-polylang' ) ), 403 );
		}
		if ( ! Integrations::enabled( 'gravityforms' ) ) {
			wp_send_json_error( array( 'message' => __( 'Gravity Forms integration is disabled.', 'supertext-polylang' ) ) );
		}

		$lang   = isset( $_POST['lang'] ) ? sanitize_key( wp_unslash( $_POST['lang'] ) ) : '';
		$source = isset( $_POST['source'] ) ? (string) wp_unslash( $_POST['source'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( '' === $lang || '' === $source ) {
			wp_send_json_error( array( 'message' => __( 'Missing language or source.', 'supertext-polylang' ) ) );
		}

		if ( ! function_exists( 'PLL' ) || ! isset( PLL()->model ) || ! class_exists( Factory::class ) ) {
			wp_send_json_error( array( 'message' => __( 'Polylang is not available.', 'supertext-polylang' ) ) );
		}

		$model  = PLL()->model;
		$target = $model->get_language( $lang );
		$source_lang = function_exists( 'pll_default_language' ) ? $model->get_language( (string) pll_default_language( 'slug' ) ) : null;
		if ( ! $target ) {
			wp_send_json_error( array( 'message' => __( 'Unknown target language.', 'supertext-polylang' ) ) );
		}

		$service = ( new Factory( $model ) )->get_active_service();
		if ( null === $service ) {
			wp_send_json_error( array( 'message' => __( 'No active Supertext AI service.', 'supertext-polylang' ) ) );
		}

		$client = $service->get_client();
		if ( ! method_exists( $client, 'translate_strings' ) ) {
			wp_send_json_error( array( 'message' => __( 'The active service cannot translate strings.', 'supertext-polylang' ) ) );
		}

		$result = $client->translate_strings( array( 's' => $source ), $target, $source_lang );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'translation' => isset( $result['s'] ) ? (string) $result['s'] : '' ) );
	}
}
