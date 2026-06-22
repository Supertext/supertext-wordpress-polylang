<?php
/**
 * @package Supertext_Polylang
 */

namespace Supertext\Polylang\Admin;

defined( 'ABSPATH' ) || exit;

use WP_Post;
use WP_Screen;
use PLL_Export_Container;
use PLL_Export_Data_From_Posts;
use WP_Syntex\Polylang_Pro\Modules\Machine_Translation\Data;
use WP_Syntex\Polylang_Pro\Modules\Machine_Translation\Factory;
use WP_Syntex\Polylang_Pro\Modules\Machine_Translation\Processor;

/**
 * Adds "Supertext AI Translation" and "Supertext Human Translation" bulk actions
 * to the posts list table, with the target-language / service-type / delivery
 * dropdowns between the bulk-action select and the Apply button.
 *
 * Ported from the original supertext-wordpress plugin, adapted to the new
 * architecture: the AI path runs Polylang's machine-translation pipeline directly
 * (which routes to the active Supertext service) instead of simulating a
 * post-new.php request.
 *
 * @since 0.4.0
 */
class Bulk_Actions {
	const ACTION_AI    = 'supertext_ai_translation';
	const ACTION_HUMAN = 'supertext_human_translation';

	/** Human translation product types (Supertext option ID => label). */
	const HUMAN_SERVICES = array(
		54 => 'Übersetzung BASIC',
		55 => 'Übersetzung PREMIUM',
		56 => 'Übersetzung CREATIVE',
	);

