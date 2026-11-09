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
			$out['discount'] = 'Największa promocja';
		}
	}
	if ( ! isset( $out['discount'] ) ) {
		$out['discount'] = 'Największa promocja';
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
	$row = null;
	if ( function_exists( 'fx_pl_compute' ) ) {
		$GLOBALS['fx_pl_fresh'] = true;
		$row = fx_pl_compute( $p );
		$GLOBALS['fx_pl_fresh'] = false;
	}
	$d = function_exists( 'fx_pl_compute' ) ? ( $row ? (int) $row[0] : 0 ) : fx_disc_of( $p );
	if ( get_option( 'fx_pl_built' ) ) {
		$map  = (array) get_option( 'fx_pl_map', array() );
		$data = (array) get_option( 'fx_pl_data', array() );
		$camp = array_flip( (array) get_option( 'fx_pl_camp', array() ) );
		unset( $map[ $p->get_id() ], $data[ $p->get_id() ], $camp[ $p->get_id() ] );
		if ( $row ) {
			$map[ $p->get_id() ]  = $row[0];
			$data[ $p->get_id() ] = $row;
			if ( $row[2] ) {
				$camp[ $p->get_id() ] = 1;
			}
		}
		update_option( 'fx_pl_map', $map, false );
		update_option( 'fx_pl_data', $data, false );
		update_option( 'fx_pl_camp', array_keys( $camp ), false );
	}
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

/* =====================================================================
 * Mapa rabatów (filtr „Wielkość promocji” i sortowanie): produkt => największy rabat w %
 * wariantu na stanie – z kampanią z Przecen i od najniższej ceny z 30 dni (jak etykiety).
 * Liczona w tle paczkami; po zakończeniu podmienia mapę i pole _fx_disc.
 * ===================================================================== */

/** Podpis: kampania + dzień (raz dziennie przeliczenie, bo ceny z 30 dni się zmieniają). */
function fx_pl_sig() {
	$promo = function_exists( 'fx_omni_promo_sig' ) ? fx_omni_promo_sig() : '';
	return md5( $promo . '|' . wp_date( 'Y-m-d' ) );
}

function fx_pl_start() {
	$ids = array_map( 'intval', (array) apply_filters( 'fx_sale_ids', wc_get_product_ids_on_sale() ) );
	$par = array();
	foreach ( $ids as $id ) {
		$pp       = wp_get_post_parent_id( $id );
		$par[ $pp ? $pp : $id ] = 1;
	}
	update_option( 'fx_pl_job', array( 'ids' => array_keys( $par ), 'i' => 0, 'map' => array(), 'at' => time() ), false );
	if ( ! wp_next_scheduled( 'fx_pl_tick' ) ) {
		wp_schedule_single_event( time() + 5, 'fx_pl_tick' );
	}
}

add_action( 'fx_pl_tick', function () {
	global $wpdb;
	$job = get_option( 'fx_pl_job' );
	if ( ! is_array( $job ) || ! isset( $job['ids'] ) ) {
		return;
	}
	$t0 = microtime( true );
	$n  = count( $job['ids'] );
	$GLOBALS['fx_pl_fresh'] = true; // liczymy od zera, bez gotowych wartości
	while ( $job['i'] < $n && microtime( true ) - $t0 < 8 ) { // krótkie porcje, żeby nie obciążać sklepu
		$p = wc_get_product( $job['ids'][ $job['i'] ] );
		$job['i']++;
		if ( $p && 'publish' === $p->get_status() && function_exists( 'fx_pl_compute' ) ) {
			$row = fx_pl_compute( $p );
			if ( $row ) {
				$job['map'][ $p->get_id() ]  = $row[0];
				$job['data'][ $p->get_id() ] = $row;
				if ( $row[2] ) {
					$job['camp'][ $p->get_id() ] = 1; // objęty kampanią – filtr „Black Week”
				}
			}
		}
	}
	$GLOBALS['fx_pl_fresh'] = false;
	if ( $job['i'] < $n ) {
		update_option( 'fx_pl_job', $job, false );
		wp_schedule_single_event( time() + 30, 'fx_pl_tick' );
		return;
	}
	// Gotowe: podmiana mapy i pola do sortowania.
	update_option( 'fx_pl_map', $job['map'], false );
	update_option( 'fx_pl_data', (array) ( $job['data'] ?? array() ), false );
	update_option( 'fx_pl_camp', array_keys( (array) ( $job['camp'] ?? array() ) ), false );
	$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_fx_disc'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	foreach ( $job['map'] as $pid => $d ) {
		$wpdb->insert( $wpdb->postmeta, array( 'post_id' => (int) $pid, 'meta_key' => '_fx_disc', 'meta_value' => (int) $d ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
	}
	if ( function_exists( 'wp_cache_flush_group' ) ) {
		wp_cache_flush_group( 'post_meta' );
	}
	delete_option( 'fx_pl_job' );
	update_option( 'fx_pl_built', time(), false );
	update_option( 'fx_disc_built', time(), false );
	if ( function_exists( 'fx_cache_touch' ) ) {
		fx_cache_touch();
	}
} );

add_action( 'init', function () {
	if ( wp_next_scheduled( 'fx_disc_rebuild_daily' ) ) {
		wp_clear_scheduled_hook( 'fx_disc_rebuild_daily' ); // zastąpione przeliczaniem w tle
	}
	if ( ! get_option( 'fx_disc_built' ) && ! wp_next_scheduled( 'fx_disc_rebuild_once' ) ) {
		wp_schedule_single_event( time() + 5, 'fx_disc_rebuild_once' ); // szybki start z cen zapisanych – w tle
	}
	$sig = fx_pl_sig();
	if ( get_option( 'fx_pl_sig' ) !== $sig && ! get_option( 'fx_pl_job' ) ) {
		update_option( 'fx_pl_sig', $sig, false );
		// Zebranie listy produktów jest ciężkie (cały katalog) – tylko w tle, nigdy w trakcie ładowania strony.
		if ( ! wp_next_scheduled( 'fx_pl_begin' ) ) {
			wp_schedule_single_event( time() + 10, 'fx_pl_begin' );
		}
	}
}, 40 );
add_action( 'fx_pl_begin', 'fx_pl_start' );
add_action( 'fx_disc_rebuild_once', 'fx_disc_rebuild' );

/** Gotowe wartości dla kafelka: [ max %, min %, kampania 0/1, najniższa cena z 30 dni ] – liczone raz, w tle. */
function fx_pl_compute( WC_Product $p ) {
	$info = function_exists( 'fx_sale_info' ) ? fx_sale_info( $p ) : null;
	if ( ! $info || $info[0] <= 0 ) {
		return null;
	}
	$ref = 0;
	if ( function_exists( 'fx_omni_ref' ) ) {
		if ( $p->is_type( 'variable' ) ) {
			$b   = fx_omni_ref_variable( $p );
			$ref = $b ? (float) $b[0] : 0;
		} else {
			$ref = (float) fx_omni_ref( $p );
		}
	}
	return array( (int) $info[0], (int) $info[1], $info[3] ? 1 : 0, $ref );
}

/** Gotowe wartości produktu: wiersz, null (bez promocji) albo false (licz na żywo: dane niegotowe / karta tego produktu). */
function fx_pl_row( $id ) {
	static $data = null;
	if ( ! empty( $GLOBALS['fx_pl_fresh'] ) || ! get_option( 'fx_pl_built' ) || ( function_exists( 'is_product' ) && is_product() && (int) get_queried_object_id() === (int) $id ) ) {
		return false;
	}
	if ( null === $data ) {
		$data = (array) get_option( 'fx_pl_data', array() );
		if ( ! $data && get_option( 'fx_pl_map' ) ) {
			return false; // mapa z wersji bez gotowych wartości – do pierwszego przeliczenia licz na żywo
		}
	}
	return $data[ $id ] ?? null;
}

/** Mapa rabatów albo null (jeszcze nie policzona). */
function fx_pl_map() {
	static $m = false;
	if ( false === $m ) {
		$m = get_option( 'fx_pl_built' ) ? (array) get_option( 'fx_pl_map', array() ) : null;
	}
	return $m;
}
