<?php
/**
 * Parametry z importera Powerbike → atrybuty produktów i filtry (bez BaseLinkera).
 *
 * Źródło: importer na airoh.sklep.pl (/wp-json/pbi/v1/products, nagłówek X-PBI-Key) – ten sam adres i klucz,
 * co „Przeceny Powerbike” / „Przedsprzedaż”. Pozycje importera (warianty) są dopasowane do sklepu po SKU,
 * a gdy SKU nie pasuje – po EAN; parametry wszystkich wariantów trafiają do produktu głównego.
 *
 * Zapis:
 *  - wybrane parametry (WooCommerce → Parametry z importera) stają się globalnymi atrybutami (pa_…),
 *    przypisanymi do produktu jako zwykłe atrybuty (nie wariantowe), opcjonalnie widoczne w „Dodatkowych informacjach”,
 *  - bez zapisu całego produktu (bez haków importu, BaseLinkera, opisów AI) – tylko terminy i lista atrybutów,
 *  - ręczne zmiany wygrywają: atrybut ustawiony ręcznie (albo zmieniony po synchronizacji) nie jest ruszany,
 *  - atrybuty wariantowe (np. rozmiar) nigdy nie są nadpisywane.
 * Filtry: parametr z zaznaczonym „Filtr” pokazuje się w kategorii, gdy ma go co najmniej X% produktów
 * (domyślnie 40%) i są co najmniej dwie wartości. Parametry „Tak/Nie” (np. Wiatroszczelna) – jako przełącznik.
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

/* =====================================================================
 * Ustawienia
 * ===================================================================== */

function fxp_defaults() {
	$pbs = get_option( 'pbs_settings', array() );
	$po  = get_option( 'fxpo_settings', array() );
	$url = '';
	$key = '';
	foreach ( array( $po, $pbs ) as $src ) {
		if ( is_array( $src ) ) {
			$url = $url ? $url : (string) ( $src['source_url'] ?? '' );
			$key = $key ? $key : (string) ( $src['key'] ?? '' );
		}
	}
	return array(
		'on'        => 0,
		'source_url' => $url ? $url : 'https://www.airoh.sklep.pl/wp-json/pbi/v1/products',
		'key'       => $key,
		'producers' => 'FOX',
		'time'      => '03:40',
		'min_cov'   => 40,
		'ean_metas' => '_woosea_ean, _global_unique_id, _alg_ean, _ean',
		'replace'   => "Cross / Enduro = Cross/Enduro\nOffroad = Off-road\nMęski = Męskie\nDamski = Damskie\nDziecięcy = Dziecięce\nTekstylia = Tekstylne",
		'map'       => array(),
	);
}

function fxp_settings() {
	static $s = null;
	if ( null === $s ) {
		$saved = get_option( 'fxp_settings', array() );
		$s     = array_merge( fxp_defaults(), is_array( $saved ) ? array_filter( $saved, function ( $v ) { return '' !== $v && null !== $v; } ) : array() );
		$s['map'] = array_merge( fxp_default_map(), is_array( $s['map'] ) ? $s['map'] : array() );
	}
	return $s;
}

/**
 * Domyślne przypisanie parametrów importera (klucz = nazwa parametru bez „Attributes.”, małymi literami).
 * label = nazwa w sklepie, use = zapisywać, filter = filtr w kategoriach, show = widoczny na karcie produktu.
 */
function fxp_default_map() {
	$m = array(
		'rodzaj'             => array( 'Rodzaj', 1, 0, 0 ), // czyta go filtr „Dla kogo”
		'przeznaczenie'      => array( 'Przeznaczenie', 1, 1, 1 ),
		'rodzaj lakieru'     => array( 'Wykończenie', 1, 1, 1 ),
		'malowanie'          => array( 'Wzór', 1, 1, 1 ),
		'rodzaj szybki'      => array( 'Szybka', 1, 1, 1 ),
		'typ'                => array( 'Typ', 1, 1, 1 ),
		'sezon'              => array( 'Sezon', 1, 1, 1 ),
		'materiał'           => array( 'Materiał', 1, 1, 1 ),
		'wiatroszczelna'     => array( 'Wiatroszczelne', 1, 1, 1 ),
		'wodoodporna'        => array( 'Wodoodporne', 1, 1, 1 ),
		'wodoodporność'      => array( 'Wodoodporne', 1, 1, 1 ),
		'oddychająca'        => array( 'Oddychające', 1, 1, 1 ),
		'homologacja'        => array( 'Homologacja', 1, 1, 1 ),
		'rodzaj zapięcia'    => array( 'Zapięcie', 1, 0, 1 ),
		'obwód głowy'        => array( 'Obwód głowy', 1, 0, 1 ),
		'możliwość regulacji rozmiaru' => array( 'Regulacja rozmiaru', 1, 0, 1 ),
		// Pomijane: dane techniczne, sklepowe albo już obsłużone inaczej.
		'ean'                => array( 'EAN', 0, 0, 0 ),
		'nr katalogowy producenta' => array( 'Nr katalogowy', 0, 0, 0 ),
		'stan'               => array( 'Stan', 0, 0, 0 ),
		'marka'              => array( 'Marka', 0, 0, 0 ),
		'producent'          => array( 'Producent', 0, 0, 0 ),
		'rozmiar'            => array( 'Rozmiar', 0, 0, 0 ),
		'kolor'              => array( 'Kolor', 0, 0, 0 ),
	);
	$out = array();
	foreach ( $m as $k => $v ) {
		$out[ $k ] = array( 'label' => $v[0], 'use' => $v[1], 'filter' => $v[2], 'show' => $v[3] );
	}
	return apply_filters( 'fxp_default_map', $out );
}

