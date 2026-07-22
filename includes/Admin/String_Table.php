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
 * Renders a Supertext-styled toolbar card (optional filter slot + target-language /
 * human pickers + "Translate with AI" and human-order buttons) followed by a table
 * card: a checkbox per row, the group as a chip, and a rounded translation input per
 * target language. The two translate actions act on the **checked** rows for the
 * chosen target language; a Save action writes the whole grid. Used by both the
 * Gravity Forms per-form editor and the general String Translation page.
 *
 * The action controls live in the toolbar card but submit the POST form (which wraps
 * the table) via the HTML5 `form="…"` attribute, so the two cards render separately.
 *
 * @since 0.9.0
 */
class String_Table {
	/**
	 * Form id shared by the toolbar controls and the table form.
	 *
	 * @var string
	 */
	const FORM_ID = 'supertext-string-form';

	/**
	 * Renders the editor.
	 *
	 * @param array $args {
	 *     @type string                                    $action          admin-post action name.
	 *     @type string                                    $nonce_action    Nonce action.
	 *     @type array<string,string>                      $hidden          Extra hidden inputs.
	 *     @type array<int,array{name:string,group?:string,source:string}> $rows Rows.
	 *     @type array<int,array{slug:string,name:string}> $languages       Target languages.
	 *     @type array<string,array<string,string>>        $translations    lang => (source => translation).
	 *     @type bool                                      $show_group      Show a Group column.
	 *     @type bool                                      $human           Show the human-order controls.
	 *     @type array<int,array{label:string}>            $human_services  OrderTypeConfigurationId => service.
	 *     @type array<string,string>                      $express_options DeliveryId => label.
	 *     @type string                                    $filter_html     Pre-escaped filter row markup (optional).
	 * }
	 * @return void
	 */
	public static function render( array $args ): void {
		$rows         = $args['rows'] ?? array();
		$languages    = $args['languages'] ?? array();
		$translations = $args['translations'] ?? array();
		$show_group   = ! empty( $args['show_group'] );
		$human        = ! empty( $args['human'] );
		$filter       = (string) ( $args['filter_html'] ?? '' );
		$fid          = self::FORM_ID;

		if ( empty( $languages ) ) {
			if ( '' !== $filter ) {
				echo '<div class="st-panel st-toolbar">' . $filter . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- caller-escaped.
			}
			echo '<p><em>' . esc_html__( 'Add at least one non-default language in Polylang to translate strings.', 'supertext-polylang' ) . '</em></p>';
			return;
		}
		?>
		<div class="st-panel st-toolbar">
			<?php if ( '' !== $filter ) : ?>
				<?php echo $filter; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built + escaped by the caller. ?>
				<div class="st-toolbar__sep"></div>
			<?php endif; ?>

			<div class="st-toolbar__actions">
				<div class="st-toolbar__pickers">
					<label class="st-field">
						<span><?php esc_html_e( 'Target language', 'supertext-polylang' ); ?></span>
						<select name="lang" form="<?php echo esc_attr( $fid ); ?>">
							<?php foreach ( $languages as $lang ) : ?>
								<option value="<?php echo esc_attr( $lang['slug'] ); ?>"><?php echo esc_html( $lang['name'] ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<?php if ( $human ) : ?>
						<label class="st-field st-field--human">
							<span><?php esc_html_e( 'Type', 'supertext-polylang' ); ?></span>
							<select name="service_id" form="<?php echo esc_attr( $fid ); ?>">
								<?php foreach ( (array) ( $args['human_services'] ?? array() ) as $id => $service ) : ?>
									<option value="<?php echo esc_attr( (string) $id ); ?>"><?php echo esc_html( (string) ( $service['label'] ?? $id ) ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<label class="st-field st-field--human">
							<span><?php esc_html_e( 'Delivery', 'supertext-polylang' ); ?></span>
							<select name="express" form="<?php echo esc_attr( $fid ); ?>">
								<?php foreach ( (array) ( $args['express_options'] ?? array() ) as $id => $label ) : ?>
									<option value="<?php echo esc_attr( (string) $id ); ?>"><?php echo esc_html( (string) $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
					<?php endif; ?>
				</div>

				<div class="st-toolbar__buttons">
					<button type="submit" name="st_do" value="ai" form="<?php echo esc_attr( $fid ); ?>" class="button st-btn-icon">
						<span class="dashicons dashicons-superhero-alt"></span><?php esc_html_e( 'Translate with AI', 'supertext-polylang' ); ?>
					</button>
					<?php if ( $human ) : ?>
						<button type="submit" name="st_do" value="human" form="<?php echo esc_attr( $fid ); ?>" class="button button-primary st-btn-icon">
							<span class="dashicons dashicons-groups"></span><?php esc_html_e( 'Order human translation', 'supertext-polylang' ); ?>
						</button>
					<?php endif; ?>
				</div>
			</div>
		</div>

		<form method="post" id="<?php echo esc_attr( $fid ); ?>" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( (string) $args['action'] ); ?>" />
			<?php foreach ( (array) ( $args['hidden'] ?? array() ) as $name => $value ) : ?>
				<input type="hidden" name="<?php echo esc_attr( (string) $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>" />
			<?php endforeach; ?>
			<?php wp_nonce_field( (string) $args['nonce_action'] ); ?>

			<?php if ( empty( $rows ) ) : ?>
				<div class="st-panel"><p style="margin:0;"><?php esc_html_e( 'No strings to translate.', 'supertext-polylang' ); ?></p></div>
			<?php else : ?>
				<div class="st-panel st-tablecard">
					<table class="st-table">
						<thead>
							<tr>
								<th class="st-col-check"><input type="checkbox" class="st-check-all" title="<?php esc_attr_e( 'Select all', 'supertext-polylang' ); ?>" /></th>
								<th><?php esc_html_e( 'Field', 'supertext-polylang' ); ?></th>
								<?php if ( $show_group ) : ?>
									<th><?php esc_html_e( 'Group', 'supertext-polylang' ); ?></th>
								<?php endif; ?>
								<th><?php esc_html_e( 'Source', 'supertext-polylang' ); ?></th>
								<?php foreach ( $languages as $lang ) : ?>
									<th><?php echo esc_html( $lang['name'] ); ?></th>
								<?php endforeach; ?>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $rows as $i => $row ) : ?>
								<tr>
									<td class="st-col-check"><input type="checkbox" class="st-row-check" name="sel[]" value="<?php echo (int) $i; ?>" /></td>
									<td class="st-col-field"><?php echo esc_html( (string) ( $row['name'] ?? '' ) ); ?></td>
									<?php if ( $show_group ) : ?>
										<td><span class="st-chip"><?php echo esc_html( (string) ( $row['group'] ?? '' ) ); ?></span></td>
									<?php endif; ?>
									<td class="st-col-source">
										<?php echo esc_html( (string) $row['source'] ); ?>
										<input type="hidden" name="src[<?php echo (int) $i; ?>]" value="<?php echo esc_attr( (string) $row['source'] ); ?>" />
									</td>
									<?php foreach ( $languages as $lang ) : ?>
										<?php $slug = $lang['slug']; ?>
										<td>
											<textarea
												name="tr[<?php echo esc_attr( $slug ); ?>][<?php echo (int) $i; ?>]"
												rows="2"
												class="st-tr-input"
												placeholder="<?php echo esc_attr( sprintf( /* translators: %s language name */ __( 'Add %s translation…', 'supertext-polylang' ), $lang['name'] ) ); ?>"
											><?php echo esc_textarea( (string) ( $translations[ $slug ][ $row['source'] ] ?? '' ) ); ?></textarea>
										</td>
									<?php endforeach; ?>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>

				<p class="submit">
					<button type="submit" name="st_save" value="1" class="button"><?php esc_html_e( 'Save changes', 'supertext-polylang' ); ?></button>
					<span class="description" style="margin-left:.5em;"><?php esc_html_e( 'Tick rows, then use “Translate with AI” or order human translation. Or edit directly and Save.', 'supertext-polylang' ); ?></span>
				</p>
			<?php endif; ?>
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
		$do         = isset( $_POST['st_do'] ) ? sanitize_key( wp_unslash( $_POST['st_do'] ) ) : '';
		$lang       = isset( $_POST['lang'] ) ? sanitize_key( wp_unslash( $_POST['lang'] ) ) : '';
		$service_id = isset( $_POST['service_id'] ) ? (int) $_POST['service_id'] : 0;
		$express    = isset( $_POST['express'] ) ? sanitize_key( wp_unslash( $_POST['express'] ) ) : '';
		$src        = ( isset( $_POST['src'] ) && is_array( $_POST['src'] ) ) ? wp_unslash( $_POST['src'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$grid       = ( isset( $_POST['tr'] ) && is_array( $_POST['tr'] ) ) ? wp_unslash( $_POST['tr'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$sel        = ( isset( $_POST['sel'] ) && is_array( $_POST['sel'] ) ) ? array_map( 'intval', (array) $_POST['sel'] ) : array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// The AI / human buttons submit `st_do`; the Save button doesn't, so anything
		// else just saves the visible grid.
		$do = in_array( $do, array( 'ai', 'human' ), true ) ? $do : 'save';

		$src_clean = array();
		foreach ( $src as $i => $value ) {
			$src_clean[ (int) $i ] = (string) $value;
		}

		return array(
			'do'         => $do,
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
	 * @param int[]             $selected Checked indexes.
	 * @param array<int,string> $src      i => source.
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
