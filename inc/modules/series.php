<?php
/**
 * „Sprawdź podobne produkty” – podobieństwo nazw w tej samej kategorii najniższego rzędu.
 *
 * Logika przeniesiona 1:1 z dotychczasowego fragmentu kodu fox.sklep.pl:
 * - kandydaci: produkty dostępne (instock) z kategorii najniższego rzędu produktu (max 150),
 * - słowa kluczowe: same litery, min. 3 znaki, bez polskich słów pomocniczych,
 * - podobny = min. 2 wspólne słowa; kolejność: więcej wspólnych słów, przy remisie droższy wyżej.
 * Dodane: wynik zapisywany w pamięci podręcznej na 12 h (i czyszczony przy zapisie produktu),
 * bo analiza 150 nazw przy każdym wejściu na kartę produktu spowalniała stronę.
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

define( 'FX_SIMILAR_LIMIT', 8 );       // 2 rzędy po 4 produkty (jak „Z tej samej serii”).
define( 'FX_SIMILAR_MIN_COMMON', 2 );  // Minimalna liczba wspólnych słów.
define( 'FX_SIMILAR_POOL', 150 );      // Ilu kandydatów analizować.

add_action( 'woocommerce_after_single_product_summary', 'fx_similar_section', 20 );

function fx_similar_stop_words() {
	return apply_filters( 'fx_similar_stop_words', array( 'i', 'w', 'na', 'z', 'o', 'do', 'oraz', 'dla', 'się', 'jest', 'lub', 'nie', 'to', 'czy', 'ale', 'bez', 'przez', 'pod', 'nad' ) );
}

/**
 * Słowa kluczowe z nazwy (jak w dotychczasowym kodzie).
 */
function fx_similar_keywords( $name, $min_len = 3 ) {
	if ( empty( $name ) ) {
		return array();
	}
	$name = mb_strtolower( wp_strip_all_tags( (string) $name ) );
	$name = html_entity_decode( $name, ENT_QUOTES, 'UTF-8' );
	$name = preg_replace( '/[^\p{L}\s]/u', '', $name );
	$name = preg_replace( '/\s+/', ' ', trim( $name ) );
	$stop = fx_similar_stop_words();
	$out  = array();
	foreach ( preg_split( '/\s+/', $name ) as $w ) {
		if ( mb_strlen( $w ) >= $min_len && ! in_array( $w, $stop, true ) ) {
			$out[] = $w;
		}
	}
	return array_values( array_unique( $out ) );
}

/**
 * ID podobnych produktów (z pamięci podręcznej, jeśli jest).
 */
function fx_similar_ids( $product ) {
	$pid   = $product->get_id();
	$cache = get_transient( 'fx_sim_' . $pid );
	if ( is_array( $cache ) ) {
		return $cache;
	}

	$ids  = array();
	$mine = fx_similar_keywords( $product->get_name() );
	if ( $mine ) {
		// Kategorie najniższego rzędu produktu.
		$leaf = array();
		foreach ( $product->get_category_ids() as $cid ) {
			if ( ! get_term_children( $cid, 'product_cat' ) ) {
				$leaf[] = (int) $cid;
			}
		}
		if ( $leaf ) {
			$cands = get_posts(
				array(
					'post_type'              => 'product',
					'post_status'            => 'publish',
					'posts_per_page'         => FX_SIMILAR_POOL,
					'post__not_in'           => array( $pid ),
					'no_found_rows'          => true,
					'update_post_term_cache' => false,
					'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery
						array(
							'key'   => '_stock_status',
							'value' => 'instock',
						),
					),
					'tax_query'              => array( // phpcs:ignore WordPress.DB.SlowDBQuery
						array(
							'taxonomy' => 'product_cat',
							'field'    => 'term_id',
							'terms'    => $leaf,
						),
					),
				)
			);
			$scored = array();
			foreach ( $cands as $post ) {
				$score = count( array_intersect( $mine, fx_similar_keywords( $post->post_title ) ) );
				if ( $score >= FX_SIMILAR_MIN_COMMON ) {
					$scored[] = array(
						'id'    => $post->ID,
						'score' => $score,
						'price' => (float) get_post_meta( $post->ID, '_price', true ),
					);
				}
			}
			usort(
				$scored,
				function ( $a, $b ) {
					return $a['score'] === $b['score'] ? $b['price'] <=> $a['price'] : $b['score'] <=> $a['score'];
				}
			);
			$ids = wp_list_pluck( array_slice( $scored, 0, FX_SIMILAR_LIMIT ), 'id' );
		}
	}
	set_transient( 'fx_sim_' . $pid, $ids, 12 * HOUR_IN_SECONDS );
	return $ids;
}

/* Po zapisie produktu odświeżamy jego listę (inne listy odświeżą się same w ciągu 12 h). */
add_action( 'woocommerce_update_product', function ( $id ) {
	delete_transient( 'fx_sim_' . $id );
} );

function fx_similar_section() {
	global $product;
	if ( ! is_product() || ! $product instanceof WC_Product ) {
		return;
	}
	$ids = fx_similar_ids( $product );
	if ( ! $ids ) {
		return;
	}
	echo '<section class="related products fx-series"><div class="fx-row-head"><h2>Sprawdź podobne produkty</h2></div>';
	echo do_shortcode( '[products ids="' . implode( ',', array_map( 'intval', $ids ) ) . '" orderby="post__in" columns="4" limit="' . (int) FX_SIMILAR_LIMIT . '"]' ); // phpcs:ignore
	echo '</section>';
}