/** Ustawienia jednego parametru (nieznany parametr = nie zapisujemy, dopóki nie zaznaczysz). */
function fxp_map_for( $name ) {
	$k = fxp_name_key( $name );
	$m = fxp_settings()['map'];
	if ( isset( $m[ $k ] ) ) {
		return array_merge( array( 'label' => $name, 'use' => 0, 'filter' => 0, 'show' => 0 ), $m[ $k ] );
	}
	return array( 'label' => fxp_pretty( $name ), 'use' => 0, 'filter' => 0, 'show' => 0 );
}

function fxp_name_key( $name ) {
	$name = preg_replace( '/^attributes\./iu', '', trim( (string) $name ) );
	return mb_strtolower( preg_replace( '/\s+/u', ' ', $name ), 'UTF-8' );
}

function fxp_pretty( $name ) {
	$name = preg_replace( '/^attributes\./iu', '', trim( (string) $name ) );
	return mb_strtoupper( mb_substr( $name, 0, 1, 'UTF-8' ), 'UTF-8' ) . mb_substr( $name, 1, null, 'UTF-8' );
}

/* =====================================================================
 * Dane z importera
 * ===================================================================== */

/**
 * Parametry pozycji importera: [ nazwa => [ wartości ] ].
 * Obsługuje kilka zapisów: attributes / attrs / params / features (obiekt albo lista {name, value}),
 * raw.Attributes oraz płaskie klucze „Attributes.Kolor”.
 */
function fxp_item_attrs( $it ) {
	$src = null;
	foreach ( array( 'attributes', 'Attributes', 'attrs', 'params', 'parameters', 'features' ) as $k ) {
		if ( ! empty( $it[ $k ] ) && is_array( $it[ $k ] ) ) {
			$src = $it[ $k ];
			break;
		}
	}
	if ( null === $src && ! empty( $it['raw']['Attributes'] ) && is_array( $it['raw']['Attributes'] ) ) {
		$src = $it['raw']['Attributes'];
	}
	$out = array();
	if ( null === $src ) {
		foreach ( (array) $it as $k => $v ) {
			if ( is_string( $k ) && 0 === stripos( $k, 'Attributes.' ) && is_scalar( $v ) ) {
				$out[ substr( $k, 11 ) ] = fxp_split( $v );
			}
		}
		return array_filter( $out );
	}
	foreach ( $src as $k => $v ) {
		if ( is_array( $v ) && isset( $v['name'] ) ) { // lista {name, value}
			$name = (string) $v['name'];
			$val  = $v['value'] ?? ( $v['values'] ?? '' );
		} else {
			$name = (string) $k;
			$val  = $v;
		}
		if ( '' === $name || is_numeric( $name ) ) {
			continue;
		}
		$vals = array();
		foreach ( (array) $val as $one ) {
			if ( is_scalar( $one ) ) {
				$vals = array_merge( $vals, fxp_split( $one ) );
			}
		}
		if ( $vals ) {
			$out[ $name ] = isset( $out[ $name ] ) ? array_values( array_unique( array_merge( $out[ $name ], $vals ) ) ) : $vals;
		}
	}
	return $out;
}

/** „Czarny|Szary” → [Czarny, Szary]; puste, „-”, „brak” pomijamy. */
function fxp_split( $v ) {
	$out = array();
	foreach ( preg_split( '/\s*\|\s*/u', trim( (string) $v ) ) as $x ) {
		$x = fxp_norm_val( $x );
		if ( '' !== $x ) {
			$out[] = $x;
		}
	}
	return array_values( array_unique( $out ) );
}

/** Ujednolicenie wartości: spacje, ukośniki, wielka litera, zamiany z ustawień. */
function fxp_norm_val( $v ) {
	$v = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $v ) ) );
	if ( '' === $v || preg_match( '/^(-+|brak|nie dotyczy|n\/a|null|0)$/iu', $v ) ) {
		return '';
	}
	static $rep = null;
	if ( null === $rep ) {
		$rep = array();
		foreach ( preg_split( '/\r?\n/', (string) fxp_settings()['replace'] ) as $line ) {
			$p = explode( '=', $line, 2 );
			if ( 2 === count( $p ) && '' !== trim( $p[0] ) ) {
				$rep[ mb_strtolower( trim( $p[0] ), 'UTF-8' ) ] = trim( $p[1] );
			}
		}
	}
	$low = mb_strtolower( $v, 'UTF-8' );
	if ( isset( $rep[ $low ] ) ) {
		return $rep[ $low ];
	}
	$v = preg_replace( '/\s*\/\s*/u', '/', $v ); // „Cross / Enduro” = „Cross/Enduro”
	if ( isset( $rep[ mb_strtolower( $v, 'UTF-8' ) ] ) ) {
		return $rep[ mb_strtolower( $v, 'UTF-8' ) ];
	}
	return mb_strtoupper( mb_substr( $v, 0, 1, 'UTF-8' ), 'UTF-8' ) . mb_substr( $v, 1, null, 'UTF-8' );
}

