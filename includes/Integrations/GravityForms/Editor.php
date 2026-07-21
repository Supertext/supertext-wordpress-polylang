<?php
/**
 * @package Supertext_Polylang
 */

namespace Supertext\Polylang\Integrations\GravityForms;

defined( 'ABSPATH' ) || exit;

use GFAPI;
use Supertext\Polylang\Admin\Bulk_Actions;
use Supertext\Polylang\Admin\Settings;
use Supertext\Polylang\Admin\String_Table;
use Supertext\Polylang\Human_Translation\Human_Strings;
use Supertext\Polylang\Polylang\String_Store;

/**
 * Per-form string editor: the form's source strings in rows with a checkbox each and
 * one editable column per target language. A bottom action bar translates the checked
 * rows with Supertext AI or orders them for human translation (into the chosen target
 * language), or saves the whole grid. Everything reads/writes Polylang's store
 * ({@see Strings} / {@see String_Table}), so it stays in sync with Languages → String
 * translations and the front end.
 *
 * Rendered by {@see Admin_Page} when its page is opened with a `form_id`.
 *
 * @since 0.7.0
 */
class Editor {
	/**
	 * admin-post action for the editor form (save / AI / human).
	 *
	 * @var string
	 */
	const ACTION = 'supertext_polylang_gf_strings';

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle_submit' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'maybe_enqueue' ) );
	}

	/**
	 * Whether the current admin request is the string-editor view.
	 *
	 * @return bool
	 */
	private static function is_editor_screen(): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		return Admin_Page::SLUG === $page && isset( $_GET['form_id'] );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Enqueues the select-all script on the editor screen.
	 *
	 * @return void
	 */
	public static function maybe_enqueue(): void {
		if ( ! self::is_editor_screen() ) {
			return;
		}
		wp_enqueue_script(
			'supertext-string-table',
			plugins_url( 'assets/js/string-table.js', SUPERTEXT_POLYLANG_FILE ),
			array(),
			SUPERTEXT_POLYLANG_VERSION,
			true
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
		$rows      = Strings::collect_unique( $form ); // [ ['name'=>, 'source'=>], … ].
		$sources   = wp_list_pluck( $rows, 'source' );

		$translations = array();
		foreach ( $languages as $lang ) {
			$translations[ $lang['slug'] ] = String_Store::translations_for( $lang['slug'], $sources );
		}
		?>
		<div class="wrap supertext-admin">
			<?php
			/* translators: %s is the form title. */
			\Supertext\Polylang\Admin\Page::hero( sprintf( __( 'Translate strings: %s', 'supertext-polylang' ), (string) $form['title'] ) );
			?>
			<p><a href="<?php echo esc_url( $back ); ?>">&larr; <?php esc_html_e( 'Back to forms', 'supertext-polylang' ); ?></a></p>

			<?php self::render_notices(); ?>

			<p class="description" style="max-width:820px;">
				<?php esc_html_e( 'Tick the rows you want, choose a target language, then translate them with Supertext AI or order human translation. Or edit a translation directly and Save. These are the same translations shown under Languages → String translations.', 'supertext-polylang' ); ?>
			</p>

			<?php
			String_Table::render(
				array(
					'action'          => self::ACTION,
					'nonce_action'    => self::ACTION,
					'hidden'          => array( 'form_id' => (string) $form_id ),
					'rows'            => $rows,
					'languages'       => $languages,
					'translations'    => $translations,
					'show_group'      => false,
					'human'           => Settings::is_configured(),
					'human_services'  => Bulk_Actions::HUMAN_SERVICES,
					'express_options' => Bulk_Actions::EXPRESS_OPTIONS,
				)
			);
			?>
		</div>
		<?php
	}

	/**
	 * Shows one-off result notices.
	 *
	 * @return void
	 */
	private static function render_notices(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Translations saved.', 'supertext-polylang' ) . '</p></div>';
		}
		if ( isset( $_GET['ai'] ) ) {
			$n = (int) $_GET['ai'];
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html( sprintf( /* translators: %d count */ _n( '%d string translated with AI.', '%d strings translated with AI.', $n, 'supertext-polylang' ), $n ) )
			);
		}
		if ( isset( $_GET['ordered'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Human translation order submitted. It will be written back automatically when complete.', 'supertext-polylang' ) . '</p></div>';
		}
		if ( isset( $_GET['order_error'] ) ) {
			$msg = get_transient( 'supertext_polylang_gf_order_error_' . get_current_user_id() );
			delete_transient( 'supertext_polylang_gf_order_error_' . get_current_user_id() );
			printf(
				'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
				esc_html( is_string( $msg ) && '' !== $msg ? $msg : __( 'Something went wrong.', 'supertext-polylang' ) )
			);
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Handles the editor submit (save / AI / human) for one form.
	 *
	 * @return void
	 */
	public static function handle_submit(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'supertext-polylang' ) );
		}
		check_admin_referer( self::ACTION );

		$form_id = isset( $_POST['form_id'] ) ? (int) $_POST['form_id'] : 0;
		$submit  = String_Table::read_submit();

		// Always persist the visible grid first.
		String_Table::save_grid( $submit['grid'], $submit['src'] );

		$args    = array(
			'page'    => Admin_Page::SLUG,
			'form_id' => $form_id,
		);
		$sources = String_Table::selected_sources( $submit['selected'], $submit['src'] );

		if ( 'ai' === $submit['do'] ) {
			if ( empty( $sources ) ) {
				self::order_error( __( 'Select at least one row to translate.', 'supertext-polylang' ) );
				$args['order_error'] = '1';
			} else {
				$map = String_Store::translate_many( $sources, $submit['lang'] );
				if ( is_wp_error( $map ) ) {
					self::order_error( $map->get_error_message() );
					$args['order_error'] = '1';
				} else {
					$filled = array_filter( $map, static fn( $v ) => '' !== (string) $v );
					String_Store::save_translations( $submit['lang'], $filled );
					$args['ai'] = (string) count( $filled );
				}
			}
		} elseif ( 'human' === $submit['do'] ) {
			$form   = class_exists( 'GFAPI' ) ? GFAPI::get_form( $form_id ) : array();
			$title  = is_array( $form ) ? (string) ( $form['title'] ?? '' ) : '';
			$result = Human_Strings::place_order(
				$sources,
				$submit['lang'],
				$submit['service_id'],
				$submit['express'],
				'Gravity Forms: ' . ( '' !== $title ? $title : '#' . $form_id ),
				'str',
				$form_id
			);
			if ( is_wp_error( $result ) ) {
				self::order_error( $result->get_error_message() );
				$args['order_error'] = '1';
			} else {
				$args['ordered'] = '1';
			}
		} else {
			$args['saved'] = '1';
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Stores a one-off error message for the current user.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	private static function order_error( string $message ): void {
		set_transient( 'supertext_polylang_gf_order_error_' . get_current_user_id(), $message, 60 );
	}
}
