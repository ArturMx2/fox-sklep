<?php
/**
 * FOX.SKLEP.PL – funkcje motywu.
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

define( 'FX_VERSION', '1.0.3' );
define( 'FX_DIR', get_template_directory() );
define( 'FX_URI', get_template_directory_uri() );

require_once FX_DIR . '/inc/template-tags.php';
require_once FX_DIR . '/inc/setup.php';
require_once FX_DIR . '/inc/customizer.php';

if ( class_exists( 'WooCommerce' ) ) {
	require_once FX_DIR . '/inc/woocommerce.php';
	require_once FX_DIR . '/inc/kit.php';
}