/** Pozycje z importera (wszystkie strony). @throws Exception */
function fxp_fetch( $max_pages = 100, $per_page = 2000, $args = array() ) {
	$s = fxp_settings();
	if ( '' === trim( $s['source_url'] ) || '' === trim( $s['key'] ) ) {
		throw new Exception( 'Brak adresu importera lub klucza.' );
	}
	$out  = array();
	$page = 1;
	do {
		$url = add_query_arg( array_merge( array( 'producer' => $s['producers'], 'page' => $page, 'per_page' => $per_page, 'attributes' => 1, '_' => time() ), $args ), trim( $s['source_url'] ) );
		$r   = wp_remote_get( $url, array( 'timeout' => 90, 'headers' => array( 'X-PBI-Key' => trim( $s['key'] ), 'Cache-Control' => 'no-cache' ) ) );
		if ( is_wp_error( $r ) ) {
			throw new Exception( 'Importer: ' . $r->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $r );
		$data = json_decode( wp_remote_retrieve_body( $r ), true );
		if ( 200 !== $code || ! is_array( $data ) || ! isset( $data['items'] ) ) {
			throw new Exception( 'Importer: HTTP ' . $code . ' – ' . wp_trim_words( wp_strip_all_tags( wp_remote_retrieve_body( $r ) ), 20 ) );
		}
		$out = array_merge( $out, (array) $data['items'] );
		$page++;
	} while ( count( $data['items'] ) >= (int) ( $data['per_page'] ?? $per_page ) && $page <= $max_pages );
	return $out;
}

/** SKU pozycji (lista „skus” albo pojedyncze pola). */
function fxp_item_skus( $it ) {
	$skus = array();
	foreach ( (array) ( $it['skus'] ?? array() ) as $x ) {
		$skus[] = $x;
	}
	foreach ( array( 'sku', 'SKU', 'symbol', 'code' ) as $k ) {
		if ( ! empty( $it[ $k ] ) && is_scalar( $it[ $k ] ) ) {
			$skus[] = $it[ $k ];
		}
	}
	return array_values( array_unique( array_filter( array_map( 'trim', array_map( 'strval', $skus ) ), 'strlen' ) ) );
}

function fxp_item_ean( $it ) {
	foreach ( array( 'ean', 'EAN', 'gtin' ) as $k ) {
		if ( ! empty( $it[ $k ] ) && is_scalar( $it[ $k ] ) ) {
			$e = preg_replace( '/\D/', '', (string) $it[ $k ] );
			if ( strlen( $e ) >= 8 ) {
				return $e;
			}
		}
	}
	$a = fxp_item_attrs( $it );
	foreach ( $a as $name => $vals ) {
		if ( 'ean' === fxp_name_key( $name ) && $vals ) {
			$e = preg_replace( '/\D/', '', (string) $vals[0] );
			return strlen( $e ) >= 8 ? $e : '';
		}
	}
	return '';
}

/**
 * Dopasowanie pozycji do produktów sklepu: SKU (wariant albo produkt), potem EAN.
 * Zwraca [ id_produktu_głównego => [ [ pozycja, 'SKU …' | 'EAN …' ], … ] ].
 */
function fxp_resolve( array $items, &$unmatched = array(), $to_parent = true ) {
	global $wpdb;
	$skus = array();
	$eans = array();
	foreach ( $items as $it ) {
		foreach ( fxp_item_skus( $it ) as $s ) {
			$skus[ mb_strtoupper( $s ) ] = $s;
		}
		$e = fxp_item_ean( $it );
		if ( $e ) {
			$eans[ $e ] = true;
		}
	}
	$by_sku = array();
	foreach ( array_chunk( array_values( $skus ), 500 ) as $chunk ) {
		$in = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT pm.post_id id, pm.meta_value sku FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type IN ('product','product_variation') AND p.post_status IN ('publish','private','draft') WHERE pm.meta_key = '_sku' AND pm.meta_value IN ($in)", $chunk ) ) as $r ) { // phpcs:ignore
			$by_sku[ mb_strtoupper( $r->sku ) ] = (int) $r->id;
		}
	}
	$by_ean = array();
	$metas  = array_filter( array_map( 'trim', explode( ',', (string) fxp_settings()['ean_metas'] ) ) );
	if ( $metas && $eans ) {
		$mk = implode( ',', array_fill( 0, count( $metas ), '%s' ) );
		foreach ( array_chunk( array_keys( $eans ), 500 ) as $chunk ) {
			$chunk = array_map( 'strval', $chunk );
			$in    = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT pm.post_id id, pm.meta_value ean FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type IN ('product','product_variation') AND p.post_status IN ('publish','private','draft') WHERE pm.meta_key IN ($mk) AND pm.meta_value IN ($in)", array_merge( $metas, $chunk ) ) ) as $r ) { // phpcs:ignore
				$by_ean[ (string) $r->ean ] = (int) $r->id;
			}
		}
	}
	$parent = array();
	$out    = array();
	$unmatched = array();
	foreach ( $items as $it ) {
		$id  = 0;
		$via = '';
		foreach ( fxp_item_skus( $it ) as $s ) {
			if ( isset( $by_sku[ mb_strtoupper( $s ) ] ) ) {
				$id  = $by_sku[ mb_strtoupper( $s ) ];
				$via = 'SKU ' . $s;
				break;
			}
		}
		if ( ! $id ) {
			$e = fxp_item_ean( $it );
			if ( $e && isset( $by_ean[ $e ] ) ) {
				$id  = $by_ean[ $e ];
				$via = 'EAN ' . $e;
			}
		}
		if ( ! $id ) {
			if ( count( $unmatched ) < 200 ) {
				$unmatched[] = trim( implode( ', ', fxp_item_skus( $it ) ) . ' ' . fxp_item_ean( $it ) );
			} else {
				$unmatched[] = '';
			}
			continue;
		}
		if ( ! $to_parent ) {
			$out[ $id ][] = array( $it, $via ); // wariant / produkt prosty
			continue;
		}
		if ( ! isset( $parent[ $id ] ) ) {
			$pp            = (int) wp_get_post_parent_id( $id );
			$parent[ $id ] = $pp && 'product_variation' === get_post_type( $id ) ? $pp : $id;
		}
		$out[ $parent[ $id ] ][] = array( $it, $via );
	}
	return $out;
}

/* =====================================================================
 * Atrybuty WooCommerce
 * ===================================================================== */

/** Taksonomia atrybutu dla parametru (tworzy globalny atrybut przy pierwszym użyciu). */
function fxp_tax_for( $name, $create = true ) {
	$cfg  = fxp_map_for( $name );
	$slug = wc_sanitize_taxonomy_name( remove_accents( $cfg['label'] ) );
	$slug = substr( $slug, 0, 27 ); // limit WooCommerce (28 znaków)
	if ( '' === $slug ) {
		return '';
	}
	$tax = wc_attribute_taxonomy_name( $slug );
	if ( taxonomy_exists( $tax ) ) {
		return $tax;
	}
	if ( ! $create ) {
		return '';
	}
	if ( ! wc_attribute_taxonomy_id_by_name( $slug ) ) {
		$id = wc_create_attribute( array( 'name' => $cfg['label'], 'slug' => $slug, 'type' => 'select', 'order_by' => 'name', 'has_archives' => false ) );
		if ( is_wp_error( $id ) ) {
			return '';
		}
	}
	register_taxonomy( $tax, array( 'product' ), array( 'hierarchical' => false, 'show_ui' => false, 'query_var' => false, 'rewrite' => false, 'labels' => array( 'name' => $cfg['label'] ) ) );
	return $tax;
}

