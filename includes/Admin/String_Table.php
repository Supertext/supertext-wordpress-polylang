<?php
/**
 * @package Supertext_Polylang
 */

namespace Supertext\Polylang\Admin;

defined( 'ABSPATH' ) || exit;

use Supertext\Polylang\Polylang\String_Store;

/**
 * Shared editor UI for translating a set of Polylang strings, styled to the
 * Supertext design: a toolbar card (intro + optional filter slot + a Bulk-actions
 * row) and a table card (checkbox, field, group chip, source, one rounded input per
 * target language), plus a mint Save bar.
 *
 * The "Translate with AI" action fills every target-language column for the checked
 * rows; human orders use the revealed target-language / type / delivery pickers. The
 * action controls live in the toolbar card but submit the POST form (which wraps the
 * table) via the HTML5 `form="…"` attribute, so the two cards render separately.
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
	 * @param array $args See the properties read below (rows, languages, translations,
	 *                    action, nonce_action, hidden, show_group, human, human_services,
	 *                    express_options, filter_html, intro).
	 * @return void
	 */
	public static function render( array $args ): void {
		$rows         = $args['rows'] ?? array();
		$languages    = $args['languages'] ?? array();
		$translations = $args['translations'] ?? array();
		$show_group   = ! empty( $args['show_group'] );
		$human        = ! empty( $args['human'] );
		$filter       = (string) ( $args['filter_html'] ?? '' );
		$intro        = (string) ( $args['intro'] ?? '' );
		$fid          = self::FORM_ID;
		$confirm      = esc_js( __( 'Order human translation for the checked rows?', 'supertext-polylang' ) );
		?>
		<div class="st-panel st-toolbar">
			<?php if ( '' !== $intro ) : ?>
				<p class="st-toolbar__intro"><?php echo esc_html( $intro ); ?></p>
			<?php endif; ?>

			<?php
			if ( '' !== $filter ) {
				echo $filter; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built + escaped by the caller.
			}
			?>

			<?php if ( empty( $languages ) ) : ?>
				<p><em><?php esc_html_e( 'Add at least one non-default language in Polylang to translate strings.', 'supertext-polylang' ); ?></em></p>
			<?php else : ?>
				<div class="st-bulkrow">
					<span class="st-select-wrap">
						<select name="st_action" form="<?php echo esc_attr( $fid ); ?>" class="st-select st-bulk-action">
							<option value="-1"><?php esc_html_e( 'Bulk actions', 'supertext-polylang' ); ?></option>
							<option value="ai"><?php esc_html_e( 'Translate with Supertext AI', 'supertext-polylang' ); ?></option>
							<?php if ( $human ) : ?>
								<option value="human"><?php esc_html_e( 'Order human translation', 'supertext-polylang' ); ?></option>
							<?php endif; ?>
						</select>
						<span class="dashicons dashicons-arrow-down-alt2 st-select-chevron"></span>
					</span>
					<button type="submit" name="st_apply" value="1" form="<?php echo esc_attr( $fid ); ?>" class="st-btn-outline"><?php esc_html_e( 'Apply', 'supertext-polylang' ); ?></button>

					<?php if ( $human ) : ?>
						<span class="st-select-wrap st-picker st-picker-human">
							<select name="lang" form="<?php echo esc_attr( $fid ); ?>" class="st-select" title="<?php esc_attr_e( 'Target language', 'supertext-polylang' ); ?>">
								<?php foreach ( $languages as $lang ) : ?>
									<option value="<?php echo esc_attr( $lang['slug'] ); ?>"><?php echo esc_html( $lang['name'] ); ?></option>
								<?php endforeach; ?>
							</select>
							<span class="dashicons dashicons-arrow-down-alt2 st-select-chevron"></span>
						</span>
						<span class="st-select-wrap st-picker st-picker-human">
							<select name="service_id" form="<?php echo esc_attr( $fid ); ?>" class="st-select" title="<?php esc_attr_e( 'Translation type', 'supertext-polylang' ); ?>">
								<?php foreach ( (array) ( $args['human_services'] ?? array() ) as $id => $service ) : ?>
									<option value="<?php echo esc_attr( (string) $id ); ?>"><?php echo esc_html( (string) ( $service['label'] ?? $id ) ); ?></option>
								<?php endforeach; ?>
							</select>
							<span class="dashicons dashicons-arrow-down-alt2 st-select-chevron"></span>
						</span>
						<span class="st-select-wrap st-picker st-picker-human">
							<select name="express" form="<?php echo esc_attr( $fid ); ?>" class="st-select" title="<?php esc_attr_e( 'Delivery', 'supertext-polylang' ); ?>">
								<?php foreach ( (array) ( $args['express_options'] ?? array() ) as $id => $label ) : ?>
									<option value="<?php echo esc_attr( (string) $id ); ?>"><?php echo esc_html( (string) $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<span class="dashicons dashicons-arrow-down-alt2 st-select-chevron"></span>
						</span>
					<?php endif; ?>

					<span class="st-bulkrow__right">
						<button type="submit" name="st_do" value="ai" form="<?php echo esc_attr( $fid ); ?>" class="st-btn-outline st-btn-icon">
							<span class="dashicons dashicons-superhero-alt"></span><?php esc_html_e( 'Translate with AI', 'supertext-polylang' ); ?>
						</button>
						<?php if ( $human ) : ?>
							<button type="submit" name="st_do" value="human" form="<?php echo esc_attr( $fid ); ?>" class="st-btn-mint st-btn-icon" onclick="return confirm('<?php echo $confirm; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_js above. ?>');">
								<span class="dashicons dashicons-groups"></span><?php esc_html_e( 'Order human translation', 'supertext-polylang' ); ?>
							</button>
						<?php endif; ?>
					</span>
				</div>
			<?php endif; ?>
		</div>

		<?php if ( ! empty( $languages ) ) : ?>
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
									<th class="st-col-field"><?php esc_html_e( 'Field', 'supertext-polylang' ); ?></th>
									<?php if ( $show_group ) : ?>
										<th class="st-col-group"><?php esc_html_e( 'Group', 'supertext-polylang' ); ?></th>
									<?php endif; ?>
									<th class="st-col-source"><?php esc_html_e( 'Source', 'supertext-polylang' ); ?></th>
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
											<td class="st-col-group"><span class="st-chip"><?php echo esc_html( (string) ( $row['group'] ?? '' ) ); ?></span></td>
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
													rows="1"
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

					<div class="st-savebar">
						<button type="submit" name="st_save" value="1" class="st-btn-mint st-btn-icon">
							<span class="dashicons dashicons-yes"></span><?php esc_html_e( 'Save translations', 'supertext-polylang' ); ?>
						</button>
						<span class="st-savebar__note"><?php esc_html_e( 'Changes apply to the strings shown under Languages → String translations.', 'supertext-polylang' ); ?></span>
					</div>
				<?php endif; ?>
			</form>
		<?php endif; ?>
		<?php
	}

	/**
	 * Reads a submitted table form (call after verifying nonce + capability).
	 *
	 * @return array{do:string, lang:string, service_id:int, express:string, src:array<int,string>, grid:array<string,array<int,string>>, selected:int[]}
	 */
	public static function read_submit(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- caller verifies the nonce.
		$do_btn     = isset( $_POST['st_do'] ) ? sanitize_key( wp_unslash( $_POST['st_do'] ) ) : '';
		$apply      = isset( $_POST['st_apply'] );
		$action     = isset( $_POST['st_action'] ) ? sanitize_key( wp_unslash( $_POST['st_action'] ) ) : '-1';
		$lang       = isset( $_POST['lang'] ) ? sanitize_key( wp_unslash( $_POST['lang'] ) ) : '';
		$service_id = isset( $_POST['service_id'] ) ? (int) $_POST['service_id'] : 0;
		$express    = isset( $_POST['express'] ) ? sanitize_key( wp_unslash( $_POST['express'] ) ) : '';
		$src        = ( isset( $_POST['src'] ) && is_array( $_POST['src'] ) ) ? wp_unslash( $_POST['src'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$grid       = ( isset( $_POST['tr'] ) && is_array( $_POST['tr'] ) ) ? wp_unslash( $_POST['tr'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$sel        = ( isset( $_POST['sel'] ) && is_array( $_POST['sel'] ) ) ? array_map( 'intval', (array) $_POST['sel'] ) : array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// A right-hand button (st_do) wins; otherwise "Apply" runs the dropdown action;
		// the Save button (neither) just saves the visible grid.
		if ( in_array( $do_btn, array( 'ai', 'human' ), true ) ) {
			$do = $do_btn;
		} elseif ( $apply && in_array( $action, array( 'ai', 'human' ), true ) ) {
			$do = $action;
		} else {
			$do = 'save';
		}

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
