<?php
/**
 * Własna wyszukiwarka FOX.SKLEP.PL (zamiast Doofindera).
 *
 * Jak działa:
 * - Indeks: tylko produkty DOSTĘPNE (na stanie) albo w PRZEDSPRZEDAŻY (wtyczka FOX – Przedsprzedaż).
 *   Każdy produkt to jeden rekord w tabeli {prefix}fx_search; zmiana produktu / stanu tylko oznacza
 *   rekord „do odświeżenia” (jedno szybkie zapytanie – import z BaseLinkera nie zwalnia),
 *   a WP-Cron co 5 min przelicza oznaczone i zapisuje gotowy plik JSON w uploads/fx-search/.
 * - Podpowiedzi w polu szukania liczy przeglądarka z tego pliku (ok. 200–300 KB po kompresji,
 *   pobierany przy pierwszym kliknięciu w pole) – wynik przy każdej literze, bez czekania na serwer.
 * - Strona wyników (?s=…&post_type=product) liczy to samo w PHP i pokazuje zwykłą listę produktów
 *   z filtrami motywu (rozmiar, kolor, promocje, wysyłka dziś, w sklepie).
 * - Kolejność: trafność × mnożniki (sprzedaż, kliknięcia z wyszukiwarki, wysyłka dziś, sklep,
 *   promocja, nowość, przedsprzedaż, mnożnik produktu i kategorii) + przypięcia fraz.
 * - Statystyki: co wpisują, ile wyników, w co klikają – WooCommerce → Wyszukiwarka.
 *
 * Silnik dopasowania jest napisany dwa razy (PHP tutaj, JS w assets/js/search.js) według tych samych zasad.
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

define( 'FX_SRCH_DB', 3 );
define( 'FX_SRCH_OPT', 'fx_srch_settings' );

/* =====================================================================
 * Ustawienia
 * ===================================================================== */

function fx_srch_defaults() {
	return array(
		'mode'      => 'test', // off | test (tylko administratorzy) | on (wszyscy)
		'kill_df'   => 1,      // wyłącz skrypty Doofindera tam, gdzie działa nasza wyszukiwarka
		'w_ship'    => 1.5,    // wysyłka dziś
		'w_store'   => 1.15,   // w sklepie stacjonarnym
		'w_sale'    => 1.1,    // promocja
		'w_new'     => 1.15,   // nowość (do 60 dni)
		'w_pre'     => 0.85,   // przedsprzedaż (jeszcze nie na stanie)
		'w_pop'     => 1,      // siła popularności (sprzedaż + kliknięcia); 0 = bez
		'syn'       => "kask, kaski, helmet\nbluza, jersey, koszulka\nspodnie, pants\ngogle, okulary, goggles\nbuty, obuwie, boots\nrękawice, rękawiczki, gloves\nmtb, rower, rowerowy, rowerowe, bike, enduro\nmx, cross, crossowy, crossowe, motocross, moto, motocyklowy, motocyklowe\nochraniacz, ochraniacze, zbroja, buzer, protektor\nbluza z kapturem, hoodie\nkurtka, jacket\nczapka, cap, czapki\nkoszulka, t-shirt, tshirt, tee\ndziecięcy, dziecięce, junior, youth, kids\ndamski, damskie, damska, women, womens",
		'pins'      => '',
		'cat_boost' => '',
		'popular'   => 'kask, gogle, buty, bluza, spodnie, rękawice',
		'max'       => 8,
	);
}

function fx_srch_settings() {
	$s = get_option( FX_SRCH_OPT, array() );
	return array_merge( fx_srch_defaults(), is_array( $s ) ? $s : array() );
}

/** Czy nasza wyszukiwarka działa dla bieżącego odwiedzającego. */
function fx_srch_on() {
	static $on = null;
	if ( null === $on ) {
		$m  = fx_srch_settings()['mode'];
		$on = 'on' === $m || ( 'test' === $m && is_user_logged_in() && current_user_can( 'manage_woocommerce' ) );
		$on = $on && fx_srch_file();
	}
	return $on;
}

/* =====================================================================
 * Tekst: polskie znaki, odmiana (te same zasady w JS)
 * ===================================================================== */

function fx_srch_norm( $s ) {
	$s = mb_strtolower( (string) $s, 'UTF-8' );
	$s = strtr( $s, array( 'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z' ) );
	$s = remove_accents( $s );
	$s = preg_replace( '/[^a-z0-9]+/', ' ', $s );
	return trim( preg_replace( '/\s+/', ' ', $s ) );
}

/** Uproszczona odmiana: „kaski” → „kask”, „rowerowe” → „rower”, „spodnie” → „spodn”. */
function fx_srch_stem( $w ) {
	if ( strlen( $w ) < 5 || preg_match( '/\d/', $w ) ) {
		return '';
	}
	foreach ( array( 'owych', 'owego', 'owymi', 'owej', 'ami', 'ach', 'owy', 'owe', 'owa', 'ego', 'ych', 'ymi', 'ow', 'om', 'ie', 'y', 'i', 'e', 'a', 'u' ) as $end ) {
		$l = strlen( $end );
		if ( substr( $w, -$l ) === $end && strlen( $w ) - $l >= 4 ) {
			return substr( $w, 0, -$l );
		}
	}
	return '';
}

/* =====================================================================
 * Tabela indeksu i dziennik wyszukiwań
 * ===================================================================== */

function fx_srch_table() {
	global $wpdb;
	return $wpdb->prefix . 'fx_search';
}

function fx_srch_log_table() {
	global $wpdb;
	return $wpdb->prefix . 'fx_search_log';
}

add_action( 'init', function () {
	if ( (int) get_option( 'fx_srch_db' ) === FX_SRCH_DB ) {
		return;
	}
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$c = $wpdb->get_charset_collate();
	dbDelta( 'CREATE TABLE ' . fx_srch_table() . " (
		id bigint(20) unsigned NOT NULL,
		data longtext NULL,
		dirty tinyint(1) NOT NULL DEFAULT 1,
		upd int(10) unsigned NOT NULL DEFAULT 0,
		PRIMARY KEY  (id),
		KEY dirty (dirty)
	) $c;" );
	dbDelta( 'CREATE TABLE ' . fx_srch_log_table() . " (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		ts int(10) unsigned NOT NULL,
		q varchar(100) NOT NULL DEFAULT '',
		n int(10) unsigned NOT NULL DEFAULT 0,
		pid bigint(20) unsigned NOT NULL DEFAULT 0,
		src varchar(8) NOT NULL DEFAULT '',
		PRIMARY KEY  (id),
		KEY ts (ts),
		KEY q (q)
	) $c;" );
	update_option( 'fx_srch_db', FX_SRCH_DB, true );
}, 5 );

/** Oznacz produkt do odświeżenia w indeksie (jedno krótkie zapytanie – bez spowalniania zapisu). */
function fx_srch_touch( $id ) {
	$id = (int) $id;
	if ( $id <= 0 || (int) get_option( 'fx_srch_db' ) !== FX_SRCH_DB ) {
		return;
	}
	static $done = array();
	if ( isset( $done[ $id ] ) ) {
		return;
	}
	$done[ $id ] = true;
	global $wpdb;
	$t = fx_srch_table();
	if ( ! $wpdb->query( $wpdb->prepare( "UPDATE $t SET dirty = 1 WHERE id = %d", $id ) ) ) { // phpcs:ignore
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO $t (id, data, dirty, upd) VALUES (%d, NULL, 1, 0)", $id ) ); // phpcs:ignore
	}
}

function fx_srch_touch_parent( $vid ) {
	$p = wp_get_post_parent_id( (int) $vid );
	fx_srch_touch( $p ? $p : $vid );
}

foreach ( array( 'woocommerce_update_product', 'woocommerce_new_product', 'woocommerce_product_set_stock_status', 'wp_trash_post', 'before_delete_post', 'untrashed_post' ) as $fx_h ) {
	add_action( $fx_h, function ( $id ) {
		if ( in_array( get_post_type( $id ), array( 'product', 'product_variation' ), true ) ) {
			fx_srch_touch_parent( $id );
		}
	} );
}
foreach ( array( 'woocommerce_variation_set_stock_status', 'woocommerce_update_product_variation', 'woocommerce_new_product_variation' ) as $fx_h ) {
	add_action( $fx_h, 'fx_srch_touch_parent' );
}

