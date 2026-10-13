<?php
/**
 * „Z tej serii” – zamiast losowych „Podobnych produktów”.
 *
 * Seria = najgłębsza kategoria produktu (np. Kaski → V1). Pokazujemy inne produkty
 * z tej kategorii: najpierw dostępne, a wśród nich te o najbardziej podobnej nazwie
 * (np. ta sama grafika lub kolekcja).
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

add_action( 'woocommerce_after_single_product_summary', 'fx_series_section', 20 );

function fx_series_term( $product ) {
	$best  = null;
	$depth = -1;
	foreach ( $product->get_category_ids() as $cid ) {
		$d = count( get_ancestors( $cid, 'product_cat', 'taxonomy' ) );
		if ( $d > $depth ) {
			$depth = $d;
			$best  = get_term( $cid, 'product_cat' );
		}
	}
	return ( $best && ! is_wp_error( $best ) ) ? $best : null;
}

function fx_name_words( $name ) {
	$stop  = array( 'fox', 'kask', 'motocyklowy', 'motocyklowe', 'spodnie', 'bluza', 'koszulka', 'rekawice', 'buty', 'gogle', 'junior', 'dla', 'na', 'i', 'z', 'w' );
	$words = preg_split( '/[^a-z0-9]+/', remove_accents( mb_strtolower( $name ) ) );
	return array_values( array_diff( array_filter( $words, function ( $w ) {
		return strlen( $w ) > 1;
	} ), $stop ) );
}

function fx_series_ids( $product, $limit = 6 ) {
	$term = fx_series_term( $product );
	if ( ! $term ) {
		return array();
	}
	$ids = wc_get_products(
		array(
			'status'     => 'publish',
			'visibility' => 'catalog',
			'limit'      => 60,
			'exclude'    => array( $product->get_id() ),
			'category'   => array( $term->slug ),
			'return'     => 'ids',
			'orderby'    => 'date',
			'order'      => 'DESC',
		)
	);
	if ( ! $ids ) {
		return array();
	}
	$mine   = fx_name_words( $product->get_name() );
	$scored = array();
	foreach ( $ids as $i => $id ) {
		$p = wc_get_product( $id );
		if ( ! $p ) {
			continue;
		}
		$score  = count( array_intersect( $mine, fx_name_words( $p->get_name() ) ) ) * 10;
		$score += $p->is_in_stock() ? 100 : 0;
		$score -= $i * 0.01; // przy remisie nowsze pierwsze
		$scored[ $id ] = $score;
	}
	arsort( $scored );
	return array_slice( array_keys( $scored ), 0, $limit );
}

function fx_series_section() {
	global $product;
	if ( ! $product instanceof WC_Product ) {
		return;
	}
	$ids = fx_series_ids( $product );
	if ( ! $ids ) {
		return;
	}
	$term = fx_series_term( $product );
	echo '<section class="related products fx-series"><div class="fx-row-head"><h2>Z tej serii: ' . esc_html( $term->name ) . '</h2>';
	echo '<a class="fx-btn fx-btn--outline fx-btn--sm" href="' . esc_url( get_term_link( $term ) ) . '">Zobacz wszystkie</a></div>';
	echo do_shortcode( '[products ids="' . implode( ',', array_map( 'intval', $ids ) ) . '" orderby="post__in" columns="3" limit="6"]' ); // phpcs:ignore
	echo '</section>';
}