/** Wszystkie taksonomie zapisywane przez moduł: [ nazwa parametru => taksonomia ]. */
function fxp_taxes( $create = false ) {
	$out = array();
	foreach ( fxp_settings()['map'] as $k => $cfg ) {
		if ( ! empty( $cfg['use'] ) ) {
			$t = fxp_tax_for( $k, $create );
			if ( $t ) {
				$out[ $k ] = $t;
			}
		}
	}
	return $out;
}

/** ID terminu (tworzy, gdy brak). */
function fxp_term_id( $tax, $value ) {
	static $cache = array();
	$k = $tax . '|' . mb_strtolower( $value, 'UTF-8' );
	if ( isset( $cache[ $k ] ) ) {
		return $cache[ $k ];
	}
	$t = get_term_by( 'name', $value, $tax );
	if ( ! $t ) {
		$t = get_term_by( 'slug', sanitize_title( $value ), $tax );
	}
	if ( $t ) {
		return $cache[ $k ] = (int) $t->term_id;
	}
	$r = wp_insert_term( $value, $tax );
	if ( is_wp_error( $r ) ) {
		$id = (int) $r->get_error_data( 'term_exists' );
		return $cache[ $k ] = $id;
	}
	return $cache[ $k ] = (int) $r['term_id'];
}

/**
 * Zapis parametrów w produkcie: $want = [ taksonomia => [ wartości ] ] (pusta lista = usunąć nasz atrybut).
 * Zwraca liczbę zmienionych atrybutów.
 */
function fxp_apply( $pid, array $want, array $shown ) {
	$own   = get_post_meta( $pid, '_fxp_own', true );
	$own   = is_array( $own ) ? $own : array();
	$attrs = get_post_meta( $pid, '_product_attributes', true );
	$attrs = is_array( $attrs ) ? $attrs : array();
	$changed = 0;
	foreach ( $want as $tax => $vals ) {
		if ( ! empty( $attrs[ $tax ]['is_variation'] ) ) {
			continue; // atrybut wariantowy – nie ruszamy
		}
		$cur = wp_get_object_terms( $pid, $tax, array( 'fields' => 'ids' ) );
		$cur = is_wp_error( $cur ) ? array() : array_map( 'intval', $cur );
		sort( $cur );
		$sig = md5( implode( ',', $cur ) );
		if ( $cur && ( ! isset( $own[ $tax ] ) || $own[ $tax ] !== $sig ) ) {
			continue; // ustawione ręcznie albo poprawione po synchronizacji
		}
		$ids = array();
		foreach ( $vals as $v ) {
			$id = fxp_term_id( $tax, $v );
			if ( $id ) {
				$ids[] = $id;
			}
		}
		$ids = array_values( array_unique( $ids ) );
		sort( $ids );
		if ( $ids === $cur && ( ! $ids || isset( $attrs[ $tax ] ) ) ) {
			if ( $ids && (int) ! empty( $attrs[ $tax ]['is_visible'] ) !== (int) ! empty( $shown[ $tax ] ) ) {
				$attrs[ $tax ]['is_visible'] = empty( $shown[ $tax ] ) ? 0 : 1;
				$changed++;
			}
			continue;
		}
		wp_set_object_terms( $pid, $ids, $tax, false );
		if ( $ids ) {
			$attrs[ $tax ] = array(
				'name'         => $tax,
				'value'        => '',
				'position'     => isset( $attrs[ $tax ]['position'] ) ? (int) $attrs[ $tax ]['position'] : count( $attrs ),
				'is_visible'   => empty( $shown[ $tax ] ) ? 0 : 1,
				'is_variation' => 0,
				'is_taxonomy'  => 1,
			);
			$own[ $tax ] = md5( implode( ',', $ids ) );
		} else {
			unset( $attrs[ $tax ], $own[ $tax ] );
		}
		$changed++;
	}
	if ( $changed ) {
		update_post_meta( $pid, '_product_attributes', $attrs );
		update_post_meta( $pid, '_fxp_own', $own );
		update_post_meta( $pid, '_fxp_at', time() );
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $pid );
		}
		if ( function_exists( 'fx_dla_assign' ) ) {
			fx_dla_assign( $pid ); // „Rodzaj” z importera zmienia filtr „Dla kogo”
		}
	}
	return $changed;
}

/* =====================================================================
 * Synchronizacja: pobranie → kolejka → zapis partiami w tle
 * ===================================================================== */

