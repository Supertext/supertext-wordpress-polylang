<?php
/**
 * @package Supertext_Polylang
 */

namespace Supertext\Polylang\Files;

defined( 'ABSPATH' ) || exit;

/**
 * An exported translation file.
 *
 * @since 0.11.0
 */
class Exported_File {
	/**
	 * @param string $file_name    Download file name.
	 * @param string $content_type Content-Type header.
	 * @param string $content      File content.
	 */
	public function __construct(
		public readonly string $file_name,
		public readonly string $content_type,
		public readonly string $content,
	) {}
}
