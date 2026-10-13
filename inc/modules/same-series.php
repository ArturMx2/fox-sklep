<?php
/**
 * „Produkty z tej samej serii” – ta sama grafika / kolekcja FOX.
 *
 * Logika przeniesiona 1:1 z dotychczasowego kodu fox.sklep.pl (fox_produkty_z_serii_v13):
 * - nazwa musi zawierać „FOX”,
 * - z nazwy (wielkie litery, „/” i „-” jako spacje, bez znaków specjalnych,
 *   bez słów JUNIOR / LADY / KIDS) bierzemy 4 słowa od „FOX”, np. „FOX FLEXAIR THORN PURPLE”,
 * - szukamy opublikowanych produktów, których nazwa zawiera tę frazę (najnowsze, max 4),
 * - pokazujemy tylko produkty widoczne w katalogu.
 * Wygląd: karty motywu, 4 kolumny (2 na telefonie). Sekcja jest nad „Sprawdź podobne produkty”.
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

add_action( 'woocommerce_after_single_product_summary', 'fx_same_series_section', 15 );

/**
 * Fraza serii z nazwy produktu albo '' gdy nie da się jej wyznaczyć.
 */
function fx_same_series_phrase( $name ) {
	$upper = strtoupper( (string) $name );
	if ( false === strpos( $upper, 'FOX' ) ) {
		return '';
	}
	$upper   = preg_replace( '/[\/\-]/', ' ', $upper );
	$cleaned = preg_replace( '/[^A-Z0-9\s]/', '', $upper );
	$words   = preg_split( '/\s+/', trim( $cleaned ), -1, PREG_SPLIT_NO_EMPTY );
	$words   = array_values( array_diff( $words, array( 'JUNIOR', 'LADY', 'KIDS' ) ) );
	$fox     = array_search( 'FOX', $words, true );
	if ( false === $fox ) {
		return '';
	}
	$search = array_slice( $words, $fox, 4 );
	return count( $search ) < 4 ? '' : implode( ' ', $search );
}

function fx_same_series_ids( $product ) {
	global $wpdb;
	$phrase = fx_same_series_phrase( $product->get_name() );
	if ( '' === $phrase ) {
		return array();
	}
	$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}
			 WHERE post_type = 'product' AND post_status = 'publish'
			   AND post_title LIKE %s AND ID != %d
			 ORDER BY ID DESC LIMIT 4",
			'%' . $wpdb->esc_like( $phrase ) . '%',
			$product->get_id()
		)
	);
	$visible = array();
	foreach ( $ids as $id ) {
		$p = wc_get_product( $id );
		if ( $p && $p->is_visible() ) {
			$visible[] = (int) $id;
		}
	}
	return $visible;
}

function fx_same_series_section() {
	global $product;
	if ( ! is_product() || ! $product instanceof WC_Product ) {
		return;
	}
	$ids = fx_same_series_ids( $product );
	if ( ! $ids ) {
		return;
	}
	echo '<section class="related products fx-same-series' . ( 1 === count( $ids ) ? ' fx-same-series--single' : '' ) . '" aria-labelledby="fx-same-series-title"><div class="fx-row-head"><h2 id="fx-same-series-title">Produkty z tej samej serii</h2></div>';
	echo do_shortcode( '[products ids="' . implode( ',', $ids ) . '" orderby="post__in" columns="4" limit="4"]' ); // phpcs:ignore
	echo '</section>';
}