/** Etap 1: pobranie z importera, statystyki parametrów, kolejka produktów do zapisu. */
function fxp_sync_start( $why = 'cron' ) {
	if ( get_transient( 'fxp_lock' ) ) {
		return 'Synchronizacja już trwa.';
	}
	set_transient( 'fxp_lock', 1, 15 * MINUTE_IN_SECONDS );
	@set_time_limit( 600 ); // phpcs:ignore
	try {
		$items = fxp_fetch();
	} catch ( Exception $e ) {
		delete_transient( 'fxp_lock' );
		fxp_log( 'Błąd: ' . $e->getMessage() );
		return $e->getMessage();
	}
	if ( ! $items ) {
		delete_transient( 'fxp_lock' );
		fxp_log( 'Importer zwrócił pustą listę – nic nie zmieniam.' );
		return 'Importer zwrócił pustą listę.';
	}
	// Statystyki parametrów (do tabeli przypisań w panelu).
	$seen = array();
	$with = 0;
	foreach ( $items as $it ) {
		$a = fxp_item_attrs( $it );
		if ( $a ) {
			$with++;
		}
		foreach ( $a as $name => $vals ) {
			$k = fxp_name_key( $name );
			if ( ! isset( $seen[ $k ] ) ) {
				$seen[ $k ] = array( 'name' => fxp_pretty( $name ), 'n' => 0, 'vals' => array() );
			}
			$seen[ $k ]['n']++;
			foreach ( $vals as $v ) {
				if ( count( $seen[ $k ]['vals'] ) < 40 || isset( $seen[ $k ]['vals'][ $v ] ) ) {
					$seen[ $k ]['vals'][ $v ] = ( $seen[ $k ]['vals'][ $v ] ?? 0 ) + 1;
				}
			}
		}
	}
	update_option( 'fxp_seen', array( 'at' => time(), 'items' => count( $items ), 'with' => $with, 'params' => $seen ), false );
	if ( ! $with ) {
		delete_transient( 'fxp_lock' );
		fxp_log( 'Importer nie wysyła parametrów (' . count( $items ) . ' pozycji bez „attributes”). Potrzebna aktualizacja importera.' );
		return 'Importer nie wysyła parametrów.';
	}
	// Produkty i parametry do zapisu.
	$unmatched = array();
	$found     = fxp_resolve( $items, $unmatched );
	$taxes     = fxp_taxes( true );
	$queue     = array();
	foreach ( $found as $pid => $rows ) {
		$want = array_fill_keys( array_values( $taxes ), array() );
		foreach ( $rows as $row ) {
			foreach ( fxp_item_attrs( $row[0] ) as $name => $vals ) {
				$k = fxp_name_key( $name );
				if ( isset( $taxes[ $k ] ) ) {
					$want[ $taxes[ $k ] ] = array_values( array_unique( array_merge( $want[ $taxes[ $k ] ], $vals ) ) );
				}
			}
		}
		$queue[ $pid ] = $want;
	}
	update_option( 'fxp_queue', $queue, false );
	update_option( 'fxp_state', array( 'at' => time(), 'why' => $why, 'items' => count( $items ), 'matched' => count( $found ), 'unmatched' => count( $unmatched ), 'unmatched_list' => array_slice( array_filter( $unmatched ), 0, 100 ), 'total' => count( $queue ), 'done' => 0, 'changed' => 0, 'finished' => 0 ), false );
	fxp_log( sprintf( 'Pobrano %d pozycji, dopasowano %d produktów, bez dopasowania %d.', count( $items ), count( $found ), count( $unmatched ) ) );
	delete_transient( 'fxp_lock' );
	wp_clear_scheduled_hook( 'fxp_tick' );
	wp_schedule_single_event( time() + 5, 'fxp_tick' );
	return '';
}

/** Etap 2: zapis partiami (do 25 s na przebieg, potem kolejny za pół minuty). */
add_action( 'fxp_tick', 'fxp_tick' );
function fxp_tick() {
	$queue = get_option( 'fxp_queue', array() );
	$state = get_option( 'fxp_state', array() );
	if ( ! is_array( $queue ) || ! $queue ) {
		return;
	}
	$shown = array();
	foreach ( fxp_settings()['map'] as $k => $cfg ) {
		if ( ! empty( $cfg['use'] ) && ! empty( $cfg['show'] ) ) {
			$t = fxp_tax_for( $k, false );
			if ( $t ) {
				$shown[ $t ] = true;
			}
		}
	}
	$start = microtime( true );
	wp_defer_term_counting( true );
	foreach ( $queue as $pid => $want ) {
		$state['changed'] = (int) ( $state['changed'] ?? 0 ) + ( fxp_apply( (int) $pid, (array) $want, $shown ) ? 1 : 0 );
		$state['done']    = (int) ( $state['done'] ?? 0 ) + 1;
		unset( $queue[ $pid ] );
		if ( microtime( true ) - $start > 25 ) {
			break;
		}
	}
	wp_defer_term_counting( false );
	update_option( 'fxp_queue', $queue, false );
	if ( $queue ) {
		update_option( 'fxp_state', $state, false );
		wp_schedule_single_event( time() + 30, 'fxp_tick' );
		return;
	}
	$state['finished'] = time();
	update_option( 'fxp_state', $state, false );
	fxp_log( sprintf( 'Zapisano: zmienione produkty %d z %d.', (int) $state['changed'], (int) $state['done'] ) );
	if ( function_exists( 'fx_filters_flush' ) ) {
		fx_filters_flush();
	}
	if ( function_exists( 'fx_cache_touch' ) ) {
		fx_cache_touch();
	}
}

/* Codziennie o ustawionej godzinie (sprawdzane co godzinę). */
add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'fxp_hourly' ) ) {
		wp_schedule_event( time() + 300, 'hourly', 'fxp_hourly' );
	}
} );
add_action( 'fxp_hourly', function () {
	$s = fxp_settings();
	if ( empty( $s['on'] ) ) {
		return;
	}
	$tz   = wp_timezone();
	$now  = new DateTime( 'now', $tz );
	$at   = DateTime::createFromFormat( 'Y-m-d H:i', $now->format( 'Y-m-d' ) . ' ' . ( preg_match( '/^\d{1,2}:\d{2}$/', $s['time'] ) ? $s['time'] : '03:40' ), $tz );
	$last = (int) ( get_option( 'fxp_state', array() )['at'] ?? 0 );
	if ( $at && $now >= $at && $last < $at->getTimestamp() ) {
		fxp_sync_start( 'cron' );
	}
} );

function fxp_log( $msg ) {
	$log = get_option( 'fxp_log', array() );
	$log = is_array( $log ) ? $log : array();
	array_unshift( $log, array( time(), $msg ) );
	update_option( 'fxp_log', array_slice( $log, 0, 50 ), false );
}

/* =====================================================================
 * Filtry w kategoriach
 * ===================================================================== */