	/** Human translation delivery / express options (Supertext option ID => label). */
	const EXPRESS_OPTIONS = array(
		'2' => '24h',
		'3' => '48h',
		'4' => '3 Tage',
		'5' => '1 Woche',
	);

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'current_screen', array( self::class, 'hook_screen' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( self::class, 'render_notice' ) );
	}

	/**
	 * Enqueues the list-table script that toggles the dropdowns.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public static function enqueue_assets( string $hook ): void {
		if ( 'edit.php' !== $hook ) {
			return;
		}

		wp_enqueue_script(
			'supertext-polylang-bulk',
			plugins_url( 'assets/js/bulk-actions.js', SUPERTEXT_POLYLANG_FILE ),
			array( 'jquery' ),
			SUPERTEXT_POLYLANG_VERSION,
			true
		);
	}

	/**
	 * Hooks the per-screen bulk-action filters once we know the current screen.
	 *
	 * @param WP_Screen $screen Current screen.
	 * @return void
	 */
	public static function hook_screen( WP_Screen $screen ): void {
		if ( 'edit' !== $screen->base ) {
			return;
		}

		add_filter( "bulk_actions-{$screen->id}", array( self::class, 'add_bulk_actions' ) );
		add_filter( "handle_bulk_actions-{$screen->id}", array( self::class, 'handle_bulk_action' ), 10, 3 );
		add_action( 'restrict_manage_posts', array( self::class, 'render_pickers' ) );
	}

	/**
	 * Adds the two Supertext bulk actions.
	 *
	 * @param array $actions Existing bulk actions.
	 * @return array
	 */
	public static function add_bulk_actions( array $actions ): array {
		$actions[ self::ACTION_AI ]    = __( 'Supertext AI Translation', 'supertext-polylang' );
		$actions[ self::ACTION_HUMAN ] = __( 'Supertext Human Translation', 'supertext-polylang' );
		return $actions;
	}

	/**
	 * Renders the (hidden) target-language, service-type and delivery dropdowns.
	 *
	 * @return void
	 */
	public static function render_pickers(): void {
		$languages = self::get_polylang_languages();
		?>
		<span id="supertext-lang-picker" style="display:none;">
			<label for="supertext_target_lang" class="screen-reader-text"><?php esc_html_e( 'Target language', 'supertext-polylang' ); ?></label>
			<select name="supertext_target_lang" id="supertext_target_lang">
				<option value=""><?php esc_html_e( 'Target language', 'supertext-polylang' ); ?></option>
				<?php foreach ( $languages as $slug => $name ) : ?>
					<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $name ); ?></option>
				<?php endforeach; ?>
			</select>
		</span>
		<span id="supertext-service-picker" style="display:none;">
			<label for="supertext_service_id" class="screen-reader-text"><?php esc_html_e( 'Translation type', 'supertext-polylang' ); ?></label>
			<select name="supertext_service_id" id="supertext_service_id">
				<option value=""><?php esc_html_e( 'Translation type', 'supertext-polylang' ); ?></option>
				<?php foreach ( self::HUMAN_SERVICES as $id => $label ) : ?>
					<option value="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</span>
		<span id="supertext-express-picker" style="display:none;">
			<label for="supertext_express" class="screen-reader-text"><?php esc_html_e( 'Delivery', 'supertext-polylang' ); ?></label>
			<select name="supertext_express" id="supertext_express">
				<option value=""><?php esc_html_e( 'Delivery', 'supertext-polylang' ); ?></option>
				<?php foreach ( self::EXPRESS_OPTIONS as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</span>
		<?php
	}

	/**
	 * Returns Polylang languages as slug => name.
	 *
	 * @return array<string, string>
	 */
	private static function get_polylang_languages(): array {
		if ( function_exists( 'PLL' ) && isset( PLL()->model ) ) {
			$result = array();
			foreach ( PLL()->model->get_languages_list() as $lang ) {
				$result[ $lang->slug ] = $lang->name;
			}
			if ( ! empty( $result ) ) {
				return $result;
			}
		}

		if ( function_exists( 'pll_languages_list' ) ) {
			$slugs = pll_languages_list( array( 'fields' => 'slug' ) );
			$names = pll_languages_list( array( 'fields' => 'name' ) );
			if ( ! empty( $slugs ) ) {
				return array_combine( $slugs, $names );
			}
		}

		return array();
	}

	/**
	 * Handles a Supertext bulk action.
	 *
	 * @param string $redirect_url Redirect URL.
	 * @param string $action       Bulk action name.
	 * @param int[]  $post_ids     Selected post IDs.
	 * @return string
	 */
	public static function handle_bulk_action( string $redirect_url, string $action, array $post_ids ): string {
		if ( ! in_array( $action, array( self::ACTION_AI, self::ACTION_HUMAN ), true ) ) {
			return $redirect_url;
		}

		$target_lang = sanitize_key( wp_unslash( $_REQUEST['supertext_target_lang'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $target_lang ) {
			return add_query_arg( 'supertext_error', 'no_lang', $redirect_url );
		}

		$service_id = 0;
		$express    = '';

		if ( self::ACTION_AI === $action && ! self::has_active_service() ) {
			return add_query_arg( 'supertext_error', 'mt_not_configured', $redirect_url );
		}

		if ( self::ACTION_HUMAN === $action ) {
			$service_id = (int) ( $_REQUEST['supertext_service_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( ! isset( self::HUMAN_SERVICES[ $service_id ] ) ) {
				return add_query_arg( 'supertext_error', 'no_service', $redirect_url );
			}
			$express = sanitize_key( wp_unslash( $_REQUEST['supertext_express'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( ! isset( self::EXPRESS_OPTIONS[ $express ] ) ) {
				return add_query_arg( 'supertext_error', 'no_delivery', $redirect_url );
			}
		}

		$created = 0;
		$errors  = 0;

		foreach ( $post_ids as $post_id ) {
			$ok = self::ACTION_AI === $action
				? self::ai_translate( (int) $post_id, $target_lang )
				: self::create_human_draft( (int) $post_id, $target_lang, $service_id, $express );

			$ok ? $created++ : $errors++;
		}

		return add_query_arg(
			array(
				'supertext_action'  => $action,
				'supertext_created' => $created,
				'supertext_errors'  => $errors,
			),
			$redirect_url
		);
	}

	/**
	 * Tells whether a Supertext MT service is active (configured with an API key).
	 *
	 * @return bool
	 */
	private static function has_active_service(): bool {
		if ( ! function_exists( 'PLL' ) || ! isset( PLL()->model ) || ! class_exists( Factory::class ) ) {
			return false;
		}

		$factory = new Factory( PLL()->model );
		$service = $factory->get_active_service();

		return null !== $service;
	}

	/**
	 * Runs Polylang's machine-translation pipeline for one post (routes to the
	 * active Supertext service). Creates and links the translated post.
	 *
	 * @param int    $post_id     Source post ID.
	 * @param string $target_lang Target language slug.
	 * @return bool
	 */
	private static function ai_translate( int $post_id, string $target_lang ): bool {
		if ( ! function_exists( 'PLL' ) || ! isset( PLL()->model ) ) {
			return false;
		}

		$polylang = PLL();
		$model    = $polylang->model;
		$post     = get_post( $post_id );
		$lang     = $model->get_language( $target_lang );

		if ( ! $post instanceof WP_Post || ! $lang ) {
			return false;
		}

		// Don't pre-create/translate if a translation already exists: Polylang's
		// exporter skips already-translated sources, which would yield an empty job.
		if ( $model->post->get_translation( $post_id, $target_lang ) ) {
			return false;
		}

		$factory = new Factory( $model );
		$service = $factory->get_active_service();
		if ( null === $service ) {
			return false;
		}

		$processor = new Processor( $polylang, $service->get_client() );
		$container = new PLL_Export_Container( Data::class );
		$export    = new PLL_Export_Data_From_Posts( $model );

		// No current language during the MT process (avoids filtering queries).
		$curlang_backup    = $polylang->curlang;
		$polylang->curlang = null;

		$export->send_to_export( $container, array( $post ), $lang );

		$result = $processor->translate( $container );
		if ( $result->has_errors() ) {
			$polylang->curlang = $curlang_backup;
			return false;
		}

		$result = $processor->save( $container );
		$polylang->curlang = $curlang_backup;

		if ( $result->has_errors() ) {
			return false;
		}

		return $model->post->get_translation( $post_id, $target_lang ) > 0;
	}

	/**
	 * Creates a linked draft copy for human translation and records the chosen
	 * Supertext product + delivery on it.
	 *
	 * @param int    $post_id     Source post ID.
	 * @param string $target_lang Target language slug.
	 * @param int    $service_id  Supertext product ID.
	 * @param string $express     Supertext delivery option.
	 * @return bool
	 */
	private static function create_human_draft( int $post_id, string $target_lang, int $service_id, string $express ): bool {
		if ( ! function_exists( 'PLL' ) || ! isset( PLL()->model ) ) {
			return false;
		}

		$model = PLL()->model;
		$post  = get_post( $post_id );
		$lang  = $model->get_language( $target_lang );

		if ( ! $post instanceof WP_Post || ! $lang ) {
			return false;
		}

		if ( $model->post->get_translation( $post_id, $target_lang ) ) {
			return false;
		}

		$new_post_id = wp_insert_post(
			array(
				'post_type'    => $post->post_type,
				'post_title'   => $post->post_title,
				'post_content' => $post->post_content,
				'post_status'  => 'draft',
			)
		);

		if ( is_wp_error( $new_post_id ) || ! $new_post_id ) {
			return false;
		}

		$model->post->set_language( $new_post_id, $lang );

		$translations = function_exists( 'pll_get_post_translations' ) ? pll_get_post_translations( $post_id ) : array();
		$source_lang  = $model->post->get_language( $post_id );
		if ( $source_lang ) {
			$translations[ $source_lang->slug ] = $post_id;
		}
		$translations[ $target_lang ] = $new_post_id;
		$model->post->save_translations( $new_post_id, $translations );

		// Record the chosen product + delivery so the order can be placed once the
		// Supertext human-translation (order) API is wired up. See HANDOFF.md §7/§9.
		update_post_meta( $new_post_id, '_supertext_service_id', $service_id );
		update_post_meta( $new_post_id, '_supertext_express', $express );

		return true;
	}

	/**
	 * Renders result / validation notices.
	 *
	 * @return void
	 */
	public static function render_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$error   = isset( $_GET['supertext_error'] ) ? sanitize_key( wp_unslash( $_GET['supertext_error'] ) ) : '';
		$action  = isset( $_GET['supertext_action'] ) ? sanitize_key( wp_unslash( $_GET['supertext_action'] ) ) : '';
		$created = isset( $_GET['supertext_created'] ) ? (int) $_GET['supertext_created'] : 0;
		$errors  = isset( $_GET['supertext_errors'] ) ? (int) $_GET['supertext_errors'] : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$messages = array(
			'no_lang'           => array( 'error', __( 'Please select a target language before applying a Supertext translation action.', 'supertext-polylang' ) ),
			'no_service'        => array( 'error', __( 'Please select a translation type before applying Supertext Human Translation.', 'supertext-polylang' ) ),
			'no_delivery'       => array( 'error', __( 'Please select a delivery option before applying Supertext Human Translation.', 'supertext-polylang' ) ),
			'mt_not_configured' => array( 'error', __( 'No active Supertext machine-translation service. Configure it in Polylang → Languages → Settings → Machine Translation.', 'supertext-polylang' ) ),
		);

		if ( isset( $messages[ $error ] ) ) {
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				esc_attr( $messages[ $error ][0] ),
				esc_html( $messages[ $error ][1] )
			);
			return;
		}

		if ( '' === $action || ( ! $created && ! $errors ) ) {
			return;
		}

		$label = self::ACTION_AI === $action
			? __( 'AI Translation', 'supertext-polylang' )
			: __( 'Human Translation', 'supertext-polylang' );

		if ( $created ) {
			$template = self::ACTION_AI === $action
				/* translators: 1: number of posts, 2: translation type */
				? _n( '%1$d translation created via Supertext %2$s.', '%1$d translations created via Supertext %2$s.', $created, 'supertext-polylang' )
				/* translators: 1: number of posts, 2: translation type */
				: _n( '%1$d draft created for Supertext %2$s.', '%1$d drafts created for Supertext %2$s.', $created, 'supertext-polylang' );

			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html( sprintf( $template, $created, $label ) )
			);
		}

		if ( $errors ) {
			printf(
				'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: number of failures */
						_n( '%d post could not be submitted (already translated, or an error occurred).', '%d posts could not be submitted (already translated, or an error occurred).', $errors, 'supertext-polylang' ),
						$errors
					)
				)
			);
		}
	}
}
