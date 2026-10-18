<?php
/**
 * @package Supertext_Polylang
 */

namespace Supertext\Polylang\Files;

defined( 'ABSPATH' ) || exit;

use PLL_Language;
use Supertext\Polylang\Human_Translation\Content;
use Supertext\Polylang\Human_Translation\Writeback;
use Supertext\Polylang\Machine_Translation\Service;
use Supertext\Polylang\Sdk\Files\FileSegment;
use Supertext\Polylang\Sdk\V1\SupertextException;
use WP_Post;

/**
 * The WordPress/Polylang side of Translation_File_Glue.
 *
 * Segments come from Polylang's export pipeline (Human_Translation\Content::collect,
 * the entries AI translation sends), keyed by Polylang's translation context and
 * sent as HTML like the AI client does. Imports are written by
 * Human_Translation\Writeback::save_map (Polylang's MT processor: creates or
 * updates the linked translation as a draft, YOOtheme/ACF write-back included).
 *
 * @since 0.11.0
 */
class Polylang_File_Store implements Translation_File_Store {
	/**
	 * {@inheritDoc}
	 */
	public function item( int $post_id ): ?File_Item {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || 'trash' === $post->post_status || ! function_exists( 'PLL' ) || ! isset( PLL()->model ) ) {
			return null;
		}
		if ( ! PLL()->model->post->get_language( $post_id ) ) {
			return null;
		}
		return new File_Item( $post_id, (string) get_the_title( $post ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function language( int $post_id, string $slug ): ?File_Language {
		if ( ! function_exists( 'PLL' ) || ! isset( PLL()->model ) ) {
			return null;
		}
		$model  = PLL()->model;
		$target = $model->get_language( $slug );
		$source = $model->post->get_language( $post_id );
		if ( ! $target instanceof PLL_Language || ! $source instanceof PLL_Language || $target->slug === $source->slug ) {
			return null;
		}
		return new File_Language(
			$target->slug,
			(string) $target->name,
			(string) $target->locale,
			self::resolve( $target ),
			(string) $source->locale,
			self::resolve( $source )
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function segments( int $post_id, string $slug ): array {
		$post   = get_post( $post_id );
		$target = PLL()->model->get_language( $slug );
		if ( ! $post instanceof WP_Post || ! $target instanceof PLL_Language ) {
			return array();
		}
		$segments = array();
		$seen     = array();
		foreach ( Content::collect( $post, $target, PLL()->model ) as $entry ) {
			// Keys must be unique in a job; Polylang's contexts are (field + id), so they are.
			if ( isset( $seen[ $entry['context'] ] ) || '' === trim( $entry['singular'] ) ) {
				continue;
			}
			$seen[ $entry['context'] ] = true;
			$segments[]                = new FileSegment( $entry['context'], $entry['singular'], true );
		}
		return $segments;
	}

	/**
	 * {@inheritDoc}
	 */
	public function can_edit( int $post_id, string $slug ): bool {
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return false;
		}
		$translation = (int) PLL()->model->post->get_translation( $post_id, $slug );
		if ( $translation > 0 ) {
			return current_user_can( 'edit_post', $translation );
		}
		$type = get_post_type_object( (string) get_post_type( $post_id ) );
		return null !== $type && current_user_can( $type->cap->create_posts );
	}

	/**
	 * {@inheritDoc}
	 */
	public function write( int $post_id, string $slug, array $texts ): array {
		$post   = get_post( $post_id );
		$target = PLL()->model->get_language( $slug );
		if ( ! $post instanceof WP_Post || ! $target instanceof PLL_Language ) {
			return array();
		}
		return Writeback::save_map( $post, $target, $texts )['errors'];
	}

	/**
	 * The language's Supertext target code (the mapping, an exact match or the default), null when it has none.
	 *
	 * @param PLL_Language $language The language.
	 * @return string|null
	 */
	private static function resolve( PLL_Language $language ): ?string {
		$options = PLL()->options['machine_translation_services'][ Service::get_slug() ] ?? array();
		$mapping = is_array( $options ) && isset( $options['languages'] ) && is_array( $options['languages'] ) ? $options['languages'] : array();
		try {
			return Service::resolve_target( $language, $mapping );
		} catch ( SupertextException $e ) {
			return null;
		}
	}
}