/* =====================================================================
 * Rekord produktu
 * ===================================================================== */

/** Kategorie: id → [nazwa, adres, rodzic] (cache na żądanie). */
function fx_srch_cat( $id ) {
	static $c = array();
	if ( ! isset( $c[ $id ] ) ) {
		$t        = get_term( (int) $id, 'product_cat' );
		$c[ $id ] = ( $t && ! is_wp_error( $t ) ) ? $t : null;
	}
	return $c[ $id ];
}

function fx_srch_rel( $url ) {
	$home = untrailingslashit( home_url() );
	return 0 === strpos( $url, $home ) ? substr( $url, strlen( $home ) ) : $url;
}

/** Czy produkt jest w przedsprzedaży (wtyczka FOX – Przedsprzedaż). */
function fx_srch_preorder_ids() {
	static $ids = null;
	if ( null === $ids ) {
		$ids = class_exists( 'FXPO' ) && method_exists( 'FXPO', 'parent_ids' ) ? FXPO::parent_ids() : array();
	}
	return $ids;
}

/**
 * Rekord do indeksu albo null (produkt niedostępny, ukryty, nieopublikowany).
 */
function fx_srch_record( $id ) {
	$p = wc_get_product( $id );
	if ( ! $p || 'publish' !== get_post_status( $id ) || $p->is_type( 'variation' ) ) {
		return null;
	}
	if ( in_array( $p->get_catalog_visibility(), array( 'hidden', 'catalog' ), true ) ) {
		return null;
	}
	$status = $p->get_stock_status();
	$pre    = 0;
	if ( 'instock' !== $status ) {
		if ( 'onbackorder' === $status && isset( fx_srch_preorder_ids()[ $id ] ) ) {
			$pre = 1;
		} else {
			return null;
		}
	}
	global $wpdb;
	$codes = array( $p->get_sku() );
	$keys  = array( '_woosea_ean', '_woosea_variable_ean', '_global_unique_id' );
	foreach ( $keys as $k ) {
		$codes[] = get_post_meta( $id, $k, true );
	}
	$sizes    = array();
	$size_tax = function_exists( 'fx_filter_taxonomies' ) ? fx_filter_taxonomies()['size'] : array();
	$kids     = $p->is_type( 'variable' ) ? array_map( 'intval', $p->get_children() ) : array();
	if ( $kids ) {
		$in   = implode( ',', $kids );
		$rows = $wpdb->get_results( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ($in) AND ( meta_key IN ('_sku','_woosea_ean','_global_unique_id','_stock_status') OR meta_key LIKE 'attribute\\_%' )" ); // phpcs:ignore
		$by   = array();
		foreach ( $rows as $r ) {
			$by[ (int) $r->post_id ][ $r->meta_key ] = $r->meta_value;
		}
		foreach ( $by as $m ) {
			foreach ( array( '_sku', '_woosea_ean', '_global_unique_id' ) as $k ) {
				if ( ! empty( $m[ $k ] ) ) {
					$codes[] = $m[ $k ];
				}
			}
			if ( ! in_array( $m['_stock_status'] ?? '', array( 'instock', 'onbackorder' ), true ) ) {
				continue;
			}
			foreach ( $size_tax as $tax ) {
				$v = $m[ 'attribute_' . $tax ] ?? '';
				if ( '' === $v ) {
					continue;
				}
				$t     = get_term_by( 'slug', $v, $tax );
				$label = $t ? $t->name : $v;
				$label = function_exists( 'fx_size_norm' ) ? fx_size_norm( $label ) : $label;
				$label = function_exists( 'fx_size_label' ) ? fx_size_label( $label ) : $label;
				if ( ! function_exists( 'fx_size_filterable' ) || fx_size_filterable( $label ) ) {
					$sizes[ $label ] = true;
				}
			}
		}
	}
	$sizes = array_keys( $sizes );
	if ( function_exists( 'fx_size_rank' ) ) {
		usort( $sizes, function ( $a, $b ) {
			return fx_size_rank( $a ) <=> fx_size_rank( $b );
		} );
	}

	// Kategorie: przypisane + nadrzędne (do podpowiedzi kategorii i mnożników).
	$cats = array();
	foreach ( (array) wp_get_post_terms( $id, 'product_cat', array( 'fields' => 'ids' ) ) as $c ) {
		$cats[ (int) $c ] = true;
		foreach ( get_ancestors( (int) $c, 'product_cat', 'taxonomy' ) as $a ) {
			$cats[ (int) $a ] = true;
		}
	}
	$main = function_exists( 'fx_main_category' ) ? fx_main_category( $id ) : null;

	// Dodatkowy tekst do szukania: kody (też bez kresek), kategoria główna, kolor, model.
	$extra = array();
	foreach ( array_unique( array_filter( array_map( 'trim', $codes ) ) ) as $code ) {
		$extra[] = $code;
		$extra[] = preg_replace( '/[^0-9a-z]/i', '', $code );
	}
	if ( $main instanceof WP_Term ) {
		$extra[] = $main->name;
		foreach ( get_ancestors( $main->term_id, 'product_cat', 'taxonomy' ) as $a ) {
			$t = fx_srch_cat( $a );
			if ( $t ) {
				$extra[] = $t->name;
			}
		}
	}
	foreach ( $p->get_attributes() as $tax => $a ) {
		if ( preg_match( '/kolor|color|model|seria|plec|płeć/i', $tax ) ) {
			$extra[] = $p->get_attribute( $tax );
		}
	}
	$extra = fx_srch_norm( implode( ' ', $extra ) );
	$extra = implode( ' ', array_unique( explode( ' ', $extra ) ) );

	// Cena (najniższa dla wariantów).
	if ( $p->is_type( 'variable' ) ) {
		$price = (float) $p->get_variation_price( 'min', true );
		$reg   = (float) $p->get_variation_regular_price( 'min', true );
	} else {
		$price = (float) wc_get_price_to_display( $p );
		$reg   = (float) wc_get_price_to_display( $p, array( 'price' => $p->get_regular_price() ) );
	}

	$img = '';
	$src = $p->get_image_id() ? wp_get_attachment_image_src( $p->get_image_id(), 'woocommerce_thumbnail' ) : false;
	if ( $src ) {
		$up  = wp_get_upload_dir();
		$img = 0 === strpos( $src[0], $up['baseurl'] . '/' ) ? substr( $src[0], strlen( $up['baseurl'] ) + 1 ) : $src[0];
	}
	$boost = (float) get_post_meta( $id, '_fx_srch_boost', true );

	return array(
		'id' => (int) $id,
		't'  => html_entity_decode( wp_strip_all_tags( $p->get_name() ), ENT_QUOTES, 'UTF-8' ),
		'u'  => fx_srch_rel( get_permalink( $id ) ),
		'i'  => $img,
		'p'  => round( $price, 2 ),
		'r'  => $reg > $price ? round( $reg, 2 ) : 0,
		'm'  => $main instanceof WP_Term ? (int) $main->term_id : 0,
		'c'  => array_keys( $cats ),
		'x'  => $extra,
		'z'  => implode( ' ', $sizes ),
		's'  => (int) get_post_meta( $id, 'total_sales', true ),
		'n'  => strtotime( get_post_field( 'post_date_gmt', $id ) . ' UTC' ) > time() - 60 * DAY_IN_SECONDS ? 1 : 0,
		'pr' => $pre,
		'b'  => $boost > 0 ? round( $boost, 2 ) : 1,
	);
}

/* =====================================================================
 * Przeliczanie (cron / panel) i publikacja pliku
 * ===================================================================== */

/** Wszystkie produkty, które mogą być w indeksie (na stanie lub „na zamówienie”) → do odświeżenia. */
function fx_srch_seed_all() {
	global $wpdb;
	$t = fx_srch_table();
	$wpdb->query( "UPDATE $t SET dirty = 1" ); // phpcs:ignore
	$ids = $wpdb->get_col( "SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_stock_status' AND m.meta_value IN ('instock','onbackorder') WHERE p.post_type = 'product' AND p.post_status = 'publish'" ); // phpcs:ignore
	foreach ( array_chunk( array_map( 'intval', $ids ), 500 ) as $chunk ) {
		$wpdb->query( "INSERT IGNORE INTO $t (id, data, dirty, upd) VALUES (" . implode( ', NULL, 1, 0), (', $chunk ) . ', NULL, 1, 0)' ); // phpcs:ignore
	}
	update_option( 'fx_srch_full', gmdate( 'Y-m-d' ), false );
}

/**
 * Przelicza rekordy oznaczone do odświeżenia, najwyżej $budget sekund.
 *
 * @return int ile zostało do przeliczenia
 */
function fx_srch_process( $budget = 40 ) {
	global $wpdb;
	$t     = fx_srch_table();
	$start = microtime( true );
	$did   = 0;
	wp_raise_memory_limit( 'admin' );
	while ( microtime( true ) - $start < $budget ) {
		$ids = $wpdb->get_col( "SELECT id FROM $t WHERE dirty = 1 LIMIT 50" ); // phpcs:ignore
		if ( ! $ids ) {
			break;
		}
		update_meta_cache( 'post', $ids );
		foreach ( $ids as $id ) {
			$r = fx_srch_record( (int) $id );
			if ( $r ) {
				$wpdb->update( $t, array( 'data' => wp_json_encode( $r ), 'dirty' => 0, 'upd' => time() ), array( 'id' => (int) $id ) ); // phpcs:ignore
			} else {
				$wpdb->delete( $t, array( 'id' => (int) $id ) ); // phpcs:ignore
			}
			$did++;
			if ( microtime( true ) - $start >= $budget ) {
				break;
			}
		}
		// Pamięć przy długiej pracy (cache obiektów produktów).
		if ( 0 === $did % 300 ) {
			wp_cache_flush_runtime();
		}
	}
	if ( $did ) {
		update_option( 'fx_srch_need_pub', 1, false );
	}
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE dirty = 1" ); // phpcs:ignore
}

/** Kliknięcia z wyszukiwarki w ostatnich 60 dniach: [ id => liczba ]. */
function fx_srch_clicks() {
	global $wpdb;
	$l    = fx_srch_log_table();
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT pid, COUNT(*) c FROM $l WHERE pid > 0 AND ts > %d GROUP BY pid", time() - 60 * DAY_IN_SECONDS ) ); // phpcs:ignore
	$out  = array();
	foreach ( (array) $rows as $r ) {
		$out[ (int) $r->pid ] = (int) $r->c;
	}
	return $out;
}

/** Popularne frazy (30 dni, z wynikami) – podpowiedzi przy pustym polu. */
function fx_srch_popular() {
	global $wpdb;
	$l    = fx_srch_log_table();
	$rows = $wpdb->get_col( $wpdb->prepare( "SELECT q FROM $l WHERE ts > %d AND n > 0 AND src IN ('dd','page') AND q <> '' GROUP BY q HAVING COUNT(*) >= 3 ORDER BY COUNT(*) DESC LIMIT 8", time() - 30 * DAY_IN_SECONDS ) ); // phpcs:ignore
	$own  = array_filter( array_map( 'trim', explode( ',', (string) fx_srch_settings()['popular'] ) ) );
	return array_values( array_slice( array_unique( array_merge( (array) $rows, $own ) ), 0, 8 ) );
}

/** Linie „a, b, c” → grupy synonimów (znormalizowane). */
function fx_srch_syn_groups( $text ) {
	$out = array();
	foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
		$g = array_values( array_unique( array_filter( array_map( 'fx_srch_norm', explode( ',', $line ) ) ) ) );
		if ( count( $g ) > 1 ) {
			$out[] = $g;
		}
	}
	return $out;
}

