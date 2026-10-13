<?php
/**
 * Klasyczny koszyk i zamówienie zamiast bloków WooCommerce.
 *
 * Bloki koszyka/zamówienia mają teksty tłumaczone w JavaScripcie (często po angielsku,
 * gdy brakuje paczki językowej) i nie obsługują hooków PHP, z których korzysta motyw
 * (walidacja kasku, uwagi do BaseLinkera, oszczędności). Dlatego jeśli strona koszyka
 * lub zamówienia zawiera blok, motyw wyświetla w jego miejscu klasyczny shortcode.
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'render_block',
	function ( $html, $block ) {
		if ( is_admin() || empty( $block['blockName'] ) ) {
			return $html;
		}
		if ( 'woocommerce/cart' === $block['blockName'] ) {
			return do_shortcode( '[woocommerce_cart]' );
		}
		if ( 'woocommerce/checkout' === $block['blockName'] ) {
			return do_shortcode( '[woocommerce_checkout]' );
		}
		return $html;
	},
	5,
	2
);

