<?php
/**
 * @package Supertext_Polylang
 */

namespace Supertext\Polylang\Admin;

defined( 'ABSPATH' ) || exit;

use Supertext\Polylang\Polylang\String_Store;

/**
 * Shared editor UI for translating a set of Polylang strings.
 *
 * Renders a form: a checkbox per row, one editable translation column per target
 * language, and a bottom action bar. The two translate actions (AI and human) act
 * only on the **checked** rows, for the chosen target language; a Save action writes
 * the whole grid. Used by both the Gravity Forms per-form editor and the general
 * String Translation page; each supplies its own admin-post handler that reads the
 * submission with {@see read_submit()} and does the work.
 *
 * Rows are `['name' => , 'group' => (optional), 'source' => ]`. A hidden `src[i]`
 * carries each source so the handler maps a row back to its string independently of
 * render order.
 *
 * @since 0.9.0
 */
class String_Table {
	/**
	 * Renders the editor form.
	 *
	 * @param array $args {
	 *     @type string                                    $action       admin-post action name.
	 *     @type string                                    $nonce_action Nonce action.
	 *     @type array<string,string>                      $hidden       Extra hidden inputs (name => value).
	 *     @type array<int,array{name:string,group?:string,source:string}> $rows Rows.
	 *     @type array<int,array{slug:string,name:string}> $languages    Target languages.
	 *     @type array<string,array<string,string>>        $translations lang slug => (source => translation).
	 *     @type bool                                      $show_group   Show a Group column.
	 *     @type bool                                      $human        Show the human-order controls.
	 *     @type array<int,array{label:string}>            $human_services OrderTypeConfigurationId => service.
	 *     @type array<string,string>                      $express_options DeliveryId => label.
	 * }
	 * @return void
	 */
	public static function render( array $args ): void {
		$rows         = $args['rows'] ?? array();
		$languages    = $args['languages'] ?? array();
		$translations = $args['translations'] ?? array();
		$show_group   = ! empty( $args['show_group'] );
		$human        = ! empty( $args['human'] );

		if ( empty( $languages ) ) {
			echo '<p><em>' . esc_html__( 'Add at least one non-default language in Polylang to translate strings.', 'supertext-polylang' ) . '</em></p>';
			return;
		}
		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No strings to translate.', 'supertext-polylang' ) . '</p>';
			return;
		}

		$colspan = 2 + ( $show_group ? 1 : 0 ) + count( $languages );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( (string) $args['action'] ); ?>" />
			<?php foreach ( (array) ( $args['hidden'] ?? array() ) as $name => $value ) : ?>
				<input type="hidden" name="<?php echo esc_attr( (string) $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>" />
			<?php endforeach; ?>
			<?php wp_nonce_field( (string) $args['nonce_action'] ); ?>

