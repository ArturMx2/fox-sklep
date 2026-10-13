<?php
/**
 * Dostępne rozmiary po najechaniu na produkt w listingu (komputer).
 *
 * Pokazujemy wyłącznie rozmiary dostępne na stanie, w kolejności ustawionej
 * w atrybucie (Produkty → Atrybuty). Na urządzeniach dotykowych lista jest ukryta.
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

add_action( 'woocommerce_before_shop_loop_item_title', function () {
	global $product;
	$sizes = fx_available_sizes( $product );
	if ( ! $sizes ) {
		return;
	}
	echo '<span class="fx-sizes" aria-hidden="true"><span class="fx-sizes__bar"><span class="fx-sizes__label">Dostępne:</span>';
	foreach ( $sizes as $s ) {
		echo '<span class="fx-sizes__item">' . esc_html( $s ) . '</span>';
	}
	echo '</span></span>';
}, 11 );

/**
 * Lista nazw dostępnych rozmiarów produktu wariantowego.
 */
function fx_available_sizes( $product ) {
	if ( ! $product instanceof WC_Product || ! $product->is_type( 'variable' ) ) {
		return array();
	}
	$attrs = $product->get_variation_attributes();
	if ( ! $attrs ) {
		return array();
	}
	// Atrybut rozmiaru: z „rozmiar”/„size” w nazwie, inaczej pierwszy atrybut wariantów.
	$size_attr = '';
	foreach ( array_keys( $attrs ) as $name ) {
		if ( preg_match( '/(rozmiar|size|wielko)/i', $name . ' ' . wc_attribute_label( $name ) ) ) {
			$size_attr = $name;
			break;
		}
	}
	if ( '' === $size_attr ) {
		if ( count( $attrs ) > 1 ) {
			return array(); // np. tylko kolor + coś innego – nie zgadujemy
		}
		$size_attr = array_key_first( $attrs );
	}
	$key = 'attribute_' . sanitize_title( $size_attr );

	$in_stock = array();
	foreach ( $product->get_visible_children() as $vid ) {
		$v = wc_get_product( $vid );
		if ( ! $v || ! $v->is_in_stock() || ! $v->is_purchasable() ) {
			continue;
		}
		$va = $v->get_attributes();
		$val = isset( $va[ sanitize_title( $size_attr ) ] ) ? $va[ sanitize_title( $size_attr ) ] : ( isset( $va[ $size_attr ] ) ? $va[ $size_attr ] : '' );
		if ( '' === $val ) {
			return array(); // wariant „dowolny rozmiar” – nie da się wypisać listy
		}
		$in_stock[ $val ] = true;
	}
	if ( ! $in_stock ) {
		return array();
	}

	$out = array();
	if ( taxonomy_exists( $size_attr ) ) {
		// Kolejność z ustawień atrybutu.
		foreach ( wc_get_product_terms( $product->get_id(), $size_attr, array( 'fields' => 'all' ) ) as $t ) {
			if ( isset( $in_stock[ $t->slug ] ) ) {
				$out[] = $t->name;
			}
		}
	} else {
		foreach ( $attrs[ $size_attr ] as $val ) {
			if ( isset( $in_stock[ $val ] ) || isset( $in_stock[ sanitize_title( $val ) ] ) ) {
				$out[] = $val;
			}
		}
	}
	unset( $key );
	return array_slice( $out, 0, 12 );
}
