<?php
/**
 * Komunikat o ostatnich sztukach (1–3) zamiast standardowej dostępności.
 *
 * Logika i teksty 1:1 z dotychczasowego kodu (enduro_low_stock_message).
 * Dodane: klasa fx-low-stock, żeby komunikat był wyróżniony kolorem.
 * Działa dla produktów prostych i dla wybranego wariantu (gdy wariant ma zarządzanie stanem).
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'woocommerce_get_availability_text', 'fx_low_stock_message', 10, 2 );
function fx_low_stock_message( $availability, $product ) {
	if ( ! $product || ! $product->managing_stock() ) {
		return $availability;
	}
	$stock = (int) $product->get_stock_quantity();
	if ( 1 === $stock ) {
		return '🔥 Została ostatnia sztuka w magazynie!';
	}
	if ( 2 === $stock ) {
		return '⚠️ Zostały tylko 2 sztuki w magazynie!';
	}
	if ( 3 === $stock ) {
		return '⚠️ Zostały tylko 3 sztuki w magazynie!';
	}
	return $availability;
}

add_filter( 'woocommerce_get_availability_class', function ( $class, $product ) {
	if ( $product && $product->managing_stock() ) {
		$stock = (int) $product->get_stock_quantity();
		if ( $stock >= 1 && $stock <= 3 ) {
			$class .= ' fx-low-stock';
		}
	}
	return $class;
}, 10, 2 );
