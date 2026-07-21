<?php
/**
 * @package Supertext_Polylang
 */

namespace Supertext\Polylang\Integrations\GravityForms;

defined( 'ABSPATH' ) || exit;

use DOMDocument;
use DOMXPath;
use GFAPI;
use Supertext\Polylang\Admin\Bulk_Actions;
use Supertext\Polylang\Admin\Integrations;
use Supertext\Polylang\Admin\Settings;
use Supertext\Polylang\Human_Translation\Callback;
use Supertext\Polylang\Human_Translation\Client as Human_Client;
use Supertext\Polylang\Human_Translation\Content as Human_Content;
use Supertext\Polylang\Human_Translation\Orders as Human_Orders;

/**
 * Human (professional) translation of a Gravity Forms form's strings.
 *
 * Mirrors the post order flow ({@see \Supertext\Polylang\Admin\Bulk_Actions}) but
 * for form strings: it packages the unique source strings as the same
 * `<div data-pll-id="…">` HTML the post path uses, uploads it, and places an order
 * whose `ReferenceData` is typed `gf:{form_id}:{lang}:…`. When Supertext calls back,
 * {@see writeback()} downloads the Final file and writes each translation into
 * Polylang's store ({@see Strings}) — the same place the AI path and manual edits
 * write, so the front end and the editor stay in sync.
 *
 * The `entity` id in the shared HTML/parse is the **source string itself**, since
 * that's how Polylang keys string translations.
 *
 * @since 0.8.0
 */
class Human {
	/**
	 * ReferenceData entity type for Gravity Forms orders.
	 *
	 * @var string
	 */
	const TYPE = 'gf';

	/**
	 * admin-post action: place a human order for a form + language.
	 *
	 * @var string
	 */
	const ORDER_ACTION = 'supertext_polylang_gf_order';

