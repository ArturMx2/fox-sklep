<?php
/**
 * FOX.SKLEP.PL – funkcje motywu.
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

define( 'FX_VERSION', '3.11.4' );
define( 'FX_DIR', get_template_directory() );
define( 'FX_URI', get_template_directory_uri() );

require_once FX_DIR . '/inc/template-tags.php';
require_once FX_DIR . '/inc/setup.php';
require_once FX_DIR . '/inc/customizer.php';
require_once FX_DIR . '/inc/mega-menu.php';
require_once FX_DIR . '/inc/security.php';
require_once FX_DIR . '/inc/worlds.php';
require_once FX_DIR . '/inc/home-seo.php';

if ( class_exists( 'WooCommerce' ) ) {
	require_once FX_DIR . '/inc/woocommerce.php';
	require_once FX_DIR . '/inc/kit.php';
	require_once FX_DIR . '/inc/cart.php';
	foreach ( array( 'settings', 'badges', 'size-guides', 'swatches', 'wishlist', 'stock-alerts', 'helmet', 'series', 'same-series', 'sizes-preview', 'filters', 'savings', 'low-stock', 'price-from', 'classic-checkout', 'color-family', 'sort-discount', 'title-badges', 'cart-cta', 'invoice-nip', 'delivery', 'ship-cta' ) as $fx_module ) {
		require_once FX_DIR . '/inc/modules/' . $fx_module . '.php';
	}
	if ( is_admin() ) {
		if ( ! function_exists( 'fx_sg_import_page' ) ) { // wtyczka „FOX – podgląd motywu” ma własną kopię
			require_once FX_DIR . '/inc/modules/size-guides-import.php';
		}
		if ( ! function_exists( 'fx_seo_cmp_page' ) ) { // wtyczka „FOX – podgląd motywu” ma własną kopię
			require_once FX_DIR . '/inc/seo-compare.php';
		}
	}
}
