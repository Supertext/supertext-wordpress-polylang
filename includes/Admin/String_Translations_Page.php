<?php
/**
 * @package Supertext_Polylang
 */

namespace Supertext\Polylang\Admin;

defined( 'ABSPATH' ) || exit;

use PLL_Admin_Strings;
use Supertext\Polylang\Human_Translation\Human_Strings;
use Supertext\Polylang\Integrations\GravityForms\Strings as GF_Strings;
use Supertext\Polylang\Polylang\String_Store;

/**
 * "String Translation" submenu under Supertext: a Supertext-branded editor over
 * ALL of Polylang's registered strings (theme, plugins, Gravity Forms, …), with the
 * same group filter + search as Polylang's own screen. Reuses {@see String_Table};
 * the checked rows can be translated with Supertext AI or ordered for human
 * translation, and everything reads/writes Polylang's store ({@see String_Store}).
 *
 * @since 0.9.0
 */
class String_Translations_Page {
	/**
	 * Submenu slug.
	 *
	 * @var string
	 */
	const SLUG = 'supertext-polylang-strings';

	/**
	 * admin-post action.
	 *
	 * @var string
	 */
	const ACTION = 'supertext_polylang_strings_save';

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( self::class, 'register_menu' ), 11 );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle_submit' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'maybe_enqueue' ) );
	}

	/**
	 * Adds the submenu.
	 *
	 * @return void
	 */
	public static function register_menu(): void {
		if ( ! class_exists( 'PLL_Admin_Strings' ) ) {
			return;
		}
		add_submenu_page(
			Page::SLUG,
			__( 'String Translation', 'supertext-polylang' ),
			__( 'String Translation', 'supertext-polylang' ),
			'manage_options',
			self::SLUG,
			array( self::class, 'render' )
		);
	}

	/**
	 * Enqueues the select-all script on this screen.
	 *
	 * @return void
	 */
	public static function maybe_enqueue(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['page'] ) || self::SLUG !== $_GET['page'] ) {
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
	 * Renders the page.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) || ! class_exists( 'PLL_Admin_Strings' ) ) {
			return;
		}

		// Gravity Forms strings are only registered on Polylang's own screens; make
		// sure they're registered here too so they show up in the list.
		if ( class_exists( GF_Strings::class ) ) {
			GF_Strings::register_all();
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$group  = isset( $_GET['st_group'] ) ? sanitize_text_field( wp_unslash( $_GET['st_group'] ) ) : '';
		$search = isset( $_GET['st_search'] ) ? sanitize_text_field( wp_unslash( $_GET['st_search'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$all    = PLL_Admin_Strings::get_strings();
		$groups = array();
		foreach ( $all as $entry ) {
			$context = (string) ( $entry['context'] ?? '' );
			if ( '' !== $context ) {
				$groups[ $context ] = true;
			}
		}
		$groups = array_keys( $groups );
		sort( $groups );

		// Filter, then dedupe by source (translations are keyed by source value).
		$rows      = array();
		$seen      = array();
		$search_lc = '' !== $search ? mb_strtolower( $search ) : '';
		foreach ( $all as $entry ) {
			$source = (string) ( $entry['string'] ?? '' );
			if ( '' === trim( $source ) ) {
				continue;
			}
			$context = (string) ( $entry['context'] ?? '' );
			$name    = (string) ( $entry['name'] ?? '' );

			if ( '' !== $group && $context !== $group ) {
				continue;
			}
			if ( '' !== $search_lc
				&& false === mb_strpos( mb_strtolower( $source ), $search_lc )
				&& false === mb_strpos( mb_strtolower( $name ), $search_lc )
				&& false === mb_strpos( mb_strtolower( $context ), $search_lc ) ) {
				continue;
			}
			if ( isset( $seen[ $source ] ) ) {
				continue;
			}
			$seen[ $source ] = true;
			$rows[]          = array(
				'name'   => $name,
				'group'  => $context,
				'source' => $source,
			);
		}

		$languages    = String_Store::target_languages();
		$sources      = wp_list_pluck( $rows, 'source' );
		$translations = array();
		foreach ( $languages as $lang ) {
			$translations[ $lang['slug'] ] = String_Store::translations_for( $lang['slug'], $sources );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'String Translation — Supertext', 'supertext-polylang' ); ?></h1>
			<p class="description" style="max-width:820px;">
				<?php esc_html_e( 'All translatable strings registered with Polylang. Tick the rows you want, then translate them with Supertext AI or order human translation — or edit a translation directly and Save. These are the same translations as Languages → String translations.', 'supertext-polylang' ); ?>
			</p>

			<?php self::render_notices(); ?>

			<form method="get" style="margin:1em 0;">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
				<label for="st-filter-group" class="screen-reader-text"><?php esc_html_e( 'Group', 'supertext-polylang' ); ?></label>
				<select name="st_group" id="st-filter-group">
					<option value=""><?php esc_html_e( 'All groups', 'supertext-polylang' ); ?></option>
					<?php foreach ( $groups as $g ) : ?>
						<option value="<?php echo esc_attr( $g ); ?>" <?php selected( $group, $g ); ?>><?php echo esc_html( $g ); ?></option>
					<?php endforeach; ?>
				</select>
				<input type="search" name="st_search" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search strings…', 'supertext-polylang' ); ?>" />
				<?php submit_button( __( 'Filter', 'supertext-polylang' ), 'secondary', 'filter', false ); ?>
				<span style="color:#787c82;margin-left:.5em;">
					<?php
					/* translators: %d is the number of strings shown. */
					printf( esc_html( _n( '%d string', '%d strings', count( $rows ), 'supertext-polylang' ) ), count( $rows ) );
					?>
				</span>
			</form>

			<?php
			String_Table::render(
				array(
					'action'          => self::ACTION,
					'nonce_action'    => self::ACTION,
					'hidden'          => array(
						'st_group'  => $group,
						'st_search' => $search,
					),
					'rows'            => $rows,
					'languages'       => $languages,
					'translations'    => $translations,
					'show_group'      => true,
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
	 * Renders one-off result notices.
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
		if ( isset( $_GET['error'] ) ) {
			$msg = get_transient( 'supertext_polylang_strings_error_' . get_current_user_id() );
			delete_transient( 'supertext_polylang_strings_error_' . get_current_user_id() );
			printf(
				'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
				esc_html( is_string( $msg ) && '' !== $msg ? $msg : __( 'Something went wrong.', 'supertext-polylang' ) )
			);
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Handles the submit (save / AI / human).
	 *
	 * @return void
	 */
	public static function handle_submit(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'supertext-polylang' ) );
		}
		check_admin_referer( self::ACTION );

		$submit = String_Table::read_submit();

		// Always persist the visible grid first, so nothing typed is lost.
		String_Table::save_grid( $submit['grid'], $submit['src'] );

		$args = array( 'page' => self::SLUG );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce checked above.
		if ( isset( $_POST['st_group'] ) ) {
			$args['st_group'] = sanitize_text_field( wp_unslash( $_POST['st_group'] ) );
		}
		if ( isset( $_POST['st_search'] ) ) {
			$args['st_search'] = sanitize_text_field( wp_unslash( $_POST['st_search'] ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$sources = String_Table::selected_sources( $submit['selected'], $submit['src'] );

		if ( 'ai' === $submit['do'] ) {
			if ( empty( $sources ) ) {
				$args['error'] = '1';
				self::error( __( 'Select at least one row to translate.', 'supertext-polylang' ) );
			} else {
				$map = String_Store::translate_many( $sources, $submit['lang'] );
				if ( is_wp_error( $map ) ) {
					$args['error'] = '1';
					self::error( $map->get_error_message() );
				} else {
					$saved = String_Store::save_translations( $submit['lang'], array_filter( $map, static fn( $v ) => '' !== (string) $v ) );
					$args['ai'] = (string) count( array_filter( $map, static fn( $v ) => '' !== (string) $v ) );
				}
			}
		} elseif ( 'human' === $submit['do'] ) {
			$result = Human_Strings::place_order(
				$sources,
				$submit['lang'],
				$submit['service_id'],
				$submit['express'],
				__( 'Polylang strings', 'supertext-polylang' ),
				'str',
				0
			);
			if ( is_wp_error( $result ) ) {
				$args['error'] = '1';
				self::error( $result->get_error_message() );
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
	private static function error( string $message ): void {
		set_transient( 'supertext_polylang_strings_error_' . get_current_user_id(), $message, 60 );
	}
}