/** „fraza | 123, 456” → [ 'fraza' => [123, 456] ]. */
function fx_srch_pins( $text ) {
	$out = array();
	foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
		$parts = array_map( 'trim', explode( '|', $line, 2 ) );
		if ( count( $parts ) < 2 ) {
			continue;
		}
		$q   = fx_srch_norm( $parts[0] );
		$ids = array_values( array_filter( array_map( 'absint', preg_split( '/[\s,;]+/', $parts[1] ) ) ) );
		if ( $q && $ids ) {
			$out[ $q ] = $ids;
		}
	}
	return $out;
}

/** „slug | 1.5” → [ term_id => 1.5 ]. */
function fx_srch_cat_boosts( $text ) {
	$out = array();
	foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
		$parts = array_map( 'trim', explode( '|', $line, 2 ) );
		if ( count( $parts ) < 2 ) {
			continue;
		}
		$t = get_term_by( 'slug', sanitize_title( $parts[0] ), 'product_cat' );
		$v = (float) str_replace( ',', '.', $parts[1] );
		if ( $t && $v > 0 ) {
			$out[ (int) $t->term_id ] = $v;
		}
	}
	return $out;
}

/**
 * Zapisuje plik indeksu dla przeglądarki i strony wyników.
 * Mnożniki zależne od dnia (wysyłka dziś / sklep / kliknięcia) są doliczane tutaj, nie w rekordach.
 */
function fx_srch_publish() {
	global $wpdb;
	$t     = fx_srch_table();
	$s     = fx_srch_settings();
	$items = array();
	$cats  = array();
	$click = fx_srch_clicks();
	$cb    = fx_srch_cat_boosts( $s['cat_boost'] );
	$ship  = function_exists( 'fx_ls_parent_ids' ) ? fx_ls_parent_ids( 'ship' ) : array();
	$store = function_exists( 'fx_ls_parent_ids' ) ? fx_ls_parent_ids( 'store' ) : array();
	foreach ( (array) $wpdb->get_col( "SELECT data FROM $t WHERE data IS NOT NULL" ) as $json ) { // phpcs:ignore
		$r = json_decode( $json, true );
		if ( ! is_array( $r ) ) {
			continue;
		}
		$id = $r['id'];
		// Mnożnik dnia: wysyłka dziś, sklep, promocja, nowość, przedsprzedaż, kategoria, ręczny.
		$w = (float) $r['b'];
		$w *= isset( $ship[ $id ] ) ? (float) $s['w_ship'] : 1;
		$w *= isset( $store[ $id ] ) ? (float) $s['w_store'] : 1;
		$w *= $r['r'] ? (float) $s['w_sale'] : 1;
		$w *= $r['n'] ? (float) $s['w_new'] : 1;
		$w *= $r['pr'] ? (float) $s['w_pre'] : 1;
		$cmax = 1;
		foreach ( $r['c'] as $c ) {
			$cats[ $c ] = true;
			if ( isset( $cb[ $c ] ) ) {
				$cmax = max( $cmax, $cb[ $c ] );
			}
		}
		$w *= $cmax;
		// Popularność: sprzedaż (od zawsze) + kliknięcia z wyszukiwarki (60 dni), w skali logarytmicznej.
		$pop = 1 + (float) $s['w_pop'] * ( 0.12 * log( 1 + $r['s'], 2 ) + 0.25 * log( 1 + ( $click[ $id ] ?? 0 ), 2 ) );
		$r['w'] = round( $w * $pop, 3 );
		$r['f'] = ( isset( $ship[ $id ] ) ? 1 : 0 ) | ( isset( $store[ $id ] ) ? 2 : 0 );
		unset( $r['b'], $r['s'], $r['n'] );
		$items[] = $r;
	}
	$catmap = array();
	foreach ( array_keys( $cats ) as $c ) {
		$term = fx_srch_cat( $c );
		if ( $term ) {
			$catmap[ $c ] = array( $term->name, fx_srch_rel( get_term_link( $term ) ), (int) $term->parent );
		}
	}
	$up   = wp_get_upload_dir();
	$data = array(
		'v'     => 1,
		'at'    => time(),
		'img'   => $up['baseurl'] . '/',
		'cats'  => (object) $catmap,
		'syn'   => fx_srch_syn_groups( $s['syn'] ),
		'pins'  => (object) fx_srch_pins( $s['pins'] ),
		'pop'   => fx_srch_popular(),
		'items' => $items,
	);
	$json = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	$dir  = trailingslashit( $up['basedir'] ) . 'fx-search';
	if ( ! wp_mkdir_p( $dir ) ) {
		return false;
	}
	$name = 'indeks-' . substr( md5( $json ), 0, 10 ) . '.json';
	if ( false === file_put_contents( $dir . '/' . $name, $json ) ) { // phpcs:ignore
		return false;
	}
	if ( function_exists( 'gzencode' ) ) {
		file_put_contents( $dir . '/' . $name . '.gz', gzencode( $json, 9 ) ); // phpcs:ignore — dla serwerów z gzip_static
	}
	$old = get_option( 'fx_srch_file', array() );
	update_option(
		'fx_srch_file',
		array(
			'name'  => $name,
			'at'    => time(),
			'n'     => count( $items ),
			'size'  => strlen( $json ),
			'ls_at' => function_exists( 'fx_ls_data' ) ? (int) fx_ls_data()['at'] : 0,
		),
		true
	);
	delete_option( 'fx_srch_need_pub' );
	// Stare pliki usuwamy z opóźnieniem (otwarte strony mogą jeszcze je pobierać).
	foreach ( (array) glob( $dir . '/indeks-*.json*' ) as $f ) {
		if ( false === strpos( $f, $name ) && filemtime( $f ) < time() - HOUR_IN_SECONDS ) {
			@unlink( $f ); // phpcs:ignore
		}
	}
	if ( ! empty( $old['name'] ) && $old['name'] !== $name ) {
		do_action( 'litespeed_purge_url', '/?s=' ); // wyniki wyszukiwania z cache
	}
	return true;
}

