<?php
/**
 * Główna kategoria produktu (okruszki na karcie produktu i dane BreadcrumbList dla Google).
 *
 * Problem: WooCommerce bierze do okruszków „najgłębszą” kategorię produktu, więc buzer wpięty też do
 * „Prezenty → Pomysł na prezent” pokazywał ścieżkę PREZENTY, a klient nie miał jak wrócić do „Zbroje i buzery”.
 *
 * Zasada:
 *  1. Kategorie pomocnicze (Prezenty, Wyprzedaż, Nowości… – lista w Dostosuj → Narzędzia sklepu)
 *     nigdy nie są ścieżką produktu, chyba że produkt nie ma żadnej innej kategorii.
 *  2. Dział główny (OFF ROAD, ROWEROWY, CASUAL, Części…) = ten, w którym produkt ma najwięcej przypisanych
 *     kategorii; przy remisie wygrywa wcześniejszy z listy (OFF ROAD przed ROWEROWY).
 *  3. W wybranym dziale – najgłębsza kategoria (np. OFF ROAD → Ochraniacze → Zbroje i buzery).
 *  4. Ręczny wybór „Kategoria główna” z Yoast / Rank Math ma pierwszeństwo, jeśli nie jest pomocnicza.
 *
 * Adresy produktów się nie zmieniają (/produkt/nazwa/) – zmienia się tylko ścieżka okruszków.
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

/** Lista slugów z ustawienia (po przecinku). */
function fx_mc_list( $key ) {
	return array_values( array_filter( array_map( 'sanitize_title', explode( ',', (string) fx_tool( $key ) ) ) ) );
}

/** Slug kategorii najwyższego poziomu dla kategorii. */
function fx_mc_root( $term ) {
	$anc = get_ancestors( $term->term_id, 'product_cat', 'taxonomy' );
	if ( ! $anc ) {
		return $term->slug;
	}
	$root = get_term( (int) end( $anc ), 'product_cat' );
	return $root && ! is_wp_error( $root ) ? $root->slug : $term->slug;
}

/** Czy kategoria jest pomocnicza (sama albo któryś z jej przodków). */
function fx_mc_is_weak( $term, $weak ) {
	if ( in_array( $term->slug, $weak, true ) ) {
		return true;
	}
	foreach ( get_ancestors( $term->term_id, 'product_cat', 'taxonomy' ) as $a ) {
		$t = get_term( (int) $a, 'product_cat' );
		if ( $t && ! is_wp_error( $t ) && in_array( $t->slug, $weak, true ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Główna kategoria produktu (WP_Term) albo null.
 *
 * @param int $product_id ID produktu.
 */
function fx_main_category( $product_id ) {
	static $cache = array();
	$product_id = (int) $product_id;
	if ( array_key_exists( $product_id, $cache ) ) {
		return $cache[ $product_id ];
	}
	$terms = get_the_terms( $product_id, 'product_cat' );
	if ( ! $terms || is_wp_error( $terms ) ) {
		return $cache[ $product_id ] = null;
	}
	$weak   = fx_mc_list( 'bc_weak' );
	$main   = fx_mc_list( 'bc_main' );
	$strong = array();
	foreach ( $terms as $t ) {
		if ( ! fx_mc_is_weak( $t, $weak ) ) {
			$strong[ $t->term_id ] = $t;
		}
	}
	if ( ! $strong ) {
		return $cache[ $product_id ] = null; // same pomocnicze – zostaje wybór WooCommerce
	}
	// 4. Ręczny wybór z wtyczki SEO.
	foreach ( array( '_yoast_wpseo_primary_product_cat', 'rank_math_primary_product_cat' ) as $k ) {
		$pid = (int) get_post_meta( $product_id, $k, true );
		if ( $pid && isset( $strong[ $pid ] ) ) {
			return $cache[ $product_id ] = $strong[ $pid ];
		}
	}
	// 2. Dział z największą liczbą kategorii produktu.
	$score = array();
	$by    = array();
	foreach ( $strong as $t ) {
		$r            = fx_mc_root( $t );
		$score[ $r ]  = ( $score[ $r ] ?? 0 ) + 1;
		$by[ $r ][]   = $t;
	}
	uksort(
		$score,
		function ( $a, $b ) use ( $score, $main ) {
			if ( $score[ $a ] !== $score[ $b ] ) {
				return $score[ $b ] <=> $score[ $a ];
			}
			$ia = array_search( $a, $main, true );
			$ib = array_search( $b, $main, true );
			$ia = false === $ia ? 99 : $ia;
			$ib = false === $ib ? 99 : $ib;
			return $ia <=> $ib;
		}
	);
	$root = array_key_first( $score );
	// 3. Najgłębsza kategoria w tym dziale.
	$best  = null;
	$depth = -1;
	foreach ( $by[ $root ] as $t ) {
		$d = count( get_ancestors( $t->term_id, 'product_cat', 'taxonomy' ) );
		if ( $d > $depth ) {
			$depth = $d;
			$best  = $t;
		}
	}
	return $cache[ $product_id ] = $best;
}

/* Okruszki WooCommerce (także dane BreadcrumbList dla Google). */
add_filter( 'woocommerce_breadcrumb_main_term', function ( $term, $terms ) {
	$id   = get_the_ID();
	$main = $id ? fx_main_category( $id ) : null;
	return $main ? $main : $term;
}, 20, 2 );

/* Gdyby kiedyś włączyć okruszki Yoast / Rank Math – ta sama kategoria, jeśli nie wybrano jej ręcznie. */
add_filter( 'wpseo_primary_term', function ( $term_id, $taxonomy ) {
	if ( 'product_cat' !== $taxonomy || ! is_singular( 'product' ) || get_post_meta( get_the_ID(), '_yoast_wpseo_primary_product_cat', true ) ) {
		return $term_id;
	}
	$main = fx_main_category( get_the_ID() );
	return $main ? $main->term_id : $term_id;
}, 20, 2 );
add_filter( 'rank_math/frontend/breadcrumb/main_term', function ( $term, $terms ) {
	if ( ! is_singular( 'product' ) || get_post_meta( get_the_ID(), 'rank_math_primary_product_cat', true ) ) {
		return $term;
	}
	$main = fx_main_category( get_the_ID() );
	return $main ? $main : $term;
}, 20, 2 );