/** Parametr „Tak/Nie” (z ostatnich statystyk) → przełącznik zamiast listy. */
function fxp_is_bool( $key ) {
	$seen = get_option( 'fxp_seen', array() );
	$vals = array_keys( (array) ( $seen['params'][ $key ]['vals'] ?? array() ) );
	if ( ! $vals ) {
		return false;
	}
	foreach ( $vals as $v ) {
		if ( ! in_array( mb_strtolower( $v, 'UTF-8' ), array( 'tak', 'nie' ), true ) ) {
			return false;
		}
	}
	return true;
}

function fxp_filter_list() {
	static $out = null;
	if ( null !== $out ) {
		return $out;
	}
	$out      = array( 'groups' => array(), 'switches' => array() );
	$reserved = array( 'dla', 'rozmiar', 'kolor', 'promocja', 'kampania', 'wysylka24', 'wsklepie', 'przedsprzedaz', 'orderby', 's', 'paged', 'page', 'post_type', 'p', 'product_cat' );
	foreach ( fxp_settings()['map'] as $k => $cfg ) {
		if ( empty( $cfg['use'] ) || empty( $cfg['filter'] ) ) {
			continue;
		}
		$tax = fxp_tax_for( $k, false );
		if ( ! $tax ) {
			continue;
		}
		$param = sanitize_title( remove_accents( $cfg['label'] ) );
		if ( in_array( $param, $reserved, true ) ) {
			$param .= '-p';
		}
		$row = array( 'tax' => $tax, 'label' => $cfg['label'], 'param' => $param );
		if ( fxp_is_bool( $k ) ) {
			$out['switches'][ $param ] = $row;
		} else {
			$out['groups'][ 'x_' . $param ] = $row;
		}
	}
	return $out;
}

add_filter( 'fx_filter_extra', function ( $x ) {
	return array_merge( $x, fxp_filter_list()['groups'] );
} );

/* Atrybuty z importera nie trafiają do automatycznych filtrów rozmiaru / koloru („…regulacji rozmiaru”). */
add_filter( 'fx_filter_not_size_color', function ( $t ) {
	return array_merge( $t, array_values( fxp_taxes( false ) ) );
} );

add_filter( 'fx_filter_extra_min_coverage', function () {
	return max( 0, min( 100, (int) fxp_settings()['min_cov'] ) ) / 100;
} );

add_filter( 'fx_filter_url_params', function ( $p ) {
	return array_merge( $p, array_keys( fxp_filter_list()['switches'] ) );
} );

/** Produkty z wartością „Tak”. */
function fxp_yes_ids( $tax ) {
	$t = get_term_by( 'slug', 'tak', $tax );
	if ( ! $t ) {
		return array();
	}
	$o = get_objects_in_term( (int) $t->term_id, $tax );
	return is_wp_error( $o ) ? array() : array_map( 'intval', $o );
}

add_filter( 'fx_filter_switches', function ( $sw, $term ) {
	$cat = null;
	foreach ( fxp_filter_list()['switches'] as $param => $x ) {
		$on = ! empty( $_GET[ $param ] ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $on ) {
			if ( null === $cat ) {
				$cat = array_flip( fx_filter_cat_products( $term ) );
			}
			if ( ! array_intersect_key( array_flip( fxp_yes_ids( $x['tax'] ) ), $cat ) ) {
				continue;
			}
		}
		$base = remove_query_arg( array( $param, 'paged' ), fx_filter_base_url() );
		$sw[] = array(
			'key'   => $param,
			'label' => $x['label'],
			'short' => $x['label'],
			'note'  => '',
			'on'    => $on,
			'url'   => $on ? $base : add_query_arg( $param, '1', $base ),
		);
	}
	return $sw;
}, 20, 2 );

