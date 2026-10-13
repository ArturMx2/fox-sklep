<?php
/**
 * FOX.SKLEP.PL – funkcje motywu.
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

define( 'FX_VERSION', '2.3.0' );
define( 'FX_DIR', get_template_directory() );
define( 'FX_URI', get_template_directory_uri() );

require_once FX_DIR . '/inc/template-tags.php';
require_once FX_DIR . '/inc/setup.php';
require_once FX_DIR . '/inc/customizer.php';

if ( class_exists( 'WooCommerce' ) ) {
	require_once FX_DIR . '/inc/woocommerce.php';
	require_once FX_DIR . '/inc/kit.php';
	foreach ( array( 'settings', 'badges', 'size-guides', 'swatches', 'wishlist', 'stock-alerts', 'helmet', 'series', 'sizes-preview', 'filters' ) as $fx_module ) {
		require_once FX_DIR . '/inc/modules/' . $fx_module . '.php';
	}
}