/** Plik indeksu: [ url, ścieżka ] albo null. */
function fx_srch_file() {
	$f = get_option( 'fx_srch_file' );
	if ( empty( $f['name'] ) ) {
		return null;
	}
	$up = wp_get_upload_dir();
	return array(
		'url'  => $up['baseurl'] . '/fx-search/' . $f['name'],
		'path' => trailingslashit( $up['basedir'] ) . 'fx-search/' . $f['name'],
	);
}

/* Cron co 5 minut: zmiany produktów, nocne pełne odświeżenie, nowa lista „wysyłka dziś”. */
add_filter( 'cron_schedules', function ( $s ) {
	$s['fx_5min'] = array( 'interval' => 300, 'display' => 'Co 5 minut' );
	return $s;
} );
add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'fx_srch_cron' ) ) {
		wp_schedule_event( time() + 60, 'fx_5min', 'fx_srch_cron' );
	}
} );
add_action( 'fx_srch_cron', 'fx_srch_cron_run' );
function fx_srch_cron_run() {
	if ( get_transient( 'fx_srch_lock' ) ) {
		return;
	}
	set_transient( 'fx_srch_lock', 1, 4 * MINUTE_IN_SECONDS );
	$hour = (int) wp_date( 'G' );
	if ( ! get_option( 'fx_srch_file' ) || ( get_option( 'fx_srch_full' ) !== gmdate( 'Y-m-d' ) && $hour >= 3 && $hour < 5 ) ) {
		fx_srch_seed_all();
		// Dziennik: tylko 180 dni.
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . fx_srch_log_table() . ' WHERE ts < %d', time() - 180 * DAY_IN_SECONDS ) ); // phpcs:ignore
	}
	$left = fx_srch_process( 40 );
	$f    = get_option( 'fx_srch_file', array() );
	$ls   = function_exists( 'fx_ls_data' ) ? (int) fx_ls_data()['at'] : 0;
	$need = get_option( 'fx_srch_need_pub' ) || empty( $f['name'] ) || $ls > (int) ( $f['ls_at'] ?? 0 ) || ( (int) ( $f['at'] ?? 0 ) < time() - 6 * HOUR_IN_SECONDS );
	// Przy dużej kolejce (import) publikujemy co najwyżej co 15 min.
	if ( $need && ( ! $left || (int) ( $f['at'] ?? 0 ) < time() - 15 * MINUTE_IN_SECONDS ) ) {
		fx_srch_publish();
	}
	delete_transient( 'fx_srch_lock' );
}

/* =====================================================================
 * Silnik dopasowania (PHP) – strona wyników
 * ===================================================================== */

/** Indeks z pliku (raz na zapytanie). */
function fx_srch_index() {
	static $idx = null;
	if ( null !== $idx ) {
		return $idx;
	}
	$idx = array();
	$f   = fx_srch_file();
	if ( $f && is_readable( $f['path'] ) ) {
		$d = json_decode( (string) file_get_contents( $f['path'] ), true ); // phpcs:ignore
		if ( is_array( $d ) ) {
			$idx = $d;
		}
	}
	return $idx;
}

/** Przygotowane rekordy: słowa tytułu i dodatkowe, słownik słów. */
function fx_srch_prepared() {
	static $prep = null;
	if ( null !== $prep ) {
		return $prep;
	}
	$idx  = fx_srch_index();
	$prep = array( 'items' => array(), 'vocab' => array() );
	foreach ( (array) ( $idx['items'] ?? array() ) as $it ) {
		$tw = explode( ' ', fx_srch_norm( $it['t'] ) );
		$xw = '' === $it['x'] ? array() : explode( ' ', $it['x'] );
		foreach ( $tw as $w ) {
			if ( strlen( $w ) > 2 && ! ctype_digit( $w ) ) {
				$prep['vocab'][ $w ] = ( $prep['vocab'][ $w ] ?? 0 ) + 1;
			}
		}
		$prep['items'][] = array( $it, $tw, $xw, implode( ' ', $tw ) . ' ' . $it['x'] );
	}
	return $prep;
}

const FX_SRCH_STOP = array( 'dla', 'i', 'z', 'na', 'do', 'w', 'the', 'and', 'od', 'ze', 'oraz' );

/** Odległość edycyjna z zamianą sąsiednich liter („gogel” → „gogle” = 1). Tak samo w JS. */
function fx_srch_lev( $a, $b ) {
	$m = strlen( $a );
	$n = strlen( $b );
	$d = array();
	for ( $i = 0; $i <= $m; $i++ ) {
		$d[ $i ][0] = $i;
	}
	for ( $j = 0; $j <= $n; $j++ ) {
		$d[0][ $j ] = $j;
	}
	for ( $i = 1; $i <= $m; $i++ ) {
		for ( $j = 1; $j <= $n; $j++ ) {
			$c          = $a[ $i - 1 ] === $b[ $j - 1 ] ? 0 : 1;
			$d[ $i ][ $j ] = min( $d[ $i - 1 ][ $j ] + 1, $d[ $i ][ $j - 1 ] + 1, $d[ $i - 1 ][ $j - 1 ] + $c );
			if ( $i > 1 && $j > 1 && $a[ $i - 1 ] === $b[ $j - 2 ] && $a[ $i - 2 ] === $b[ $j - 1 ] ) {
				$d[ $i ][ $j ] = min( $d[ $i ][ $j ], $d[ $i - 2 ][ $j - 2 ] + 1 );
			}
		}
	}
	return $d[ $m ][ $n ];
}

/**
 * Tokeny zapytania z wariantami (synonimy, odmiana) i poprawką literówek.
 *
 * @return array [ tokens => [ [ alts ] ], fixed => string|'' ]
 */
function fx_srch_tokens( $q ) {
	$idx  = fx_srch_index();
	$prep = fx_srch_prepared();
	$words = array_values( array_filter( explode( ' ', fx_srch_norm( $q ) ), 'strlen' ) );
	if ( count( $words ) > 1 ) {
		$words = array_values( array_filter( $words, function ( $w ) {
			return ! in_array( $w, FX_SRCH_STOP, true );
		} ) );
	}
	$words = array_slice( $words, 0, 8 );
	$fixed = false;
	foreach ( $words as $i => $w ) {
		if ( strlen( $w ) < 4 || preg_match( '/\d/', $w ) ) {
			continue;
		}
		$stem = fx_srch_stem( $w );
		$hit  = false;
		foreach ( $prep['vocab'] as $v => $n ) {
			if ( 0 === strpos( $v, $w ) || ( $stem && 0 === strpos( $v, $stem ) ) ) {
				$hit = true;
				break;
			}
		}
		if ( $hit || fx_srch_syn_of( $w, $idx ) ) {
			continue;
		}
		$max  = strlen( $w ) <= 5 ? 1 : 2;
		$best = '';
		$bd   = 99;
		$bn   = 0;
		foreach ( $prep['vocab'] as $v => $n ) {
			if ( abs( strlen( $v ) - strlen( $w ) ) > $max ) {
				continue;
			}
			$d = fx_srch_lev( $w, $v );
			if ( $d <= $max && ( $d < $bd || ( $d === $bd && $n > $bn ) ) ) {
				$best = $v;
				$bd   = $d;
				$bn   = $n;
			}
		}
		if ( $best ) {
			$words[ $i ] = $best;
			$fixed       = true;
		}
	}
	$tokens = array();
	foreach ( $words as $w ) {
		$alts = array( $w );
		foreach ( fx_srch_syn_of( $w, $idx ) as $s ) {
			$alts[] = $s;
		}
		$tokens[] = array_values( array_unique( $alts ) );
	}
	return array( 'tokens' => $tokens, 'words' => $words, 'fixed' => $fixed ? implode( ' ', $words ) : '' );
}

