<?php
/**
 * @package Supertext_Polylang
 */

namespace Supertext\Polylang\Integrations\YooTheme;

defined( 'ABSPATH' ) || exit;

use PLL_Export_Data;
use PLL_Import_Export;
use PLL_Language;
use Translation_Entry;
use Translations;
use WP_Post;
use WP_Syntex\Polylang_Pro\Modules\Import_Export\Services\Context;

/**
 * Translates YOOtheme Pro page-builder layouts field-by-field through Polylang's
 * export/import pipeline (used by both XLIFF and the Supertext MT path).
 *
 * Instead of letting Polylang translate the serialized layout JSON as one blob
 * (which a translator corrupts), this:
 *   1. excludes `post_content` from the export for YOOtheme posts,
 *   2. adds one translation entry per text node in `pll_after_post_export`,
 *   3. rebuilds the JSON with the translations in `pll_filter_translated_post`.
 *
 * Mirrors Polylang's own ACF integration.
 *
 * @since 0.3.0
 */
class Integration {
	/**
	 * Registers the Polylang hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'pll_export_post_fields', array( self::class, 'exclude_post_content' ), 10, 2 );
		add_action( 'pll_after_post_export', array( self::class, 'export' ), 10, 3 );
		add_filter( 'pll_filter_translated_post', array( self::class, 'write_back' ), 10, 4 );
	}

	/**
	 * Removes `post_content` from the exported fields for YOOtheme posts, so the
	 * JSON blob is never walked/translated as one string.
	 *
	 * @param string[] $fields Field names to export.
	 * @param WP_Post  $post   Post being exported.
	 * @return string[]
	 */
	public static function exclude_post_content( $fields, $post ) {
		if ( ! self::is_yootheme_post( $post ) || ! is_array( $fields ) ) {
			return $fields;
		}

		return array_values(
			array_filter(
				$fields,
				static function ( $field ) {
					return PLL_Import_Export::POST_CONTENT !== $field;
				}
			)
		);
	}

	/**
	 * Adds one translation entry per translatable text node of the layout.
	 *
	 * @param PLL_Export_Data $export  Export object.
	 * @param WP_Post         $post    Source post.
	 * @param WP_Post|null    $tr_post Existing translated post, if any.
	 * @return void
	 */
	public static function export( $export, $post, $tr_post ) {
		if ( ! $export instanceof PLL_Export_Data || ! self::is_yootheme_post( $post ) ) {
			return;
		}

		$data = Layout::decode( $post->post_content );
		if ( null === $data ) {
			return;
		}

		// Pre-existing translations (so XLIFF round-trips show current values).
		$existing = ( $tr_post instanceof WP_Post ) ? Layout::decode( $tr_post->post_content ) : null;
		$existing = is_array( $existing ) ? Layout::collect( $existing ) : array();

		foreach ( Layout::collect( $data ) as $path => $source ) {
			$export->add_translation_entry(
				array(
					'object_type' => PLL_Import_Export::TYPE_POST,
					'field_type'  => Layout::FIELD_TYPE,
					'field_id'    => $path,
					'object_id'   => $post->ID,
				),
				$source,
				isset( $existing[ $path ] ) ? $existing[ $path ] : ''
			);
		}
	}

	/**
	 * Rebuilds the translated post's layout JSON from the translation set.
	 *
	 * @param WP_Post      $tr_post         Target (translated) post.
	 * @param WP_Post      $source_post     Source post.
	 * @param PLL_Language $target_language Target language.
	 * @param Translations $translations    The translation set.
	 * @return WP_Post
	 */
	public static function write_back( $tr_post, $source_post, $target_language, $translations ) {
		if ( ! $tr_post instanceof WP_Post || ! $source_post instanceof WP_Post || ! $translations instanceof Translations ) {
			return $tr_post;
		}

		$data = Layout::decode( $source_post->post_content );
		if ( null === $data ) {
			return $tr_post;
		}

		$new = Layout::map(
			$data,
			static function ( string $path, string $value ) use ( $translations ) {
				$entry = new Translation_Entry(
					array(
						'singular' => $value,
						'context'  => Context::to_string(
							array(
								Context::FIELD => Layout::FIELD_TYPE,
								Context::ID    => $path,
							)
						),
					)
				);

				// Only replace when an actual translation exists; keep the source otherwise.
				if ( ! $translations->translate_entry( $entry ) ) {
					return $value;
				}

				return (string) $translations->translate( $value, $entry->context );
			}
		);

		$tr_post->post_content = Layout::encode( $source_post->post_content, $new );

		return $tr_post;
	}

	/**
	 * Tells whether a post is a YOOtheme-built post.
	 *
	 * @param mixed $post Post.
	 * @return bool
	 */
	public static function is_yootheme_post( $post ): bool {
		return $post instanceof WP_Post && Layout::is_layout( (string) $post->post_content );
	}
}
