<?php
/**
 * @package Supertext_Polylang
 */

namespace Supertext\Polylang\Files;

defined( 'ABSPATH' ) || exit;

/**
 * A post that can be exported for translation.
 *
 * @since 0.11.0
 */
class File_Item {
	/**
	 * @param int    $id    Post ID.
	 * @param string $title Post title.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $title,
	) {}
}