/** Synonimy słowa (grupy z ustawień; dopasowanie także po odmianie). */
function fx_srch_syn_of( $w, $idx ) {
	$out  = array();
	$stem = fx_srch_stem( $w );
	foreach ( (array) ( $idx['syn'] ?? array() ) as $g ) {
		foreach ( $g as $s ) {
			if ( $s === $w || ( $stem && fx_srch_stem( $s ) === $stem ) ) {
				foreach ( $g as $x ) {
					if ( $x !== $w && false === strpos( $x, ' ' ) ) {
						$out[] = $x;
					}
				}
				break;
			}
		}
	}
	return $out;
}

/** Punkty za jeden token (0 = brak dopasowania). Te same progi co w JS. */
function fx_srch_tok_score( $alts, $tw, $xw ) {
	$best = 0;
	foreach ( $alts as $k => $a ) {
		$syn  = $k > 0 ? 0.8 : 1; // synonim trochę słabiej niż wpisane słowo
		$stem = fx_srch_stem( $a );
		$code = strlen( $a ) >= 4 && preg_match( '/\d/', $a );
		foreach ( $tw as $i => $w ) {
			$s = 0;
			if ( $w === $a ) {
				$s = 12;
			} elseif ( 0 === strpos( $w, $a ) ) {
				$s = strlen( $a ) >= 2 ? 9 : 6;
			} elseif ( $stem && 0 === strpos( $w, $stem ) ) {
				$s = 8;
			} elseif ( strlen( $a ) >= 3 && false !== strpos( $w, $a ) ) {
				$s = 4;
			}
			if ( $s && 0 === $i ) {
				$s += 1;
			}
			$best = max( $best, $s * $syn );
		}
		foreach ( $xw as $w ) {
			$s = 0;
			if ( $w === $a ) {
				$s = $code ? 30 : 6;
			} elseif ( 0 === strpos( $w, $a ) && ( $code || strlen( $a ) >= 2 ) ) {
				$s = $code ? 18 : 5;
			} elseif ( $stem && 0 === strpos( $w, $stem ) ) {
				$s = 4;
			} elseif ( strlen( $a ) >= 4 && false !== strpos( $w, $a ) ) {
				$s = 2;
			}
			$best = max( $best, $s * $syn );
		}
	}
	return $best;
}

/**
 * Wyszukiwanie: [ ids (kolejność), fixed (poprawione zapytanie), partial ].
 */
function fx_srch_query( $q ) {
	static $cache = array();
	if ( isset( $cache[ $q ] ) ) {
		return $cache[ $q ];
	}
	$idx  = fx_srch_index();
	$prep = fx_srch_prepared();
	$tk   = fx_srch_tokens( $q );
	$out  = array( 'ids' => array(), 'fixed' => $tk['fixed'], 'partial' => false );
	if ( ! $tk['tokens'] ) {
		return $cache[ $q ] = $out;
	}
	$n       = count( $tk['tokens'] );
	$scores  = array();
	$partial = array();
	// Szybkie odrzucenie: każde dopasowanie jest podciągiem tekstu produktu.
	$nds = array();
	foreach ( $tk['tokens'] as $k => $alts ) {
		foreach ( $alts as $a ) {
			$st          = fx_srch_stem( $a );
			$nds[ $k ][] = $st ? $st : $a;
		}
	}
	foreach ( $prep['items'] as $row ) {
		list( $it, $tw, $xw, $all ) = $row;
		$sum = 0;
		$hit = 0;
		foreach ( $tk['tokens'] as $k => $alts ) {
			$maybe = false;
			foreach ( $nds[ $k ] as $nd ) {
				if ( false !== strpos( $all, $nd ) ) {
					$maybe = true;
					break;
				}
			}
			$s = $maybe ? fx_srch_tok_score( $alts, $tw, $xw ) : 0;
			if ( $s > 0 ) {
				$hit++;
				$sum += $s;
			}
		}
		if ( ! $hit ) {
			continue;
		}
		$score = $sum * (float) $it['w'];
		if ( $hit === $n ) {
			$scores[ $it['id'] ] = $score;
		} elseif ( $n > 1 && $hit >= ceil( $n / 2 ) ) {
			$partial[ $it['id'] ] = $score * $hit / $n;
		}
	}
	if ( ! $scores && $partial ) {
		$scores         = $partial;
		$out['partial'] = true;
	}
	arsort( $scores );
	$ids  = array_keys( $scores );
	$pins = (array) ( $idx['pins'] ?? array() );
	$key  = implode( ' ', $tk['words'] );
	if ( ! empty( $pins[ $key ] ) ) {
		$present = array_flip( array_map( 'intval', array_column( (array) $idx['items'], 'id' ) ) );
		$pin     = array_values( array_filter( array_map( 'intval', $pins[ $key ] ), function ( $id ) use ( $present ) {
			return isset( $present[ $id ] );
		} ) );
		$ids = array_values( array_unique( array_merge( $pin, $ids ) ) );
	}
	$out['ids'] = array_map( 'intval', $ids );
	return $cache[ $q ] = $out;
}

/* =====================================================================
 * Strona wyników (?s=…): tylko indeks, kolejność trafności, filtry motywu
 * ===================================================================== */

function fx_srch_is_results() {
	return ! is_admin() && is_search() && fx_srch_on() && fx_srch_current_q() !== '';
}

function fx_srch_current_q() {
	return trim( (string) get_search_query( false ) );
}

/** Wynik dla bieżącego zapytania. */
function fx_srch_current() {
	static $r = null;
	if ( null === $r ) {
		$r = fx_srch_query( fx_srch_current_q() );
	}
	return $r;
}

add_action( 'pre_get_posts', function ( $q ) {
	if ( is_admin() || ! $q->is_main_query() || ! $q->is_search() || ! fx_srch_on() ) {
		return;
	}
	$s = trim( (string) $q->get( 's' ) );
	if ( '' === $s ) {
		return;
	}
	$r = fx_srch_query( wp_unslash( $s ) );
	$q->set( 'post_type', 'product' );
	$q->set( 'post__in', $r['ids'] ? $r['ids'] : array( 0 ) );
	$q->set( 'fx_srch', 1 );
	$GLOBALS['fx_srch_ids'] = $r['ids'] ? $r['ids'] : array( 0 );
}, 5 );

/* WooCommerce ustawia listę produktów na nowo (filtr loop_shop_post_in) – podajemy mu wyniki silnika. */
add_filter( 'loop_shop_post_in', function ( $ids ) {
	if ( isset( $GLOBALS['fx_srch_ids'] ) && fx_srch_is_results() ) {
		return $ids ? array_values( array_intersect( $GLOBALS['fx_srch_ids'], array_map( 'intval', $ids ) ) ) : $GLOBALS['fx_srch_ids'];
	}
	return $ids;
}, 99 );

/* Bez wbudowanego „LIKE %słowo%” WordPressa – wyniki wybiera nasz silnik. */
add_filter( 'posts_search', function ( $sql, $q ) {
	return $q->get( 'fx_srch' ) ? '' : $sql;
}, 99, 2 );
add_filter( 'posts_search_orderby', function ( $sql, $q ) {
	return $q->get( 'fx_srch' ) ? '' : $sql;
}, 99, 2 );

