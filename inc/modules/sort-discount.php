<?php
/**
 * Sortowanie „Największa przecena” – najpierw produkty z najwyższym % rabatu.
 *
 * Procent rabatu trzymamy w polu _fx_disc (dla wariantowych: najwyższy rabat spośród
 * wariantów NA STANIE). Liczony przy zapisie produktu, a raz dziennie – i przy pierwszym
 * uruchomieniu – dla wszystkich produktów jednym zapytaniem (koniec promocji z harmonogramu też się zaktualizuje).
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

/* Pozycja na liście sortowania (zaraz po „domyślne”). */
add_filter( 'woocommerce_catalog_orderby', function ( $opts ) {
	$out = array();
	foreach ( $opts as $k => $v ) {
		$out[ $k ] = $v;
		if ( 'menu_order' === $k ) {
			$out['discount'] = 'Największa przecena';
		}
	}
	if ( ! isset( $out['discount'] ) ) {
		$out['discount'] = 'Największa przecena';
	}
	return $out;
} );

add_filter( 'woocommerce_get_catalog_ordering_args', function ( $args, $orderby ) {
	if ( 'discount' === $orderby ) {
		$args['orderby']  = 'date';
		$args['order']    = 'DESC';
		$args['meta_key'] = ''; // phpcs:ignore WordPress.DB.SlowDBQuery
	}
	return $args;
}, 20, 2 );

/* Zaznacz kwerendę listy produktów, a sortowanie dołóż w SQL (produkty bez rabatu na końcu). */
add_action( 'woocommerce_product_query', function ( $q ) {
	$orderby = isset( $_GET['orderby'] ) ? wc_clean( wp_unslash( $_GET['orderby'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	if ( 'discount' === $orderby ) {
		$q->set( 'fx_disc_sort', 1 );
	}
}, 40 );

add_filter( 'posts_clauses', function ( $c, $q ) {
	if ( ! $q->get( 'fx_disc_sort' ) ) {
		return $c;
	}
	global $wpdb;
	$c['join']   .= " LEFT JOIN {$wpdb->postmeta} fx_disc ON ( fx_disc.post_id = {$wpdb->posts}.ID AND fx_disc.meta_key = '_fx_disc' ) ";
	$c['orderby'] = "COALESCE( fx_disc.meta_value + 0, 0 ) DESC, {$wpdb->posts}.post_date DESC, {$wpdb->posts}.ID DESC";
	if ( ! empty( $c['groupby'] ) && false === strpos( $c['fields'], 'fx_disc' ) ) {
		$c['fields'] .= ', MAX( COALESCE( fx_disc.meta_value + 0, 0 ) ) AS fx_disc_v';
		$c['orderby'] = "fx_disc_v DESC, {$wpdb->posts}.post_date DESC, {$wpdb->posts}.ID DESC";
	}
	return $c;
}, 20, 2 );

/** Rabat produktu w % (0 = brak). */
function fx_disc_of( $product ) {
	$best = 0;
	$list = $product->is_type( 'variable' ) ? array_filter( array_map( 'wc_get_product', $product->get_children() ) ) : array( $product );
	foreach ( $list as $p ) {
		if ( ! $p->is_in_stock() ) {
			continue;
		}
		$r = (float) $p->get_regular_price();
		$s = (float) $p->get_price();
		if ( $r > 0 && $s > 0 && $s < $r ) {
			$best = max( $best, ( $r - $s ) / $r * 100 );
		}
	}
	return (int) round( $best );
}

function fx_disc_update( $product_id ) {
	$p = wc_get_product( $product_id );
	if ( ! $p || $p->is_type( 'variation' ) ) {
		return;
	}
	$d = fx_disc_of( $p );
	if ( $d > 0 ) {
		update_post_meta( $p->get_id(), '_fx_disc', $d );
	} else {
		delete_post_meta( $p->get_id(), '_fx_disc' );
	}
}
add_action( 'woocommerce_update_product', 'fx_disc_update', 30 );
add_action( 'woocommerce_new_product', 'fx_disc_update', 30 );
add_action( 'woocommerce_update_product_variation', function ( $vid ) {
	$parent = wp_get_post_parent_id( $vid );
	if ( $parent ) {
		fx_disc_update( $parent );
	}
}, 30 );

/** Przeliczenie dla wszystkich produktów jednym zapytaniem. */
function fx_disc_rebuild() {
	global $wpdb;
	$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		"SELECT CASE WHEN p.post_parent > 0 THEN p.post_parent ELSE p.ID END AS pid,
		        MAX( ( ( r.meta_value + 0 ) - ( pr.meta_value + 0 ) ) * 100 / ( r.meta_value + 0 ) ) AS d
		 FROM {$wpdb->posts} p
		 JOIN {$wpdb->postmeta} r  ON r.post_id = p.ID AND r.meta_key = '_regular_price'
		 JOIN {$wpdb->postmeta} pr ON pr.post_id = p.ID AND pr.meta_key = '_price'
		 JOIN {$wpdb->postmeta} st ON st.post_id = p.ID AND st.meta_key = '_stock_status' AND st.meta_value IN ('instock','onbackorder')
		 WHERE p.post_type IN ('product','product_variation') AND p.post_status = 'publish'
		   AND ( r.meta_value + 0 ) > 0 AND ( pr.meta_value + 0 ) > 0 AND ( pr.meta_value + 0 ) < ( r.meta_value + 0 )
		 GROUP BY pid"
	);
	$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_fx_disc'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	foreach ( (array) $rows as $r ) {
		$d = (int) round( (float) $r->d );
		if ( $d > 0 ) {
			$wpdb->insert( $wpdb->postmeta, array( 'post_id' => (int) $r->pid, 'meta_key' => '_fx_disc', 'meta_value' => $d ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		}
	}
	if ( function_exists( 'wp_cache_flush_group' ) ) {
		wp_cache_flush_group( 'post_meta' );
	}
	update_option( 'fx_disc_built', time(), false );
}

add_action( 'fx_disc_rebuild_daily', 'fx_disc_rebuild' );
add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'fx_disc_rebuild_daily' ) ) {
		wp_schedule_event( strtotime( 'tomorrow 01:10' ), 'daily', 'fx_disc_rebuild_daily' );
	}
	if ( ! get_option( 'fx_disc_built' ) ) {
		fx_disc_rebuild();
	}
} );
