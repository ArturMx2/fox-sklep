<?php
/**
 * Cena „Od…” dla produktów wariantowych na stronie sklepu i w kategoriach.
 *
 * Logika 1:1 z dotychczasowego kodu (fox_cena_od_na_listingu): zamiast zakresu „X – Y”
 * pokazujemy „Od X”, tylko gdy warianty mają różne ceny. Karta produktu bez zmian.
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'woocommerce_variable_price_html', 'fx_price_from_on_listing', 20, 2 );
function fx_price_from_on_listing( $price_html, $product ) {
	if ( ! is_shop() && ! is_product_category() ) {
		return $price_html;
	}
	$prices = $product->get_variation_prices( true );
	if ( empty( $prices['price'] ) ) {
		return $price_html;
	}
	$min = (float) min( $prices['price'] );
	$max = (float) max( $prices['price'] );
	if ( $min === $max ) {
		return $price_html;
	}
	return 'Od ' . wc_price( $min ) . $product->get_price_suffix();
}