/* Domyślne sortowanie wyników = trafność (kolejność silnika); wybór „cena” itp. dalej działa. */
add_filter( 'woocommerce_get_catalog_ordering_args', function ( $args, $orderby ) {
	if ( fx_srch_is_results() && ( '' === $orderby || 'relevance' === $orderby || ( empty( $_GET['orderby'] ) && 'menu_order' === $orderby ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return array( 'orderby' => 'post__in', 'order' => 'ASC', 'meta_key' => '' ); // phpcs:ignore WordPress.DB.SlowDBQuery
	}
	return $args;
}, 99, 2 );

/* Wyniki zawsze jako lista produktów (nie podkategorie). */
add_filter( 'woocommerce_get_loop_display_mode', function ( $m ) {
	return fx_srch_is_results() ? 'products' : $m;
}, 99 );

/** Tytuł strony wyników. */
add_filter( 'woocommerce_page_title', function ( $t ) {
	if ( ! fx_srch_is_results() ) {
		return $t;
	}
	$r = fx_srch_current();
	return 'Wyniki dla „' . esc_html( $r['fixed'] ? $r['fixed'] : fx_srch_current_q() ) . '”';
}, 99 );

/* Nad wynikami: poprawka literówki / wyniki częściowe. */
add_action( 'woocommerce_before_shop_loop', function () {
	if ( ! fx_srch_is_results() ) {
		return;
	}
	$r = fx_srch_current();
	if ( $r['fixed'] ) {
		echo '<p class="fx-srch-note">Nie znaleźliśmy „' . esc_html( fx_srch_current_q() ) . '” – pokazujemy wyniki dla <strong>„' . esc_html( $r['fixed'] ) . '”</strong>.</p>';
	} elseif ( $r['partial'] ) {
		echo '<p class="fx-srch-note">Brak produktów pasujących do wszystkich słów – pokazujemy najbliższe.</p>';
	}
}, 16 );

/* Brak wyników: podpowiedzi zamiast pustej strony. */
add_action( 'woocommerce_no_products_found', function () {
	if ( ! fx_srch_is_results() ) {
		return;
	}
	$pop = fx_srch_index()['pop'] ?? array();
	echo '<div class="fx-srch-empty"><p>Sprawdź pisownię albo wpisz mniej słów – np. sam model („V1”, „Proframe”) lub rodzaj produktu („kask”, „gogle”).</p>';
	if ( $pop ) {
		echo '<p class="fx-srch-empty__h">Często szukane:</p><div class="fx-srch-chips">';
		foreach ( $pop as $p ) {
			echo '<a class="fx-chip" href="' . esc_url( add_query_arg( array( 's' => $p, 'post_type' => 'product' ), home_url( '/' ) ) ) . '">' . esc_html( $p ) . '</a>';
		}
		echo '</div>';
	}
	echo '</div>';
}, 20 );

/* Wyniki wyszukiwania nie trafiają do Google (jak dotąd). */
add_filter( 'wp_robots', function ( $r ) {
	if ( fx_srch_is_results() ) {
		$r['noindex'] = true;
		$r['follow']  = true;
		unset( $r['index'] );
	}
	return $r;
}, 99 );

/* Wyszukanie kodu (SKU / EAN) z jednym wynikiem → od razu karta produktu. */
add_action( 'template_redirect', function () {
	if ( ! fx_srch_is_results() || ! empty( $_GET['orderby'] ) || fx_filters_active() ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	$q = fx_srch_norm( fx_srch_current_q() );
	if ( ! preg_match( '/\d{4,}/', $q ) ) {
		return;
	}
	$r = fx_srch_current();
	if ( 1 === count( $r['ids'] ) ) {
		wp_safe_redirect( get_permalink( $r['ids'][0] ), 302 );
		exit;
	}
} );

/* =====================================================================
 * Front: skrypt, dane, wyłączenie Doofindera
 * ===================================================================== */

add_action( 'wp_enqueue_scripts', function () {
	if ( ! fx_srch_on() ) {
		return;
	}
	$f = fx_srch_file();
	$s = fx_srch_settings();
	wp_enqueue_script( 'fx-search', FX_URI . '/assets/js/search.js', array(), FX_VERSION, array( 'strategy' => 'defer', 'in_footer' => true ) );
	$cfg = array(
		'index'  => $f['url'],
		'home'   => untrailingslashit( home_url() ),
		'log'    => rest_url( 'fx/v1/szukaj' ),
		'max'    => max( 4, min( 12, (int) $s['max'] ) ),
		'q'      => fx_srch_is_results() ? fx_srch_current_q() : '',
		'n'      => fx_srch_is_results() ? count( fx_srch_current()['ids'] ) : 0,
		'ship'   => function_exists( 'fx_ls_settings' ) ? fx_ls_settings()['ship_label'] : 'Wysyłka dziś',
		'cutoff' => function_exists( 'fox_bl_delivery_get_settings' ) ? fox_bl_delivery_get_settings()['cutoff_time'] : '15:00',
		'hol'    => function_exists( 'fx_ship_cta_conf' ) ? array_values( (array) fx_ship_cta_conf()['blocked'] ) : array(),
	);
	wp_add_inline_script( 'fx-search', 'window.fxSearch=' . wp_json_encode( $cfg ) . ';', 'before' );
}, 20 );

/* Plik indeksu łączy się z tą samą domeną – wcześniejsze pobranie po najechaniu / dotknięciu pola. */

/**
 * Doofinder: tam, gdzie działa nasza wyszukiwarka, jego skrypty się nie ładują (inaczej oba okna
 * podpowiedzi otwierałyby się naraz). Szukamy funkcji wtyczki Doofinder podpiętych pod nagłówek/stopkę.
 */
add_action( 'wp', function () {
	if ( is_admin() || ! fx_srch_on() || ! fx_srch_settings()['kill_df'] ) {
		return;
	}
	foreach ( array( 'wp_head', 'wp_footer', 'wp_enqueue_scripts', 'wp_print_footer_scripts', 'wp_body_open' ) as $hook ) {
		global $wp_filter;
		if ( empty( $wp_filter[ $hook ] ) ) {
			continue;
		}
		foreach ( $wp_filter[ $hook ]->callbacks as $prio => $cbs ) {
			foreach ( $cbs as $key => $cb ) {
				$fn   = $cb['function'];
				$file = '';
				try {
					if ( is_array( $fn ) ) {
						$file = ( new ReflectionMethod( is_object( $fn[0] ) ? get_class( $fn[0] ) : $fn[0], $fn[1] ) )->getFileName();
					} elseif ( $fn instanceof Closure || ( is_string( $fn ) && function_exists( $fn ) ) ) {
						$file = ( new ReflectionFunction( $fn ) )->getFileName();
					}
				} catch ( ReflectionException $e ) {
					$file = '';
				}
				if ( $file && false !== stripos( $file, 'doofinder' ) ) {
					remove_action( $hook, $fn, $prio );
				}
			}
		}
	}
} );
add_filter( 'script_loader_tag', function ( $tag, $handle, $src ) {
	if ( ! is_admin() && fx_srch_on() && fx_srch_settings()['kill_df'] && ( false !== stripos( $handle, 'doofinder' ) || false !== stripos( (string) $src, 'doofinder.com' ) ) ) {
		return '';
	}
	return $tag;
}, 99, 3 );
add_filter( 'fx_perf_preconnect', function ( $hosts ) {
	if ( fx_srch_on() && fx_srch_settings()['kill_df'] ) {
		$hosts = array_values( array_filter( $hosts, function ( $h ) {
			return false === strpos( $h, 'doofinder' );
		} ) );
	}
	return $hosts;
} );

/* =====================================================================
 * Dziennik: co wpisują, ile wyników, w co klikają
 * ===================================================================== */

add_action( 'rest_api_init', function () {
	register_rest_route(
		'fx/v1',
		'/szukaj',
		array(
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => function ( WP_REST_Request $r ) {
				$q   = mb_substr( fx_srch_norm( (string) $r->get_param( 'q' ) ), 0, 100 );
				$src = in_array( $r->get_param( 'src' ), array( 'dd', 'page', 'ddc', 'pagec' ), true ) ? $r->get_param( 'src' ) : 'dd';
				if ( '' === $q || mb_strlen( $q ) < 2 ) {
					return new WP_REST_Response( null, 204 );
				}
				// Ochrona przed zalewaniem: najwyżej 60 wpisów na minutę z jednego adresu.
				$ip  = md5( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) ); // phpcs:ignore
				$key = 'fx_sl_' . substr( $ip, 0, 12 );
				$cnt = (int) get_transient( $key );
				if ( $cnt > 60 ) {
					return new WP_REST_Response( null, 429 );
				}
				set_transient( $key, $cnt + 1, MINUTE_IN_SECONDS );
				global $wpdb;
				$wpdb->insert( // phpcs:ignore
					fx_srch_log_table(),
					array(
						'ts'  => time(),
						'q'   => $q,
						'n'   => min( 100000, absint( $r->get_param( 'n' ) ) ),
						'pid' => absint( $r->get_param( 'pid' ) ),
						'src' => $src,
					)
				);
				return new WP_REST_Response( null, 204 );
			},
		)
	);
} );

/* =====================================================================
 * Produkt: „Mnożnik w wyszukiwarce”
 * ===================================================================== */

add_action( 'woocommerce_product_options_general_product_data', function () {
	woocommerce_wp_text_input(
		array(
			'id'                => '_fx_srch_boost',
			'label'             => 'Mnożnik w wyszukiwarce',
			'placeholder'       => '1',
			'type'              => 'number',
			'custom_attributes' => array( 'step' => '0.1', 'min' => '0.1', 'max' => '10' ),
			'desc_tip'          => true,
			'description'       => '1 = normalnie. 2 = wyraźnie wyżej w wynikach, 0,5 = niżej. Działa tylko wśród produktów pasujących do wpisanej frazy.',
		)
	);
} );
add_action( 'woocommerce_admin_process_product_object', function ( $p ) {
	if ( isset( $_POST['_fx_srch_boost'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		$v = (float) str_replace( ',', '.', wp_unslash( $_POST['_fx_srch_boost'] ) ); // phpcs:ignore
		if ( $v > 0 && 1.0 !== $v ) {
			$p->update_meta_data( '_fx_srch_boost', min( 10, $v ) );
		} else {
			$p->delete_meta_data( '_fx_srch_boost' );
		}
	}
} );

/* =====================================================================
 * Panel: WooCommerce → Wyszukiwarka
 * ===================================================================== */

add_action( 'admin_menu', function () {
	add_submenu_page( 'woocommerce', 'Wyszukiwarka FOX', 'Wyszukiwarka', 'manage_woocommerce', 'fx-szukaj', 'fx_srch_admin_page' );
}, 60 );

add_action( 'admin_post_fx_srch_save', function () {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( 'Brak uprawnień' );
	}
	check_admin_referer( 'fx_srch_save' );
	$in  = wp_unslash( $_POST['fx'] ?? array() ); // phpcs:ignore
	$d   = fx_srch_defaults();
	$out = array(
		'mode'    => in_array( $in['mode'] ?? '', array( 'off', 'test', 'on' ), true ) ? $in['mode'] : 'test',
		'kill_df' => empty( $in['kill_df'] ) ? 0 : 1,
		'max'     => max( 4, min( 12, (int) ( $in['max'] ?? 8 ) ) ),
	);
	foreach ( array( 'w_ship', 'w_store', 'w_sale', 'w_new', 'w_pre', 'w_pop' ) as $k ) {
		$v         = (float) str_replace( ',', '.', (string) ( $in[ $k ] ?? $d[ $k ] ) );
		$out[ $k ] = max( 0, min( 10, $v ) );
	}
	foreach ( array( 'syn', 'pins', 'cat_boost' ) as $k ) {
		$out[ $k ] = sanitize_textarea_field( (string) ( $in[ $k ] ?? '' ) );
	}
	$out['popular'] = sanitize_text_field( (string) ( $in['popular'] ?? '' ) );
	update_option( FX_SRCH_OPT, $out, false );
	if ( fx_srch_file() ) {
		fx_srch_publish(); // mnożniki i synonimy działają od razu
	}
	wp_safe_redirect( admin_url( 'admin.php?page=fx-szukaj&zapisano=1' ) );
	exit;
} );

add_action( 'wp_ajax_fx_srch_build', function () {
	check_ajax_referer( 'fx_srch_build' );
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( null, 403 );
	}
	if ( ! empty( $_POST['seed'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		fx_srch_seed_all();
	}
	$left = fx_srch_process( 20 );
	if ( ! $left ) {
		fx_srch_publish();
	}
	global $wpdb;
	wp_send_json_success(
		array(
			'left'  => $left,
			'total' => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . fx_srch_table() ), // phpcs:ignore
			'file'  => get_option( 'fx_srch_file' ),
		)
	);
} );

function fx_srch_admin_page() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}
	global $wpdb;
	$s     = fx_srch_settings();
	$f     = get_option( 'fx_srch_file', array() );
	$t     = fx_srch_table();
	$l     = fx_srch_log_table();
	$rows  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t" ); // phpcs:ignore
	$dirty = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE dirty = 1" ); // phpcs:ignore
	$since = time() - 30 * DAY_IN_SECONDS;
	$tot   = $wpdb->get_row( $wpdb->prepare( "SELECT SUM(src IN ('dd','page')) s, SUM(src IN ('dd','page') AND n = 0) z, SUM(pid > 0) c FROM $l WHERE ts > %d", $since ) ); // phpcs:ignore
	$top   = $wpdb->get_results( $wpdb->prepare( "SELECT q, SUM(src IN ('dd','page')) s, SUM(pid > 0) c, ROUND(AVG(CASE WHEN src IN ('dd','page') THEN n END)) n FROM $l WHERE ts > %d GROUP BY q ORDER BY s DESC LIMIT 40", $since ) ); // phpcs:ignore
	$zero  = $wpdb->get_results( $wpdb->prepare( "SELECT q, COUNT(*) s FROM $l WHERE ts > %d AND n = 0 AND src IN ('dd','page') GROUP BY q ORDER BY s DESC LIMIT 40", $since ) ); // phpcs:ignore
	$clk   = $wpdb->get_results( $wpdb->prepare( "SELECT pid, COUNT(*) c FROM $l WHERE ts > %d AND pid > 0 GROUP BY pid ORDER BY c DESC LIMIT 20", $since ) ); // phpcs:ignore
	$n     = function ( $v ) {
		return number_format_i18n( (float) $v );
	};
	?>
	<div class="wrap fx-srch-admin">
		<h1>Wyszukiwarka FOX</h1>
		<?php if ( isset( $_GET['zapisano'] ) ) : // phpcs:ignore ?>
			<div class="notice notice-success is-dismissible"><p>Zapisano. Indeks przeliczony z nowymi ustawieniami.</p></div>
		<?php endif; ?>
		<div class="fx-srch-cards">
			<div class="fx-srch-card">
				<h2>Indeks</h2>
				<p>Produktów w wyszukiwarce (dostępne + przedsprzedaż): <strong data-fx-total><?php echo esc_html( $n( $f['n'] ?? 0 ) ); ?></strong><br>
				Plik: <?php echo ! empty( $f['size'] ) ? esc_html( size_format( $f['size'], 1 ) ) . ' (po kompresji ok. ' . esc_html( size_format( $f['size'] * 0.2, 0 ) ) . ')' : '—'; ?>, zapisany: <?php echo ! empty( $f['at'] ) ? esc_html( wp_date( 'd.m H:i', $f['at'] ) ) : 'jeszcze nie'; ?><br>
				Czeka na przeliczenie: <strong data-fx-left><?php echo esc_html( $n( $dirty ) ); ?></strong> z <?php echo esc_html( $n( $rows ) ); ?> (automat co 5 min)</p>
				<p><button class="button button-primary" data-fx-build>Przebuduj cały indeks teraz</button> <span data-fx-status></span></p>
				<p class="description">Nowe i zmienione produkty trafiają do wyszukiwarki same w ciągu ok. 5 minut, a raz na dobę (3:00) cały indeks jest odświeżany.</p>
			</div>
			<div class="fx-srch-card">
				<h2>Ostatnie 30 dni</h2>
				<p>Wyszukiwań: <strong><?php echo esc_html( $n( $tot->s ?? 0 ) ); ?></strong><br>
				Bez wyników: <strong><?php echo esc_html( $n( $tot->z ?? 0 ) ); ?></strong> (<?php echo $tot && $tot->s ? esc_html( round( 100 * $tot->z / $tot->s ) ) : 0; ?>%)<br>
				Kliknięć w produkt z wyszukiwarki: <strong><?php echo esc_html( $n( $tot->c ?? 0 ) ); ?></strong></p>
				<p class="description">Sprzedaż z wyszukiwarki widać w GA4 (zdarzenie „view_search_results”, parametr s).</p>
			</div>
		</div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'fx_srch_save' ); ?>
			<input type="hidden" name="action" value="fx_srch_save">
			<h2>Ustawienia</h2>
			<table class="form-table" role="presentation">
				<tr><th>Wyszukiwarka</th><td>
					<?php foreach ( array( 'off' => 'Wyłączona (zwykłe wyszukiwanie WordPressa / Doofinder)', 'test' => 'Test – tylko dla zalogowanych administratorów', 'on' => 'Włączona dla wszystkich klientów' ) as $k => $label ) : ?>
						<label style="display:block;margin-bottom:4px"><input type="radio" name="fx[mode]" value="<?php echo esc_attr( $k ); ?>" <?php checked( $s['mode'], $k ); ?>> <?php echo esc_html( $label ); ?></label>
					<?php endforeach; ?>
				</td></tr>
				<tr><th>Doofinder</th><td><label><input type="checkbox" name="fx[kill_df]" value="1" <?php checked( $s['kill_df'], 1 ); ?>> Nie ładuj skryptów Doofindera tam, gdzie działa nasza wyszukiwarka (w trybie testu – tylko u administratorów)</label></td></tr>
				<tr><th>Mnożniki</th><td class="fx-srch-w">
					<?php
					$wl = array(
						'w_ship'  => 'Wysyłka dziś',
						'w_store' => 'W sklepie stacjonarnym',
						'w_sale'  => 'Promocja',
						'w_new'   => 'Nowość (do 60 dni)',
						'w_pre'   => 'Przedsprzedaż',
						'w_pop'   => 'Siła popularności (sprzedaż + kliknięcia)',
					);
					foreach ( $wl as $k => $label ) {
						echo '<label>' . esc_html( $label ) . ' <input type="number" step="0.05" min="0" max="10" name="fx[' . esc_attr( $k ) . ']" value="' . esc_attr( $s[ $k ] ) . '"></label>';
					}
					?>
					<p class="description">1 = bez wpływu, 1,5 = o połowę wyżej, 0,8 = trochę niżej. Mnożniki zmieniają kolejność tylko wśród produktów pasujących do frazy. Ręczny mnożnik produktu: edycja produktu → Ogólne → „Mnożnik w wyszukiwarce”.</p>
				</td></tr>
				<tr><th>Przypięte produkty</th><td><textarea name="fx[pins]" rows="5" class="large-text code" placeholder="kask | 12345, 23456&#10;gogle | 34567"><?php echo esc_textarea( $s['pins'] ); ?></textarea>
					<p class="description">Jedna linia: fraza | ID produktów po przecinku. Te produkty są zawsze na górze dla tej frazy (w podanej kolejności). ID widać na liście produktów po najechaniu na nazwę.</p></td></tr>
				<tr><th>Mnożnik kategorii</th><td><textarea name="fx[cat_boost]" rows="4" class="large-text code" placeholder="kaski-mtb | 1.3&#10;outlet | 0.7"><?php echo esc_textarea( $s['cat_boost'] ); ?></textarea>
					<p class="description">Jedna linia: slug kategorii | mnożnik. Obejmuje podkategorie.</p></td></tr>
				<tr><th>Synonimy</th><td><textarea name="fx[syn]" rows="10" class="large-text code"><?php echo esc_textarea( $s['syn'] ); ?></textarea>
					<p class="description">Jedna linia = słowa, które znaczą to samo. Polskie znaki i odmiana („kaski”, „rowerowe”) są obsługiwane same.</p></td></tr>
				<tr><th>Podpowiedzi przy pustym polu</th><td><input type="text" class="large-text" name="fx[popular]" value="<?php echo esc_attr( $s['popular'] ); ?>">
					<p class="description">Po przecinku. Gdy zbiorą się statystyki, na początku listy pojawią się najczęściej wyszukiwane frazy.</p></td></tr>
				<tr><th>Produktów w podpowiedziach</th><td><input type="number" min="4" max="12" name="fx[max]" value="<?php echo esc_attr( $s['max'] ); ?>" style="width:70px"></td></tr>
			</table>
			<?php submit_button( 'Zapisz i przelicz' ); ?>
		</form>

		<div class="fx-srch-cards">
			<div class="fx-srch-card">
				<h2>Najczęściej wyszukiwane (30 dni)</h2>
				<table class="widefat striped"><thead><tr><th>Fraza</th><th>Wyszukań</th><th>Śr. wyników</th><th>Kliknięć</th></tr></thead><tbody>
				<?php foreach ( (array) $top as $r ) : ?>
					<tr><td><a href="<?php echo esc_url( add_query_arg( array( 's' => $r->q, 'post_type' => 'product' ), home_url( '/' ) ) ); ?>" target="_blank"><?php echo esc_html( $r->q ); ?></a></td><td><?php echo (int) $r->s; ?></td><td><?php echo esc_html( null === $r->n ? '—' : (int) $r->n ); ?></td><td><?php echo (int) $r->c; ?></td></tr>
				<?php endforeach; ?>
				<?php if ( ! $top ) : ?><tr><td colspan="4">Brak danych – statystyki zbierają się od włączenia wyszukiwarki.</td></tr><?php endif; ?>
				</tbody></table>
			</div>
			<div class="fx-srch-card">
				<h2>Bez wyników (30 dni)</h2>
				<table class="widefat striped"><thead><tr><th>Fraza</th><th>Ile razy</th></tr></thead><tbody>
				<?php foreach ( (array) $zero as $r ) : ?>
					<tr><td><?php echo esc_html( $r->q ); ?></td><td><?php echo (int) $r->s; ?></td></tr>
				<?php endforeach; ?>
				<?php if ( ! $zero ) : ?><tr><td colspan="2">Brak.</td></tr><?php endif; ?>
				</tbody></table>
				<p class="description">Wskazówka: dopisz brakujące słowo do synonimów (np. „ochraniacz kolan” → „nakolanniki”) albo uzupełnij ofertę.</p>
			</div>
			<div class="fx-srch-card">
				<h2>Najczęściej klikane produkty</h2>
				<table class="widefat striped"><thead><tr><th>Produkt</th><th>Kliknięć</th></tr></thead><tbody>
				<?php foreach ( (array) $clk as $r ) : ?>
					<tr><td><a href="<?php echo esc_url( get_edit_post_link( $r->pid ) ); ?>"><?php echo esc_html( get_the_title( $r->pid ) ); ?></a> <small>#<?php echo (int) $r->pid; ?></small></td><td><?php echo (int) $r->c; ?></td></tr>
				<?php endforeach; ?>
				<?php if ( ! $clk ) : ?><tr><td colspan="2">Brak.</td></tr><?php endif; ?>
				</tbody></table>
			</div>
		</div>
	</div>
	<style>
		.fx-srch-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px;margin:16px 0}
		.fx-srch-card{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:4px 16px 16px}
		.fx-srch-w label{display:inline-flex;align-items:center;gap:6px;margin:0 18px 8px 0}
		.fx-srch-w input{width:72px}
	</style>
	<script>
	(function(){var b=document.querySelector('[data-fx-build]'),st=document.querySelector('[data-fx-status]'),nonce=<?php echo wp_json_encode( wp_create_nonce( 'fx_srch_build' ) ); ?>,first=true;
	if(!b)return;
	function step(){var f=new FormData();f.append('action','fx_srch_build');f.append('_ajax_nonce',nonce);if(first){f.append('seed','1');first=false}
		fetch(ajaxurl,{method:'POST',body:f,credentials:'same-origin'}).then(function(r){return r.json()}).then(function(r){
			if(!r||!r.success){st.textContent='Błąd – spróbuj ponownie.';b.disabled=false;return}
			document.querySelector('[data-fx-left]').textContent=r.data.left;
			if(r.data.left>0){st.textContent='Liczę… zostało '+r.data.left+' z '+r.data.total;step()}
			else{st.textContent='Gotowe – w wyszukiwarce: '+(r.data.file&&r.data.file.n||0)+' produktów.';document.querySelector('[data-fx-total]').textContent=(r.data.file&&r.data.file.n)||0;b.disabled=false}
		}).catch(function(){st.textContent='Przerwa w połączeniu – ponawiam…';setTimeout(step,3000)})}
	b.addEventListener('click',function(e){e.preventDefault();b.disabled=true;st.textContent='Start…';first=true;step()});
	})();
	</script>
	<?php
}