			<table class="widefat striped fixed">
				<thead>
					<tr>
						<td style="width:2.5em;"><input type="checkbox" class="st-check-all" title="<?php esc_attr_e( 'Select all', 'supertext-polylang' ); ?>" /></td>
						<th style="width:12%;"><?php esc_html_e( 'Field', 'supertext-polylang' ); ?></th>
						<?php if ( $show_group ) : ?>
							<th style="width:16%;"><?php esc_html_e( 'Group', 'supertext-polylang' ); ?></th>
						<?php endif; ?>
						<th style="width:24%;"><?php esc_html_e( 'Source', 'supertext-polylang' ); ?></th>
						<?php foreach ( $languages as $lang ) : ?>
							<th><?php echo esc_html( $lang['name'] ); ?></th>
						<?php endforeach; ?>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $i => $row ) : ?>
						<tr>
							<td><input type="checkbox" class="st-row-check" name="sel[]" value="<?php echo (int) $i; ?>" /></td>
							<td><span style="color:#787c82;"><?php echo esc_html( (string) ( $row['name'] ?? '' ) ); ?></span></td>
							<?php if ( $show_group ) : ?>
								<td><span style="color:#787c82;"><?php echo esc_html( (string) ( $row['group'] ?? '' ) ); ?></span></td>
							<?php endif; ?>
							<td>
								<?php echo esc_html( (string) $row['source'] ); ?>
								<input type="hidden" name="src[<?php echo (int) $i; ?>]" value="<?php echo esc_attr( (string) $row['source'] ); ?>" />
							</td>
							<?php foreach ( $languages as $lang ) : ?>
								<?php $slug = $lang['slug']; ?>
								<td>
									<textarea
										name="tr[<?php echo esc_attr( $slug ); ?>][<?php echo (int) $i; ?>]"
										rows="2"
										style="width:100%;box-sizing:border-box;"
									><?php echo esc_textarea( (string) ( $translations[ $slug ][ $row['source'] ] ?? '' ) ); ?></textarea>
								</td>
							<?php endforeach; ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<div style="margin:1em 0;padding:1em;background:#fff;border:1px solid #dcdcde;display:flex;flex-wrap:wrap;gap:1.5em;align-items:flex-end;">
				<div>
					<label for="st-target-lang" style="display:block;font-weight:600;"><?php esc_html_e( 'Target language', 'supertext-polylang' ); ?></label>
					<select name="lang" id="st-target-lang">
						<?php foreach ( $languages as $lang ) : ?>
							<option value="<?php echo esc_attr( $lang['slug'] ); ?>"><?php echo esc_html( $lang['name'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>

				<div>
					<button type="submit" name="st_do" value="ai" class="button button-primary">
						<?php esc_html_e( 'Translate selected with AI', 'supertext-polylang' ); ?>
					</button>
				</div>

				<?php if ( $human ) : ?>
					<div>
						<label for="st-service" style="display:block;font-weight:600;"><?php esc_html_e( 'Human: type', 'supertext-polylang' ); ?></label>
						<select name="service_id" id="st-service">
							<?php foreach ( (array) ( $args['human_services'] ?? array() ) as $id => $service ) : ?>
								<option value="<?php echo esc_attr( (string) $id ); ?>"><?php echo esc_html( (string) ( $service['label'] ?? $id ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div>
						<label for="st-express" style="display:block;font-weight:600;"><?php esc_html_e( 'Delivery', 'supertext-polylang' ); ?></label>
						<select name="express" id="st-express">
							<?php foreach ( (array) ( $args['express_options'] ?? array() ) as $id => $label ) : ?>
								<option value="<?php echo esc_attr( (string) $id ); ?>"><?php echo esc_html( (string) $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div>
						<button type="submit" name="st_do" value="human" class="button">
							<?php esc_html_e( 'Order human translation for selected', 'supertext-polylang' ); ?>
						</button>
					</div>
				<?php endif; ?>

				<div style="margin-left:auto;">
					<button type="submit" name="st_do" value="save" class="button">
						<?php esc_html_e( 'Save changes', 'supertext-polylang' ); ?>
					</button>
				</div>
			</div>
		</form>
		<?php
	}

	/**
	 * Reads a submitted table form (call after verifying nonce + capability).
	 *
	 * @return array{do:string, lang:string, service_id:int, express:string, src:array<int,string>, grid:array<string,array<int,string>>, selected:int[]}
	 */
	public static function read_submit(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- caller verifies the nonce.
		$do         = isset( $_POST['st_do'] ) ? sanitize_key( wp_unslash( $_POST['st_do'] ) ) : 'save';
		$lang       = isset( $_POST['lang'] ) ? sanitize_key( wp_unslash( $_POST['lang'] ) ) : '';
		$service_id = isset( $_POST['service_id'] ) ? (int) $_POST['service_id'] : 0;
		$express    = isset( $_POST['express'] ) ? sanitize_key( wp_unslash( $_POST['express'] ) ) : '';
		$src        = ( isset( $_POST['src'] ) && is_array( $_POST['src'] ) ) ? wp_unslash( $_POST['src'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$grid       = ( isset( $_POST['tr'] ) && is_array( $_POST['tr'] ) ) ? wp_unslash( $_POST['tr'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$sel        = ( isset( $_POST['sel'] ) && is_array( $_POST['sel'] ) ) ? array_map( 'intval', (array) $_POST['sel'] ) : array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$src_clean = array();
		foreach ( $src as $i => $value ) {
			$src_clean[ (int) $i ] = (string) $value;
		}

		return array(
			'do'         => in_array( $do, array( 'ai', 'human', 'save' ), true ) ? $do : 'save',
			'lang'       => $lang,
			'service_id' => $service_id,
			'express'    => $express,
			'src'        => $src_clean,
			'grid'       => $grid,
			'selected'   => array_values( array_unique( $sel ) ),
		);
	}

	/**
	 * Saves the whole grid into Polylang (all languages, non-empty cells).
	 *
	 * @param array<string,array<int,string>> $grid lang => (i => value).
	 * @param array<int,string>               $src  i => source string.
	 * @return void
	 */
	public static function save_grid( array $grid, array $src ): void {
		foreach ( $grid as $lang => $rows ) {
			$lang = sanitize_key( $lang );
			if ( '' === $lang || ! is_array( $rows ) ) {
				continue;
			}
			$pairs = array();
			foreach ( $rows as $i => $value ) {
				$source = $src[ (int) $i ] ?? '';
				if ( '' === $source ) {
					continue;
				}
				$pairs[ $source ] = wp_kses_post( (string) $value );
			}
			String_Store::save_translations( $lang, $pairs );
		}
	}

	/**
	 * Maps the checked row indexes to their source strings.
	 *
	 * @param int[]              $selected Checked indexes.
	 * @param array<int,string>  $src      i => source.
	 * @return string[]
	 */
	public static function selected_sources( array $selected, array $src ): array {
		$out = array();
		foreach ( $selected as $i ) {
			$source = $src[ (int) $i ] ?? '';
			if ( '' !== $source ) {
				$out[] = $source;
			}
		}
		return array_values( array_unique( $out ) );
	}
}
