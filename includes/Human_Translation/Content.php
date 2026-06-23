<?php
/**
 * @package Supertext_Polylang
 */

namespace Supertext\Polylang\Human_Translation;

defined( 'ABSPATH' ) || exit;

use PLL_Export_Container;
use PLL_Export_Data_From_Posts;
use PLL_Language;
use PLL_Model;
use WP_Post;
use WP_Syntex\Polylang_Pro\Modules\Machine_Translation\Data;

/**
 * Builds the HTML document uploaded to Supertext for a human-translation order.
 *
 * Reuses Polylang's export pipeline (the same one the AI path uses), so the
 * YOOtheme field-by-field extraction and blob-exclusion apply automatically. Each
 * translatable string is wrapped in a `<div data-pll-id="…">` whose id is the
 * base64-encoded translation context (field + id) — enough to map the returned
 * translation back onto the right field later, when we wire up the callback.
 *
 * @since 0.5.0
 */
class Content {
	/**
	 * Builds the HTML document for a post.
	 *
	 * @param WP_Post      $post        Source post.
	 * @param PLL_Language $target_lang Target language (drives the export).
	 * @param PLL_Model    $model       Polylang model.
	 * @return string
	 */
	public static function build_html( WP_Post $post, PLL_Language $target_lang, PLL_Model $model ): string {
		$container = new PLL_Export_Container( Data::class );
		$export    = new PLL_Export_Data_From_Posts( $model );

		// `include_translated_items => true` so the source is exported even when a
		// translation already exists in the target language; otherwise the exporter
		// drops the post and we'd build an empty file ("no translatable content").
		$export->send_to_export( $container, array( $post ), $target_lang, array( 'include_translated_items' => true ) );

		$html = "<!DOCTYPE html>\n<html><head><meta charset=\"utf-8\"></head><body>\n";

		foreach ( $container as $data ) {
			if ( ! $data instanceof Data ) {
				continue;
			}

			foreach ( $data->get() as $entities ) {
				foreach ( $entities as $translations ) {
					foreach ( $translations->entries as $entry ) {
						if ( '' === $entry->singular ) {
							continue;
						}

						$marker = base64_encode( (string) $entry->context );
						$html  .= '<div data-pll-id="' . esc_attr( $marker ) . '">' . $entry->singular . "</div>\n";
					}
				}
			}
		}

		$html .= '</body></html>';

		return $html;
	}
}
