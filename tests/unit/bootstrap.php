<?php
/**
 * PHPUnit bootstrap for the Supertext for Polylang unit tests.
 *
 * Loads Composer (PHPUnit + Brain Monkey), defines the few constants the plugin
 * files expect, stubs WP_Error, and requires the plugin classes under test.
 *
 * @package Supertext_Polylang
 */

require __DIR__ . '/vendor/autoload.php';

// Let plugin files past their `defined( 'ABSPATH' ) || exit;` guard.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'SUPERTEXT_POLYLANG_VERSION' ) ) {
	define( 'SUPERTEXT_POLYLANG_VERSION', 'test' );
}
if ( ! defined( 'SUPERTEXT_POLYLANG_FILE' ) ) {
	define( 'SUPERTEXT_POLYLANG_FILE', __DIR__ . '/plugin.php' );
}

// Minimal WP_Error stub (the plugin only uses these methods).
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code;
		private $message;
		private $data;

		public function __construct( $code = '', $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code() {
			return $this->code;
		}

		public function get_error_message() {
			return $this->message;
		}

		public function has_errors() {
			return '' !== $this->code;
		}

		public function add_data( $data, $code = '' ) {
			$this->data = $data;
		}
	}
}

$root = dirname( __DIR__, 2 );

require_once $root . '/includes/Integrations/YooTheme/Layout.php';
require_once $root . '/includes/Human_Translation/Callback.php';
require_once $root . '/includes/Human_Translation/Client.php';
require_once $root . '/includes/Human_Translation/Writeback.php';

require_once __DIR__ . '/TestCase.php';