add_filter( 'fx_filter_sets', function ( $sets ) {
	foreach ( fxp_filter_list()['switches'] as $param => $x ) {
		if ( ! empty( $_GET[ $param ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$sets[] = fxp_yes_ids( $x['tax'] );
		}
	}
	return $sets;
} );

/* =====================================================================
 * Panel: WooCommerce → Parametry z importera
 * ===================================================================== */

add_action( 'admin_menu', function () {
	add_submenu_page( 'woocommerce', 'Parametry z importera', 'Parametry z importera', 'manage_woocommerce', 'fxp', 'fxp_page' );
}, 60 );

add_action( 'admin_post_fxp', function () {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( 'Brak uprawnień.' );
	}
	check_admin_referer( 'fxp' );
	$do  = sanitize_key( $_POST['do'] ?? '' ); // phpcs:ignore
	$msg = '';
	if ( 'save' === $do ) {
		$in  = wp_unslash( $_POST ); // phpcs:ignore
		$raw = get_option( 'fxp_settings', array() );
		$raw = is_array( $raw ) ? $raw : array();
		$raw['on']         = empty( $in['on'] ) ? 0 : 1;
		$raw['source_url'] = esc_url_raw( trim( (string) ( $in['source_url'] ?? '' ) ) );
		if ( isset( $in['key'] ) && '' !== trim( (string) $in['key'] ) ) {
			$raw['key'] = sanitize_text_field( $in['key'] );
		}
		$raw['producers'] = sanitize_text_field( $in['producers'] ?? 'FOX' );
		$raw['time']      = preg_match( '/^\d{1,2}:\d{2}$/', (string) ( $in['time'] ?? '' ) ) ? $in['time'] : '03:40';
		$raw['min_cov']   = max( 0, min( 100, (int) ( $in['min_cov'] ?? 40 ) ) );
		$raw['replace']   = sanitize_textarea_field( $in['replace'] ?? '' );
		$raw['ean_metas'] = sanitize_text_field( $in['ean_metas'] ?? '' );
		$map = array();
		foreach ( (array) ( $in['map'] ?? array() ) as $k => $row ) {
			$k = fxp_name_key( base64_decode( (string) $k ) ); // phpcs:ignore
			if ( '' === $k ) {
				continue;
			}
			$map[ $k ] = array(
				'label'  => sanitize_text_field( $row['label'] ?? '' ) ?: fxp_pretty( $k ),
				'use'    => empty( $row['use'] ) ? 0 : 1,
				'filter' => empty( $row['filter'] ) ? 0 : 1,
				'show'   => empty( $row['show'] ) ? 0 : 1,
			);
		}
		$raw['map'] = $map;
		update_option( 'fxp_settings', $raw, false );
		$msg = 'Zapisano.';
	} elseif ( 'sync' === $do ) {
		$err = fxp_sync_start( 'ręcznie' );
		$msg = $err ? $err : 'Pobrano dane – zapis do produktów trwa w tle (odśwież stronę, żeby zobaczyć postęp).';
	} elseif ( 'test' === $do ) {
		try {
			$items = fxp_fetch( 1, 5 );
			$first = $items ? $items[0] : array();
			$attrs = $first ? fxp_item_attrs( $first ) : array();
			set_transient( 'fxp_test', array( 'keys' => array_keys( (array) $first ), 'attrs' => $attrs, 'skus' => $first ? fxp_item_skus( $first ) : array(), 'ean' => $first ? fxp_item_ean( $first ) : '', 'n' => count( $items ) ), HOUR_IN_SECONDS );
			$msg = $items ? ( $attrs ? 'Połączenie działa, importer wysyła parametry.' : 'Połączenie działa, ale importer NIE wysyła parametrów – potrzebna aktualizacja importera.' ) : 'Połączenie działa, ale importer nie zwrócił pozycji dla tej marki.';
		} catch ( Exception $e ) {
			$msg = $e->getMessage();
		}
	} elseif ( 'stop' === $do ) {
		update_option( 'fxp_queue', array(), false );
		wp_clear_scheduled_hook( 'fxp_tick' );
		$msg = 'Zatrzymano zapis.';
	}
	wp_safe_redirect( add_query_arg( array( 'page' => 'fxp', 'msg' => rawurlencode( $msg ) ), admin_url( 'admin.php' ) ) );
	exit;
} );

function fxp_page() {
	$s     = fxp_settings();
	$raw   = get_option( 'fxp_settings', array() );
	$state = get_option( 'fxp_state', array() );
	$seen  = get_option( 'fxp_seen', array() );
	$queue = get_option( 'fxp_queue', array() );
	$test  = get_transient( 'fxp_test' );
	$msg   = isset( $_GET['msg'] ) ? sanitize_text_field( wp_unslash( $_GET['msg'] ) ) : ''; // phpcs:ignore
	$fmt   = function ( $t ) {
		return $t ? wp_date( 'j.m.Y H:i', (int) $t ) : '–';
	};
	// Lista parametrów: z importera + z ustawień.
	$rows = array();
	foreach ( (array) ( $seen['params'] ?? array() ) as $k => $p ) {
		$rows[ $k ] = $p;
	}
	foreach ( $s['map'] as $k => $cfg ) {
		if ( ! isset( $rows[ $k ] ) && ! empty( $cfg['use'] ) ) {
			$rows[ $k ] = array( 'name' => fxp_pretty( $k ), 'n' => 0, 'vals' => array() );
		}
	}
	uasort( $rows, function ( $a, $b ) {
		return $b['n'] <=> $a['n'];
	} );
	$total = max( 1, (int) ( $seen['items'] ?? 0 ) );
	?>
	<div class="wrap">
		<h1>Parametry z importera</h1>
		<p>Parametry produktów z importera Powerbike (airoh.sklep.pl) trafiają do produktów sklepu jako atrybuty – dopasowanie po SKU, a gdy nie pasuje – po EAN. BaseLinker nie bierze w tym udziału. Atrybuty ustawione albo poprawione ręcznie nie są nadpisywane.</p>
		<?php if ( $msg ) : ?><div class="notice notice-info"><p><?php echo esc_html( $msg ); ?></p></div><?php endif; ?>

		<h2>Stan</h2>
		<table class="widefat striped" style="max-width:900px">
			<tr><th>Ostatnie pobranie</th><td><?php echo esc_html( $fmt( $state['at'] ?? 0 ) . ( ! empty( $state['why'] ) ? ' (' . $state['why'] . ')' : '' ) ); ?></td></tr>
			<tr><th>Pozycje z importera</th><td><?php echo (int) ( $state['items'] ?? 0 ); ?> (z parametrami: <?php echo (int) ( $seen['with'] ?? 0 ); ?>)</td></tr>
			<tr><th>Dopasowane produkty</th><td><?php echo (int) ( $state['matched'] ?? 0 ); ?> · bez dopasowania pozycji: <?php echo (int) ( $state['unmatched'] ?? 0 ); ?></td></tr>
			<tr><th>Zapis do produktów</th><td><?php echo $queue ? 'w toku: ' . (int) ( $state['done'] ?? 0 ) . ' / ' . (int) ( $state['total'] ?? 0 ) : 'zakończony ' . esc_html( $fmt( $state['finished'] ?? 0 ) ) . ' · zmienione: ' . (int) ( $state['changed'] ?? 0 ); ?></td></tr>
		</table>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:12px 0;display:flex;gap:8px">
			<?php wp_nonce_field( 'fxp' ); ?><input type="hidden" name="action" value="fxp">
			<button class="button" name="do" value="test">Sprawdź importer</button>
			<button class="button button-primary" name="do" value="sync">Synchronizuj teraz</button>
			<?php if ( $queue ) : ?><button class="button" name="do" value="stop">Zatrzymaj zapis</button><?php endif; ?>
		</form>
		<?php if ( is_array( $test ) ) : ?>
			<div class="card" style="max-width:900px">
				<h3>Pierwsza pozycja z importera</h3>
				<p>Pola: <code><?php echo esc_html( implode( ', ', $test['keys'] ) ); ?></code></p>
				<p>SKU: <code><?php echo esc_html( implode( ', ', $test['skus'] ) ); ?></code> · EAN: <code><?php echo esc_html( $test['ean'] ); ?></code></p>
				<p>Parametry: <?php echo $test['attrs'] ? esc_html( implode( ' · ', array_map( function ( $k, $v ) { return $k . ': ' . implode( '|', $v ); }, array_keys( $test['attrs'] ), $test['attrs'] ) ) ) : '<strong>brak – importer musi wysyłać pole „attributes”</strong>'; ?></p>
			</div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'fxp' ); ?><input type="hidden" name="action" value="fxp"><input type="hidden" name="do" value="save">
			<h2>Parametry</h2>
			<p>„Zapisuj” = atrybut w produktach · „Filtr” = filtr w kategoriach (pokazuje się, gdy ma go co najmniej <?php echo (int) $s['min_cov']; ?>% produktów kategorii i są ≥ 2 wartości; „Tak/Nie” = przełącznik) · „Na karcie” = w „Dodatkowych informacjach”. „Rodzaj” zasila filtr „Dla kogo”.</p>
			<table class="widefat striped">
				<thead><tr><th>Parametr w importerze</th><th>Ma go</th><th>Wartości</th><th>Nazwa w sklepie</th><th>Zapisuj</th><th>Filtr</th><th>Na karcie</th></tr></thead>
				<tbody>
				<?php foreach ( $rows as $k => $p ) : ?>
					<?php
					$cfg  = fxp_map_for( $k );
					$f    = 'map[' . base64_encode( $k ) . ']';
					$vals = (array) $p['vals'];
					arsort( $vals );
					?>
					<tr>
						<td><strong><?php echo esc_html( $p['name'] ); ?></strong></td>
						<td><?php echo $p['n'] ? (int) round( 100 * $p['n'] / $total ) . '%' : '–'; ?></td>
						<td style="max-width:380px"><?php echo esc_html( implode( ' · ', array_slice( array_keys( $vals ), 0, 12 ) ) . ( count( $vals ) > 12 ? ' …' : '' ) ); ?></td>
						<td><input type="text" name="<?php echo esc_attr( $f ); ?>[label]" value="<?php echo esc_attr( $cfg['label'] ); ?>"></td>
						<td><input type="checkbox" name="<?php echo esc_attr( $f ); ?>[use]" value="1" <?php checked( $cfg['use'] ); ?>></td>
						<td><input type="checkbox" name="<?php echo esc_attr( $f ); ?>[filter]" value="1" <?php checked( $cfg['filter'] ); ?>></td>
						<td><input type="checkbox" name="<?php echo esc_attr( $f ); ?>[show]" value="1" <?php checked( $cfg['show'] ); ?>></td>
					</tr>
				<?php endforeach; ?>
				<?php if ( ! $rows ) : ?><tr><td colspan="7">Kliknij „Synchronizuj teraz” – po pobraniu pojawi się lista parametrów z importera.</td></tr><?php endif; ?>
				</tbody>
			</table>

			<h2>Ustawienia</h2>
			<table class="form-table">
				<tr><th>Automatycznie</th><td><label><input type="checkbox" name="on" value="1" <?php checked( ! empty( $s['on'] ) ); ?>> codziennie o</label> <input type="text" name="time" value="<?php echo esc_attr( $s['time'] ); ?>" size="5"> (czas polski)</td></tr>
				<tr><th>Adres importera</th><td><input class="large-text code" name="source_url" value="<?php echo esc_attr( $raw['source_url'] ?? '' ); ?>" placeholder="<?php echo esc_attr( fxp_defaults()['source_url'] ); ?>"><p class="description">Puste = jak w „Przedsprzedaży” / „Przecenach Powerbike” (teraz: <code><?php echo esc_html( $s['source_url'] ); ?></code>).</p></td></tr>
				<tr><th>Klucz</th><td><input type="password" class="regular-text" name="key" value="" autocomplete="off" placeholder="<?php echo $s['key'] ? '•••••• (jest)' : 'brak'; ?>"><p class="description">Puste = bez zmian (domyślnie klucz z „Przedsprzedaży” / „Przecen Powerbike”).</p></td></tr>
				<tr><th>Marki</th><td><input class="regular-text" name="producers" value="<?php echo esc_attr( $s['producers'] ); ?>"></td></tr>
				<tr><th>Filtr – minimalny udział</th><td><input type="number" name="min_cov" value="<?php echo (int) $s['min_cov']; ?>" min="0" max="100">% produktów kategorii</td></tr>
				<tr><th>Ujednolicenie wartości</th><td><textarea name="replace" rows="6" class="large-text code"><?php echo esc_textarea( $s['replace'] ); ?></textarea><p class="description">Jedna linia: „wartość z importera = wartość w sklepie”. Wielkość liter bez znaczenia; „a / b” i „a/b” to to samo; „Czarny|Szary” to dwie wartości.</p></td></tr>
				<tr><th>Pola EAN w sklepie</th><td><input class="regular-text code" name="ean_metas" value="<?php echo esc_attr( $s['ean_metas'] ); ?>"></td></tr>
			</table>
			<?php submit_button( 'Zapisz' ); ?>
		</form>

		<?php if ( ! empty( $state['unmatched_list'] ) ) : ?>
			<h2>Pozycje bez dopasowania (pierwsze 100)</h2>
			<p class="description">Nie ma ich w sklepie albo mają inne SKU i brak EAN.</p>
			<p style="max-width:900px"><code><?php echo esc_html( implode( ' · ', $state['unmatched_list'] ) ); ?></code></p>
		<?php endif; ?>

		<h2>Dziennik</h2>
		<table class="widefat striped" style="max-width:900px">
			<?php foreach ( (array) get_option( 'fxp_log', array() ) as $l ) : ?>
				<tr><td style="width:140px"><?php echo esc_html( $fmt( $l[0] ) ); ?></td><td><?php echo esc_html( $l[1] ); ?></td></tr>
			<?php endforeach; ?>
		</table>
	</div>
	<?php
}
