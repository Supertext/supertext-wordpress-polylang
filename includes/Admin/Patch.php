<?php
/**
 * @package Supertext_Polylang
 */

namespace Supertext\Polylang\Admin;

defined( 'ABSPATH' ) || exit;

use ReflectionClass;
use WP_Error;

/**
 * Applies (and inspects) the one-line patch to Polylang Pro's machine-translation
 * Factory so it exposes the `pll_mt_services` filter our service hooks into.
 *
 * This is the in-WordPress equivalent of the patch the deploy script applies over
 * SFTP — it lets an admin patch the live Polylang install from the plugin's own
 * settings page.
 *
 * @since 0.2.0
 */
class Patch {
	/**
	 * Fully-qualified name of Polylang's MT factory.
	 *
	 * @var string
	 */
	const FACTORY_CLASS = '\WP_Syntex\Polylang_Pro\Modules\Machine_Translation\Factory';

	/**
	 * Marker that indicates the patch is present.
	 *
	 * @var string
	 */
	const MARKER = 'pll_mt_services';

	/**
	 * The exact line we replace.
	 *
	 * @var string
	 */
	const NEEDLE = 'return self::SERVICES;';

	/**
	 * The replacement (adds the filter).
	 *
	 * @var string
	 */
	const REPLACEMENT = "return apply_filters( 'pll_mt_services', self::SERVICES );";

	/**
	 * Tells whether Polylang Pro's MT factory is available.
	 *
	 * @return bool
	 */
	public static function polylang_available(): bool {
		return class_exists( self::FACTORY_CLASS );
	}

	/**
	 * Resolves the absolute path to Polylang's Factory.php via reflection
	 * (version-agnostic: works for the 3.7 `modules/` and 3.8+ `src/modules/` layouts).
	 *
	 * @return string|WP_Error
	 */
	public static function factory_file() {
		if ( ! self::polylang_available() ) {
			return new WP_Error( 'supertext_no_polylang', __( 'Polylang Pro (with the Machine Translation module) is not active.', 'supertext-polylang' ) );
		}

		try {
			$ref  = new ReflectionClass( self::FACTORY_CLASS );
			$file = $ref->getFileName();
		} catch ( \Throwable $e ) {
			return new WP_Error( 'supertext_reflection_failed', $e->getMessage() );
		}

		if ( ! is_string( $file ) || '' === $file ) {
			return new WP_Error( 'supertext_factory_not_found', __( 'Could not locate Polylang\'s Factory.php.', 'supertext-polylang' ) );
		}

		return $file;
	}

	/**
	 * Tells whether the patch is currently applied.
	 *
	 * @return bool
	 */
	public static function is_patched(): bool {
		$file = self::factory_file();
		if ( is_wp_error( $file ) ) {
			return false;
		}

		$content = @file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors
		return is_string( $content ) && false !== strpos( $content, self::MARKER );
	}

	/**
	 * Applies the patch.
	 *
	 * @return true|WP_Error True on success (or if already patched), error otherwise.
	 */
	public static function apply() {
		$file = self::factory_file();
		if ( is_wp_error( $file ) ) {
			return $file;
		}

		if ( ! is_readable( $file ) ) {
			return new WP_Error( 'supertext_unreadable', __( 'Polylang\'s Factory.php is not readable.', 'supertext-polylang' ) );
		}

		$content = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $content ) {
			return new WP_Error( 'supertext_read_failed', __( 'Could not read Polylang\'s Factory.php.', 'supertext-polylang' ) );
		}

		if ( false !== strpos( $content, self::MARKER ) ) {
			return true; // Already patched.
		}

		if ( false === strpos( $content, self::NEEDLE ) ) {
			return new WP_Error(
				'supertext_needle_missing',
				__( 'The expected code was not found in Polylang\'s Factory.php — it may already differ from the supported version.', 'supertext-polylang' )
			);
		}

		if ( ! is_writable( $file ) ) {
			return new WP_Error(
				'supertext_not_writable',
				sprintf(
					/* translators: %s is a file path. */
					__( 'Polylang\'s Factory.php is not writable by the web server: %s', 'supertext-polylang' ),
					$file
				)
			);
		}

		// One-time backup of the pristine file.
		$backup = $file . '.bak';
		if ( ! file_exists( $backup ) ) {
			@copy( $file, $backup ); // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors
		}

		$patched = str_replace( self::NEEDLE, self::REPLACEMENT, $content );

		$written = file_put_contents( $file, $patched ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $written ) {
			return new WP_Error( 'supertext_write_failed', __( 'Failed to write the patched Factory.php.', 'supertext-polylang' ) );
		}

		// Make sure the new code is used immediately rather than a cached opcode.
		if ( function_exists( 'opcache_invalidate' ) ) {
			@opcache_invalidate( $file, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		return true;
	}
}
