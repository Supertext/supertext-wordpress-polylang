<?php
/**
 * @package Supertext_Polylang
 */

namespace Supertext\Polylang\Admin;

defined( 'ABSPATH' ) || exit;

use WP_Error;
use WP_Post;
use WP_Screen;
use PLL_Export_Container;
use PLL_Export_Data_From_Posts;
use Supertext\Polylang\Human_Translation\Callback as Human_Callback;
use Supertext\Polylang\Human_Translation\Client as Human_Client;
use Supertext\Polylang\Human_Translation\Content as Human_Content;
use Supertext\Polylang\Human_Translation\Orders as Human_Orders;
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

	/** User meta storing the last-selected order options (to pre-fill next time). */
	const PREFS_META = 'supertext_polylang_last_order';

	/** Human translation product types (Supertext OrderTypeConfigurationId => label). */
	const HUMAN_SERVICES = array(
		166 => 'Übersetzung BASIC',
		167 => 'Übersetzung PREMIUM',
		168 => 'Übersetzung CREATIVE',
	);

	/** Human translation delivery options (Supertext DeliveryId => label). */
	const EXPRESS_OPTIONS = array(
		'1' => 'Express',
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
		$languages   = self::get_polylang_languages();
		$prefs       = self::get_user_prefs();
		$sel_lang    = (string) ( $prefs['lang'] ?? '' );
		$sel_service = (int) ( $prefs['service_id'] ?? 0 );
		$sel_express = (string) ( $prefs['express'] ?? '' );
		?>
		<span id="supertext-lang-picker" style="display:none;">
			<label for="supertext_target_lang" class="screen-reader-text"><?php esc_html_e( 'Target language', 'supertext-polylang' ); ?></label>
			<select name="supertext_target_lang" id="supertext_target_lang">
				<option value=""><?php esc_html_e( 'Target language', 'supertext-polylang' ); ?></option>
				<?php foreach ( $languages as $slug => $name ) : ?>
					<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $sel_lang, $slug ); ?>><?php echo esc_html( $name ); ?></option>
				<?php endforeach; ?>
			</select>
		</span>
		<span id="supertext-service-picker" style="display:none;">
			<label for="supertext_service_id" class="screen-reader-text"><?php esc_html_e( 'Translation type', 'supertext-polylang' ); ?></label>
			<select name="supertext_service_id" id="supertext_service_id">
				<option value=""><?php esc_html_e( 'Translation type', 'supertext-polylang' ); ?></option>
				<?php foreach ( self::HUMAN_SERVICES as $id => $label ) : ?>
					<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $sel_service, $id ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</span>
		<span id="supertext-express-picker" style="display:none;">
			<label for="supertext_express" class="screen-reader-text"><?php esc_html_e( 'Delivery', 'supertext-polylang' ); ?></label>
			<select name="supertext_express" id="supertext_express">
				<option value=""><?php esc_html_e( 'Delivery', 'supertext-polylang' ); ?></option>
				<?php foreach ( self::EXPRESS_OPTIONS as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $sel_express, $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</span>
		<?php
	}

	/**
	 * Returns the current user's last-selected order options.
	 *
	 * @return array{lang?: string, service_id?: int, express?: string}
	 */
	private static function get_user_prefs(): array {
		$prefs = get_user_meta( get_current_user_id(), self::PREFS_META, true );
		return is_array( $prefs ) ? $prefs : array();
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
			if ( ! Settings::is_configured() ) {
				return add_query_arg( 'supertext_error', 'human_not_configured', $redirect_url );
			}
			$service_id = (int) ( $_REQUEST['supertext_service_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( ! isset( self::HUMAN_SERVICES[ $service_id ] ) ) {
				return add_query_arg( 'supertext_error', 'no_service', $redirect_url );
			}
			$express = sanitize_key( wp_unslash( $_REQUEST['supertext_express'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( ! isset( self::EXPRESS_OPTIONS[ $express ] ) ) {
				return add_query_arg( 'supertext_error', 'no_delivery', $redirect_url );
			}
		}

		// Remember the user's selections to pre-fill the dropdowns next time.
		$prefs         = self::get_user_prefs();
		$prefs['lang'] = $target_lang;
		if ( self::ACTION_HUMAN === $action ) {
			$prefs['service_id'] = $service_id;
			$prefs['express']    = $express;
		}
		update_user_meta( get_current_user_id(), self::PREFS_META, $prefs );

		$created        = 0;
		$errors         = 0;
		$error_messages = array();
		$order_ids_all  = array();

		foreach ( $post_ids as $post_id ) {
			$res = self::ACTION_AI === $action
				? self::ai_translate( (int) $post_id, $target_lang )
				: self::submit_human_order( (int) $post_id, $target_lang, $service_id, $express );

			if ( $res instanceof WP_Error || false === $res ) {
				$errors++;
				if ( is_wp_error( $res ) ) {
					$error_messages[] = $res->get_error_message();
				}
			} else {
				$created++;
				if ( is_array( $res ) ) {
					$order_ids_all = array_merge( $order_ids_all, $res );
				}
			}
		}

		if ( ! empty( $error_messages ) ) {
			set_transient( 'supertext_polylang_bulk_errors_' . get_current_user_id(), array_values( array_unique( $error_messages ) ), 60 );
		}

		if ( ! empty( $order_ids_all ) ) {
			set_transient( 'supertext_polylang_bulk_orders_' . get_current_user_id(), array_values( array_map( 'intval', $order_ids_all ) ), 60 );
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
	 * Places a human-translation order with Supertext: builds the content HTML,
	 * uploads it, and creates the order. Response handling (the callback) is wired
	 * up separately.
	 *
	 * @param int    $post_id     Source post ID.
	 * @param string $target_lang Target language slug.
	 * @param int    $service_id  Supertext OrderTypeConfigurationId.
	 * @param string $express     Supertext DeliveryId.
	 * @return int[]|WP_Error Order id(s) on success.
	 */
	private static function submit_human_order( int $post_id, string $target_lang, int $service_id, string $express ) {
		if ( ! function_exists( 'PLL' ) || ! isset( PLL()->model ) ) {
			return new WP_Error( 'supertext_no_pll', __( 'Polylang is not available.', 'supertext-polylang' ) );
		}

		$model  = PLL()->model;
		$post   = get_post( $post_id );
		$lang   = $model->get_language( $target_lang );
		$source = $model->post->get_language( $post_id );

		if ( ! $post instanceof WP_Post || ! $lang || ! $source ) {
			return new WP_Error( 'supertext_invalid', __( 'Invalid post, or missing source/target language.', 'supertext-polylang' ) );
		}

		// Avoid placing a duplicate (paid) order for the same target language.
		if ( get_post_meta( $post_id, '_supertext_order_' . $target_lang, true ) ) {
			return new WP_Error(
				'supertext_already_ordered',
				sprintf(
					/* translators: 1: post title, 2: language slug. */
					__( 'An order already exists for "%1$s" (%2$s).', 'supertext-polylang' ),
					get_the_title( $post_id ),
					$target_lang
				)
			);
		}

		$html = Human_Content::build_html( $post, $lang, $model );
		if ( '' === trim( wp_strip_all_tags( $html ) ) ) {
			return new WP_Error( 'supertext_empty', __( 'No translatable content found in this post.', 'supertext-polylang' ) );
		}

		$title    = '' !== $post->post_title ? $post->post_title : 'post-' . $post_id;
		$filename = sanitize_file_name( $title );
		$filename = ( '' !== $filename ? $filename : 'content-' . $post_id ) . '.html';

		$client = new Human_Client();

		$document_id = $client->upload_file( $html, $filename );
		if ( is_wp_error( $document_id ) ) {
			return $document_id;
		}

		$order = array(
			'DeliveryId'               => (int) $express,
			'OrderName'                => $title,
			'OrderTypeConfigurationId' => $service_id,
			'ContentType'              => 'text/html',
			'Referrer'                 => 'Supertext for Polylang',
			'SystemName'               => 'WordPress',
			'SystemVersion'            => get_bloginfo( 'version' ),
			'ComponentName'            => 'supertext-polylang',
			'ComponentVersion'         => SUPERTEXT_POLYLANG_VERSION,
			'SourceLang'               => (string) strtok( (string) $source->w3c, '-' ), // 2-letter primary subtag.
			'TargetLanguages'          => array( $lang->w3c ),
			'ReferenceData'            => Human_Callback::reference_data( $post_id, $target_lang ),
			'CallbackUrl'              => Human_Callback::url(),
			'Files'                    => array(
				array(
					'Comment' => 'WordPress content',
					'Id'      => $document_id,
				),
			),
		);

		$result = $client->create_order( $order );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// The order endpoint returns an array of order objects (one per target
		// language). Capture the order id(s) so we can reconcile on callback.
		$order_ids = array();
		if ( is_array( $result ) ) {
			foreach ( $result as $entry ) {
				if ( is_array( $entry ) && isset( $entry['Id'] ) ) {
					$order_ids[] = (int) $entry['Id'];
				}
			}
		}

		// Add each order to the registry shown on the Orders admin page.
		foreach ( $order_ids as $oid ) {
			Human_Orders::record(
				array(
					'order_id'    => $oid,
					'post_id'     => $post_id,
					'lang'        => $target_lang,
					'target'      => $lang->w3c,
					'type_id'     => $service_id,
					'delivery_id' => (int) $express,
					'order_name'  => $title,
					'status'      => 'New',
				)
			);
		}

		// Record the order so we can reconcile it when the callback is implemented.
		update_post_meta(
			$post_id,
			'_supertext_order_' . $target_lang,
			wp_json_encode(
				array(
					'order_ids'   => $order_ids,
					'document_id' => $document_id,
					'service_id'  => $service_id,
					'delivery_id' => (int) $express,
					'target'      => $lang->w3c,
					'ordered_at'  => gmdate( 'c' ),
				)
			)
		);

		return $order_ids;
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
			'mt_not_configured'    => array( 'error', __( 'No active Supertext machine-translation service. Configure it in Polylang → Languages → Settings → Machine Translation.', 'supertext-polylang' ) ),
			'human_not_configured' => array( 'error', __( 'Supertext human-translation credentials are not configured. Add your account email and Legacy API Key on the Supertext settings page.', 'supertext-polylang' ) ),
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
				: _n( '%1$d order submitted to Supertext %2$s.', '%1$d orders submitted to Supertext %2$s.', $created, 'supertext-polylang' );

			$message = sprintf( $template, $created, $label );

			if ( self::ACTION_HUMAN === $action ) {
				$order_key = 'supertext_polylang_bulk_orders_' . get_current_user_id();
				$order_ids = get_transient( $order_key );
				delete_transient( $order_key );

				if ( is_array( $order_ids ) && ! empty( $order_ids ) ) {
					$message .= ' ' . sprintf(
						/* translators: %s is a comma-separated list of order ids. */
						_n( 'Order ID: %s', 'Order IDs: %s', count( $order_ids ), 'supertext-polylang' ),
						implode( ', ', array_map( 'intval', $order_ids ) )
					);
				}
			}

			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html( $message )
			);
		}

		if ( $errors ) {
			$detail_key = 'supertext_polylang_bulk_errors_' . get_current_user_id();
			$details    = get_transient( $detail_key );
			delete_transient( $detail_key );

			$list = '';
			if ( is_array( $details ) && ! empty( $details ) ) {
				$items = '';
				foreach ( $details as $message ) {
					$items .= '<li>' . esc_html( $message ) . '</li>';
				}
				$list = '<ul style="list-style:disc;margin-left:1.5em;">' . $items . '</ul>';
			}

			printf(
				'<div class="notice notice-error is-dismissible"><p>%s</p>%s</div>',
				esc_html(
					sprintf(
						/* translators: number of failures */
						_n( '%d item could not be submitted:', '%d items could not be submitted:', $errors, 'supertext-polylang' ),
						$errors
					)
				),
				$list // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each message escaped above.
			);
		}
	}
}
