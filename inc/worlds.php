<?php
/**
 * Świat klienta: Moto / Rower.
 *
 * Wybór zapisany w przeglądarce (localStorage „fxWorld”) – strona główna ma w HTML obie wersje
 * sekcji (Google widzi wszystko), a przeglądarka pokazuje tylko wybraną. Bez migania: atrybut
 * data-world ustawiany jest w <head>, zanim strona się narysuje. Działa z cache stron.
 * Oglądanie kategorii moto albo rowerowych samo ustawia świat (body: data-fx-world-hint).
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_head', function () {
	if ( ! fx_mod( 'worlds_on' ) ) {
		return;
	}
	$def = 'rower' === fx_mod( 'world_default' ) ? 'rower' : 'moto';
	echo "<script>(function(){var w;try{w=localStorage.getItem('fxWorld')}catch(e){}if(w!=='moto'&&w!=='rower'){w='" . esc_js( $def ) . "'}document.documentElement.setAttribute('data-world',w)})();</script>\n";
}, 1 );

/** Świat bieżącej kategorii / produktu ('moto', 'rower' albo ''). */
function fx_world_hint() {
	if ( ! fx_mod( 'worlds_on' ) || ! function_exists( 'is_product_category' ) ) {
		return '';
	}
	$ids = array();
	if ( is_product_category() ) {
		$ids = array( get_queried_object_id() );
	} elseif ( is_product() ) {
		$ids = wc_get_product_cat_ids( get_queried_object_id() );
	}
	if ( ! $ids ) {
		return '';
	}
	$moto  = fx_world_cat( 'moto' );
	$rower = fx_world_cat( 'rower' );
	$found = array();
	foreach ( $ids as $id ) {
		$chain = array_merge( array( (int) $id ), array_map( 'intval', get_ancestors( $id, 'product_cat', 'taxonomy' ) ) );
		if ( $moto && in_array( $moto, $chain, true ) ) {
			$found['moto'] = true;
		}
		if ( $rower && in_array( $rower, $chain, true ) ) {
			$found['rower'] = true;
		}
	}
	return 1 === count( $found ) ? (string) key( $found ) : ''; // produkt w obu światach – nie zmieniamy wyboru
}

add_action( 'wp_footer', function () {
	$hint = fx_world_hint();
	if ( $hint ) {
		echo "<script>try{localStorage.setItem('fxWorld','" . esc_js( $hint ) . "')}catch(e){}</script>\n";
	}
}, 5 );
