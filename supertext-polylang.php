<?php
/**
 * Plugin Name:       Supertext for Polylang
 * Plugin URI:        https://github.com/Supertext/supertext-wordpress-polylang
 * Description:       Adds Supertext as a native machine-translation service in Polylang Pro. Translates content directly through Supertext — no DeepL proxy required.
 * Version:           0.1.0
 * Requires PHP:      8.1
 * Author:            Supertext
 * Author URI:        https://www.supertext.com
 * Text Domain:       supertext-polylang
 * License:           GPL-2.0-or-later
 *
 * @package Supertext_Polylang
 */

defined( 'ABSPATH' ) || exit;

define( 'SUPERTEXT_POLYLANG_VERSION', '0.1.0' );
define( 'SUPERTEXT_POLYLANG_FILE', __FILE__ );
define( 'SUPERTEXT_POLYLANG_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Minimal PSR-4 autoloader for the `Supertext\Polylang\` namespace.
 *
 * Classes are only ever loaded lazily — `Service` is not touched until Polylang
 * itself calls into it via the `pll_mt_services` filter, by which point
 * Polylang Pro's interfaces are guaranteed to be loaded.
 */
spl_autoload_register(
	function ( string $class ): void {
		$prefix = 'Supertext\\Polylang\\';
		if ( strpos( $class, $prefix ) !== 0 ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$path     = SUPERTEXT_POLYLANG_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $path ) ) {
			require $path;
		}
	}
);

/**
 * Registers the Supertext machine-translation service with Polylang.
 *
 * Polylang Pro keeps the list of MT services in a hardcoded const
 * (`Machine_Translation\Factory::SERVICES`). For this filter to fire, Polylang's
 * `Factory::get_classnames()` must return `apply_filters( 'pll_mt_services', self::SERVICES )`
 * instead of `self::SERVICES`. See README.md ("Polylang patch") for the one-line change.
 *
 * That single seam feeds three consumers at once: the service picker, the option
 * defaults, and the strict option storage schema — so our `supertext` options
 * actually persist.
 *
 * @param string[] $services List of service class names.
 * @return string[]
 */
add_filter(
	'pll_mt_services',
	function ( array $services ): array {
		$services[] = \Supertext\Polylang\Machine_Translation\Service::class;
		return $services;
	}
);

// Register the "Supertext" admin page (status, Polylang settings link, Patch button).
\Supertext\Polylang\Admin\Page::init();

// Register the plugin's own settings (human/order API credentials + environment).
\Supertext\Polylang\Admin\Settings::init();

// Add the "Supertext AI/Human Translation" bulk actions to the posts list table.
\Supertext\Polylang\Admin\Bulk_Actions::init();

// Register the REST callback Supertext calls when a human-translation order is done.
\Supertext\Polylang\Human_Translation\Callback::init();

// Translate YOOtheme Pro page-builder layouts field-by-field (instead of as a JSON blob).
\Supertext\Polylang\Integrations\YooTheme\Integration::init();

/**
 * Paints the full-colour Supertext logo over the block-editor MT-service icon.
 *
 * Polylang's block editor renders the service icon as a single monochrome SVG
 * path (it only accepts `path_d`), so a colour PNG can't be passed through. Our
 * Service tags that `<svg>` with `.supertext-mt-icon`; here we set the logo as its
 * background and hide the fallback path — entirely within our plugin, no JS patch.
 */
add_action(
	'enqueue_block_editor_assets',
	function (): void {
		$logo = plugins_url( 'assets/icon-v2-128.png', SUPERTEXT_POLYLANG_FILE );
		$css  = sprintf(
			'svg.supertext-mt-icon{background:url("%s") center/contain no-repeat !important;}svg.supertext-mt-icon > *{display:none !important;}',
			esc_url( $logo )
		);
		wp_register_style( 'supertext-polylang-editor', false );
		wp_enqueue_style( 'supertext-polylang-editor' );
		wp_add_inline_style( 'supertext-polylang-editor', $css );
	}
);

/**
 * Warns in the admin if Polylang Pro is missing or has not been patched to expose
 * the `pll_mt_services` filter (without it, this plugin silently does nothing).
 */
add_action(
	'admin_notices',
	function (): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		// Polylang Pro present?
		if ( ! class_exists( '\WP_Syntex\Polylang_Pro\Modules\Machine_Translation\Factory' ) ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'Supertext for Polylang requires Polylang Pro (with the Machine Translation module) to be active.', 'supertext-polylang' )
			);
			return;
		}

		// Patch applied? The filter is only honoured if our service shows up in the factory list.
		if ( ! in_array(
			\Supertext\Polylang\Machine_Translation\Service::class,
			\WP_Syntex\Polylang_Pro\Modules\Machine_Translation\Factory::get_classnames(),
			true
		) ) {
			printf(
				'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
				esc_html__( 'Supertext for Polylang: Polylang Pro is active but has not been patched to expose the "pll_mt_services" filter, so Supertext cannot register as a translation service yet.', 'supertext-polylang' ),
				esc_url( admin_url( 'admin.php?page=' . \Supertext\Polylang\Admin\Page::SLUG ) ),
				esc_html__( 'Patch Polylang now →', 'supertext-polylang' )
			);
		}
	}
);