	/**
	 * Option prefix holding per-form order state: `{ lang => {...} }`.
	 *
	 * @var string
	 */
	const ORDER_OPTION_PREFIX = 'supertext_polylang_gf_order_';

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_post_' . self::ORDER_ACTION, array( self::class, 'handle_order' ) );
		add_action( 'supertext_polylang_order_completed', array( self::class, 'writeback' ), 10, 5 );
	}

	/**
	 * Renders the "Order human translation" panel for the editor.
	 *
	 * @param int                                              $form_id   Form id.
	 * @param array<int, array{slug: string, name: string}>   $languages Target languages.
	 * @return void
	 */
	public static function render_panel( int $form_id, array $languages ): void {
		if ( empty( $languages ) ) {
			return;
		}
		if ( ! Settings::is_configured() ) {
			printf(
				'<p class="description">%s</p>',
				esc_html__( 'Add your Supertext account email and Order API Key on the Supertext settings page to order human translations.', 'supertext-polylang' )
			);
			return;
		}

		$state = self::order_state( $form_id );
		?>
		<h2 style="margin-top:1.5em;"><?php esc_html_e( 'Order human translation', 'supertext-polylang' ); ?></h2>
		<p class="description" style="max-width:820px;">
			<?php esc_html_e( 'Send this form to Supertext for professional translation. When it comes back, the translations are written into Polylang automatically — right here and under Languages → String translations.', 'supertext-polylang' ); ?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:1em;">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ORDER_ACTION ); ?>" />
			<input type="hidden" name="form_id" value="<?php echo esc_attr( (string) $form_id ); ?>" />
			<?php wp_nonce_field( self::ORDER_ACTION . '_' . $form_id ); ?>

			<label for="st-gf-order-lang" class="screen-reader-text"><?php esc_html_e( 'Target language', 'supertext-polylang' ); ?></label>
			<select name="lang" id="st-gf-order-lang">
				<?php foreach ( $languages as $lang ) : ?>
					<option value="<?php echo esc_attr( $lang['slug'] ); ?>"><?php echo esc_html( $lang['name'] ); ?></option>
				<?php endforeach; ?>
			</select>

			<label for="st-gf-order-service" class="screen-reader-text"><?php esc_html_e( 'Translation type', 'supertext-polylang' ); ?></label>
			<select name="service_id" id="st-gf-order-service">
				<?php foreach ( Bulk_Actions::HUMAN_SERVICES as $id => $service ) : ?>
					<option value="<?php echo esc_attr( (string) $id ); ?>"><?php echo esc_html( $service['label'] ); ?></option>
				<?php endforeach; ?>
			</select>

			<label for="st-gf-order-express" class="screen-reader-text"><?php esc_html_e( 'Delivery', 'supertext-polylang' ); ?></label>
			<select name="express" id="st-gf-order-express">
				<?php foreach ( Bulk_Actions::EXPRESS_OPTIONS as $id => $label ) : ?>
					<option value="<?php echo esc_attr( (string) $id ); ?>"><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>

			<?php submit_button( __( 'Order human translation', 'supertext-polylang' ), 'secondary', 'submit', false ); ?>
		</form>

		<?php if ( ! empty( $state ) ) : ?>
			<table class="widefat striped" style="max-width:640px;margin-bottom:1.5em;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Language', 'supertext-polylang' ); ?></th>
						<th><?php esc_html_e( 'Order', 'supertext-polylang' ); ?></th>
						<th><?php esc_html_e( 'Status', 'supertext-polylang' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $languages as $lang ) : ?>
						<?php
						$entry = $state[ $lang['slug'] ] ?? null;
						if ( ! is_array( $entry ) ) {
							continue;
						}
						$completed = ! empty( $entry['completed_at'] );
						?>
						<tr>
							<td><?php echo esc_html( $lang['name'] ); ?></td>
							<td>
								<?php
								foreach ( (array) ( $entry['order_ids'] ?? array() ) as $oid ) {
									printf(
										'<a href="%s" target="_blank" rel="noopener">%d</a> ',
										esc_url( Settings::order_url( (int) $oid ) ),
										(int) $oid
									);
								}
								?>
							</td>
							<td>
								<?php if ( $completed ) : ?>
									<span style="color:#00a32a;">&#10003; <?php esc_html_e( 'Completed', 'supertext-polylang' ); ?></span>
								<?php else : ?>
									<span style="color:#dba617;">&#9203; <?php esc_html_e( 'In progress', 'supertext-polylang' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	/**
	 * Handles the order submission (admin-post).
	 *
	 * @return void
	 */
	public static function handle_order(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'supertext-polylang' ) );
		}

		$form_id = isset( $_POST['form_id'] ) ? (int) $_POST['form_id'] : 0;
		check_admin_referer( self::ORDER_ACTION . '_' . $form_id );

		$lang       = isset( $_POST['lang'] ) ? sanitize_key( wp_unslash( $_POST['lang'] ) ) : '';
		$service_id = isset( $_POST['service_id'] ) ? (int) $_POST['service_id'] : 0;
		$express    = isset( $_POST['express'] ) ? sanitize_key( wp_unslash( $_POST['express'] ) ) : '';

		$result = self::submit_order( $form_id, $lang, $service_id, $express );

		$args = array(
			'page'    => Admin_Page::SLUG,
			'form_id' => $form_id,
		);
		if ( is_wp_error( $result ) ) {
			set_transient( 'supertext_polylang_gf_order_error_' . get_current_user_id(), $result->get_error_message(), 60 );
			$args['order_error'] = '1';
		} else {
			$args['ordered'] = '1';
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Validates, packages, and places the order.
	 *
	 * @param int    $form_id    Form id.
	 * @param string $lang       Target language slug.
	 * @param int    $service_id OrderTypeConfigurationId.
	 * @param string $express    DeliveryId.
	 * @return int[]|\WP_Error Order id(s) on success.
	 */
	private static function submit_order( int $form_id, string $lang, int $service_id, string $express ) {
		if ( ! Integrations::enabled( 'gravityforms' ) || ! class_exists( 'GFAPI' ) ) {
			return new \WP_Error( 'supertext_gf_unavailable', __( 'Gravity Forms is not available.', 'supertext-polylang' ) );
		}
		if ( ! Settings::is_configured() ) {
			return new \WP_Error( 'supertext_gf_not_configured', __( 'Supertext human-translation credentials are not configured.', 'supertext-polylang' ) );
		}
		if ( ! isset( Bulk_Actions::HUMAN_SERVICES[ $service_id ] ) ) {
			return new \WP_Error( 'supertext_gf_no_service', __( 'Please choose a translation type.', 'supertext-polylang' ) );
		}
		if ( ! isset( Bulk_Actions::EXPRESS_OPTIONS[ $express ] ) ) {
			return new \WP_Error( 'supertext_gf_no_delivery', __( 'Please choose a delivery option.', 'supertext-polylang' ) );
		}
		if ( ! function_exists( 'PLL' ) || ! isset( PLL()->model ) ) {
			return new \WP_Error( 'supertext_gf_no_pll', __( 'Polylang is not available.', 'supertext-polylang' ) );
		}

		$model  = PLL()->model;
		$target = $model->get_language( $lang );
		$source = function_exists( 'pll_default_language' ) ? $model->get_language( (string) pll_default_language( 'slug' ) ) : null;
		if ( ! $target || ! $source ) {
			return new \WP_Error( 'supertext_gf_bad_lang', __( 'Unknown source or target language.', 'supertext-polylang' ) );
		}

		// Block a duplicate order while one is still in progress for this language.
		$state = self::order_state( $form_id );
		if ( isset( $state[ $lang ] ) && empty( $state[ $lang ]['completed_at'] ) ) {
			return new \WP_Error(
				'supertext_gf_already_ordered',
				/* translators: %s is the language slug. */
				sprintf( __( 'A human order for %s is already in progress.', 'supertext-polylang' ), $lang )
			);
		}

		$form = GFAPI::get_form( $form_id );
		if ( empty( $form ) ) {
			return new \WP_Error( 'supertext_gf_no_form', __( 'Form not found.', 'supertext-polylang' ) );
		}

		$html = self::build_html( $form );
		if ( '' === $html ) {
			return new \WP_Error( 'supertext_gf_empty', __( 'This form has no translatable text.', 'supertext-polylang' ) );
		}

		$client      = new Human_Client();
		$title       = (string) $form['title'];
		$filename    = sanitize_file_name( '' !== $title ? $title : 'form-' . $form_id ) . '.html';
		$document_id = $client->upload_file( $html, $filename );
		if ( is_wp_error( $document_id ) ) {
			return $document_id;
		}

		$order = array(
			'DeliveryId'               => (int) $express,
			'OrderName'                => 'Gravity Forms: ' . $title,
			'OrderTypeConfigurationId' => $service_id,
			'ContentType'              => 'text/html',
			'Referrer'                 => 'Supertext for Polylang',
			'SystemName'               => 'WordPress',
			'SystemVersion'            => get_bloginfo( 'version' ),
			'ComponentName'            => 'supertext-polylang',
			'ComponentVersion'         => SUPERTEXT_POLYLANG_VERSION,
			'SourceLang'               => (string) strtok( (string) $source->w3c, '-' ),
			'TargetLanguages'          => array( (string) $target->w3c ),
			'ReferenceData'            => Callback::reference_data_for( self::TYPE, $form_id, $lang ),
			'CallbackUrl'              => Callback::url(),
			'Files'                    => array(
				array(
					'Comment' => 'Gravity Forms strings',
					'Id'      => (int) $document_id,
				),
			),
		);

		$result = $client->create_order( $order );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$order_ids = array();
		if ( is_array( $result ) ) {
			foreach ( $result as $entry ) {
				if ( is_array( $entry ) && isset( $entry['Id'] ) ) {
					$order_ids[] = (int) $entry['Id'];
				}
			}
		}

		// Record in the shared Orders registry (post_id 0 = not a post).
		foreach ( $order_ids as $oid ) {
			Human_Orders::record(
				array(
					'order_id'    => $oid,
					'post_id'     => 0,
					'kind'        => 'gravityforms',
					'form_id'     => $form_id,
					'lang'        => $lang,
					'target'      => (string) $target->w3c,
					'type_id'     => $service_id,
					'delivery_id' => (int) $express,
					'order_name'  => 'Gravity Forms: ' . $title,
					'status'      => 'New',
				)
			);
		}

		// Track per-form state for the editor's status table + dedup.
		self::set_order_state(
			$form_id,
			$lang,
			array(
				'order_ids'   => $order_ids,
				'document_id' => (int) $document_id,
				'service_id'  => $service_id,
				'delivery_id' => (int) $express,
				'target'      => (string) $target->w3c,
				'ordered_at'  => gmdate( 'c' ),
				'completed_at' => null,
			)
		);

		return $order_ids;
	}

	/**
	 * Writes a completed Gravity Forms order back into Polylang.
	 *
	 * Hooked on `supertext_polylang_order_completed`; ignores non-`gf` orders.
	 *
	 * @param int[]  $order_ids Completed order id(s).
	 * @param int    $form_id   Form id (from ReferenceData).
	 * @param string $lang      Target language slug.
	 * @param mixed  $body      Raw decoded callback body.
	 * @param string $type      Entity type.
	 * @return void
	 */
	public static function writeback( $order_ids, $form_id, $lang, $body, $type = 'post' ): void {
		if ( self::TYPE !== $type ) {
			return;
		}

		$files = self::final_files( $body );
		if ( empty( $files ) ) {
			self::debug_log( 'gf writeback: no Final files in callback' );
			return;
		}

		$client = new Human_Client();
		$pairs  = array();
		foreach ( $files as $file ) {
			$html = $client->download_file( (int) $file['Id'], (string) $file['Name'] );
			if ( is_wp_error( $html ) ) {
				self::debug_log( sprintf( 'gf writeback: download failed for file %d: %s', (int) $file['Id'], $html->get_error_message() ) );
				continue;
			}
			$pairs += self::parse_html( $html );
		}

		if ( ! empty( $pairs ) ) {
			Strings::save_translations( (string) $lang, $pairs );
		}

		// Mark the order complete for the editor status + registry.
		$state = self::order_state( (int) $form_id );
		if ( isset( $state[ $lang ] ) && is_array( $state[ $lang ] ) ) {
			$state[ $lang ]['completed_at'] = gmdate( 'c' );
			$state[ $lang ]['strings']      = count( $pairs );
			self::save_state( (int) $form_id, $state );
		}

		$status = is_array( $body ) && ! empty( $body['Status'] ) ? (string) $body['Status'] : 'Completed';
		foreach ( (array) $order_ids as $oid ) {
			Human_Orders::update( (int) $oid, array( 'status' => $status, 'completed_at' => gmdate( 'c' ) ) );
		}

		self::debug_log( sprintf( 'gf writeback done: form=%d lang=%s strings=%d', (int) $form_id, $lang, count( $pairs ) ) );
	}

	/**
	 * Builds the upload HTML: each unique source string wrapped by its own base64 id.
	 *
	 * @param array $form Gravity Forms form.
	 * @return string
	 */
	private static function build_html( array $form ): string {
		$entries = array();
		foreach ( Strings::collect_unique( $form ) as $item ) {
			$entries[] = array(
				'context'  => $item['source'],
				'singular' => $item['source'],
			);
		}
		return empty( $entries ) ? '' : Human_Content::render( $entries );
	}

	/**
	 * Parses translated HTML into a source => translation map.
	 *
	 * The `data-pll-id` is the base64-encoded **source string** (see build_html).
	 *
	 * @param string $html Translated HTML.
	 * @return array<string, string>
	 */
	private static function parse_html( string $html ): array {
		$out = array();

		$dom      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$xpath = new DOMXPath( $dom );
		$nodes = $xpath->query( '//div[@data-pll-id]' );
		if ( false === $nodes ) {
			return $out;
		}

		foreach ( $nodes as $node ) {
			if ( ! $node instanceof \DOMElement ) {
				continue;
			}
			$source = base64_decode( $node->getAttribute( 'data-pll-id' ), true );
			if ( false === $source || '' === $source ) {
				continue;
			}
			$inner = '';
			foreach ( $node->childNodes as $child ) {
				$inner .= (string) $dom->saveHTML( $child );
			}
			$translation = trim( $inner );
			if ( '' !== $translation ) {
				$out[ $source ] = $translation;
			}
		}

		return $out;
	}

	/**
	 * Returns the Final (translated) files from the callback body.
	 *
	 * @param mixed $body Decoded callback body.
	 * @return array[] List of { Id, Name }.
	 */
	private static function final_files( $body ): array {
		$files = array();
		if ( ! is_array( $body ) || empty( $body['Files'] ) || ! is_array( $body['Files'] ) ) {
			return $files;
		}
		foreach ( $body['Files'] as $file ) {
			if ( ! is_array( $file ) || ! isset( $file['Id'] ) ) {
				continue;
			}
			if ( ( $file['DocumentType'] ?? '' ) !== 'Final' ) {
				continue;
			}
			$files[] = array(
				'Id'   => (int) $file['Id'],
				'Name' => (string) ( $file['Name'] ?? ( $file['Id'] . '.html' ) ),
			);
		}
		return $files;
	}

	/**
	 * Returns the per-form order state map: `{ lang => {...} }`.
	 *
	 * @param int $form_id Form id.
	 * @return array<string, array>
	 */
	public static function order_state( int $form_id ): array {
		$state = get_option( self::ORDER_OPTION_PREFIX . $form_id, array() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * Persists one language's order state.
	 *
	 * @param int    $form_id Form id.
	 * @param string $lang    Language slug.
	 * @param array  $entry   State entry.
	 * @return void
	 */
	private static function set_order_state( int $form_id, string $lang, array $entry ): void {
		$state          = self::order_state( $form_id );
		$state[ $lang ] = $entry;
		self::save_state( $form_id, $state );
	}

	/**
	 * Saves the whole per-form state map.
	 *
	 * @param int                   $form_id Form id.
	 * @param array<string, array>  $state   State map.
	 * @return void
	 */
	private static function save_state( int $form_id, array $state ): void {
		update_option( self::ORDER_OPTION_PREFIX . $form_id, $state, false );
	}

	/**
	 * Logs a diagnostic line when WP_DEBUG is on.
	 *
	 * @param string $message The message.
	 * @return void
	 */
	private static function debug_log( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[supertext-polylang][gf-human] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
