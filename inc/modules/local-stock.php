<?php
/**
 * „Wysyłka 24h” i „Dostępny w sklepie stacjonarnym” – stany z magazynów BaseLinkera.
 *
 * Jak to działa:
 *  - kilka razy dziennie (domyślnie 6:00, 11:00, 14:00) sklep w tle pobiera z Base stany WSZYSTKICH
 *    produktów jednym zbiorczym zapytaniem na 1000 pozycji (getInventoryProductsStock),
 *  - dla produktów, które leżą w wybranych magazynach, dociąga SKU/EAN (getInventoryProductsData,
 *    tylko nowe – reszta z pamięci) i wiąże je z produktami/wariantami sklepu po SKU, a gdy brak – po EAN,
 *  - wynik zapisuje jako gotową listę; filtry, etykiety i karta produktu tylko ją czytają
 *    (klienci nie odpytują Base ani nie obciążają bazy),
 *  - po każdym zamówieniu sprzedane sztuki od razu odejmujemy z listy (do następnej synchronizacji).
 *
 * Magazyny:
 *  - „Dostępny w sklepie stacjonarnym” = magazyn sklepu (domyślnie bl_33043),
 *  - „Wysyłka 24h” = magazyny z czasem 0 dni w ustawieniach „Termin wysyłki FOX” (spójnie z koszykiem).
 *
 * Ustawienia i ręczna synchronizacja: WooCommerce → Wysyłka 24h / sklep.
 * Token i katalog Base – z ustawień „Termin wysyłki FOX” (bez ponownego wpisywania).
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

const FX_LS_OPT      = 'fx_ls_settings';
const FX_LS_DATA     = 'fx_ls_data';
const FX_LS_STATE    = 'fx_ls_state';
const FX_LS_SKUMAP   = 'fx_ls_skumap';
const FX_LS_EVENT    = 'fx_ls_sync_event';

/* =====================================================================
 * Ustawienia
 * ===================================================================== */

function fx_ls_settings() {
	$d = array(
		'on'          => 1,
		'store_wh'    => 'bl_33043',
		'ship_wh'     => '', // puste = magazyny „0 dni” z Terminu wysyłki FOX
		'times'       => '6:00, 11:00, 14:00',
		'ship_label'  => 'Wysyłka 24h',
		'store_label' => 'Dostępny w sklepie stacjonarnym',
		'badge'       => 1,
	);
	$s = get_option( FX_LS_OPT, array() );
	return array_merge( $d, is_array( $s ) ? $s : array() );
}

/** Token i ID katalogu Base z modułu „Termin wysyłki FOX”. */
function fx_ls_api() {
	$bl = function_exists( 'fox_bl_delivery_get_settings' ) ? fox_bl_delivery_get_settings() : array();
	return array( (string) ( $bl['api_token'] ?? '' ), (int) ( $bl['inventory_id'] ?? 0 ), $bl );
}

/** Lista kluczy magazynów z tekstu („bl_33043, bl_36406”). */
function fx_ls_keys( $raw ) {
	return array_values( array_filter( array_map( 'sanitize_key', preg_split( '/[\s,;]+/', (string) $raw ) ) ) );
}

/** Magazyny „Wysyłka 24h”: z ustawienia albo wszystkie z czasem 0 dni w Terminie wysyłki. */
function fx_ls_ship_keys() {
	$s    = fx_ls_settings();
	$keys = fx_ls_keys( $s['ship_wh'] );
	if ( ! $keys && function_exists( 'fox_bl_delivery_parse_warehouses' ) ) {
		list( , , $bl ) = fx_ls_api();
		foreach ( fox_bl_delivery_parse_warehouses( $bl['warehouse_map'] ?? '' ) as $k => $days ) {
			if ( 0 === (int) $days ) {
				$keys[] = $k;
			}
		}
	}
	return $keys;
}

/* =====================================================================
 * Dane (gotowa lista dla sklepu)
 * ===================================================================== */

/**
 * [ 'at' => czas, 'ship' => [ id => szt ], 'store' => [ id => szt ] ] – id produktu prostego albo wariantu.
 */
function fx_ls_data() {
	static $d = null;
	if ( null === $d ) {
		$d = get_option( FX_LS_DATA, array() );
		$d = is_array( $d ) ? $d : array();
		$d += array( 'at' => 0, 'ship' => array(), 'store' => array() );
	}
	return $d;
}

/** Produkty (ID na listach – proste i nadrzędne) z towarem w danym miejscu. */
function fx_ls_parent_ids( $kind ) {
	static $cache = array();
	if ( isset( $cache[ $kind ] ) ) {
		return $cache[ $kind ];
	}
	$d   = fx_ls_data();
	$out = array();
	foreach ( (array) $d[ $kind ] as $id => $qty ) {
		if ( $qty > 0 ) {
			$p         = (int) ( $d['parent'][ $id ] ?? $id );
			$out[ $p ] = true;
		}
	}
	return $cache[ $kind ] = $out;
}

/** Czy włączony przełącznik w adresie. */
function fx_ls_on( $kind ) {
	$p = 'ship' === $kind ? 'wysylka24' : 'wsklepie';
	return ! empty( $_GET[ $p ] ); // phpcs:ignore WordPress.Security.NonceVerification
}

/* =====================================================================
 * Filtry na liście produktów
 * ===================================================================== */

add_filter( 'fx_filter_sets', function ( $sets ) {
	if ( ! fx_ls_settings()['on'] ) {
		return $sets;
	}
	$size = function_exists( 'fx_filter_selected' ) ? fx_filter_selected( 'size' ) : array();
	foreach ( array( 'ship', 'store' ) as $kind ) {
		if ( ! fx_ls_on( $kind ) ) {
			continue;
		}
		$sets[] = array_keys( fx_ls_parent_ids( $kind ) );
		if ( $size ) {
			// Rozmiar + „w sklepie” = ten rozmiar musi leżeć w sklepie (nie dowolny rozmiar produktu).
			$only = array();
			foreach ( fx_ls_data()[ $kind ] as $id => $qty ) {
				if ( $qty > 0 ) {
					$only[ (int) $id ] = true;
				}
			}
			$sets[] = fx_filter_match_ids( 'size', $size, $only );
		}
	}
	return $sets;
} );

add_filter( 'fx_filter_switches', function ( $sw, $term ) {
	$s = fx_ls_settings();
	if ( ! $s['on'] || ! fx_ls_data()['at'] ) {
		return $sw;
	}
	$cat = null;
	foreach ( array( 'ship' => 'wysylka24', 'store' => 'wsklepie' ) as $kind => $param ) {
		$on = fx_ls_on( $kind );
		if ( ! $on ) {
			// Przełącznik tylko tam, gdzie w kategorii coś faktycznie leży.
			if ( null === $cat ) {
				$cat = array_flip( fx_filter_cat_products( $term ) );
			}
			if ( ! array_intersect_key( fx_ls_parent_ids( $kind ), $cat ) ) {
				continue;
			}
		}
		$base = remove_query_arg( array( $param, 'paged' ), fx_filter_base_url() );
		$note = '';
		if ( 'store' === $kind ) {
			$note = trim( fx_mod( 'store_city' ) . ( fx_mod( 'store_hours_text' ) ? ' · ' . fx_mod( 'store_hours_text' ) : '' ), ' ·' );
		}
		$sw[] = array(
			'key'   => $param,
			'label' => 'ship' === $kind ? $s['ship_label'] : $s['store_label'],
			'short' => 'ship' === $kind ? $s['ship_label'] : 'W sklepie',
			'note'  => $note,
			'on'    => $on,
			'url'   => $on ? $base : add_query_arg( $param, '1', $base ),
		);
	}
	return $sw;
}, 10, 2 );

/* Etykieta na kafelku: „Wysyłka 24h”. */
add_filter( 'fx_product_badges', function ( $badges, $product ) {
	$s = fx_ls_settings();
	if ( ! $s['on'] || ! $s['badge'] || ! $product instanceof WC_Product || ! $product->is_in_stock() || count( $badges ) >= 3 ) {
		return $badges;
	}
	if ( isset( fx_ls_parent_ids( 'ship' )[ $product->get_id() ] ) ) {
		$badges[] = array( $s['ship_label'], 'default' );
	}
	return $badges;
}, 10, 2 );

/* =====================================================================
 * Karta produktu: rozmiary w sklepie stacjonarnym + informacja po wyborze rozmiaru
 * ===================================================================== */

add_filter( 'woocommerce_available_variation', function ( $data, $parent, $variation ) {
	$d                = fx_ls_data();
	$id               = $variation->get_id();
	$data['fx_ship']  = (int) ( $d['ship'][ $id ] ?? 0 ) > 0;
	$data['fx_store'] = (int) ( $d['store'][ $id ] ?? 0 ) > 0;
	return $data;
}, 10, 3 );

add_action( 'woocommerce_single_product_summary', function () {
	global $product;
	$s = fx_ls_settings();
	if ( ! $s['on'] || ! $product instanceof WC_Product || ! fx_ls_data()['at'] ) {
		return;
	}
	$d     = fx_ls_data();
	$sizes = array();
	if ( $product->is_type( 'variable' ) ) {
		foreach ( $product->get_children() as $vid ) {
			if ( (int) ( $d['store'][ $vid ] ?? 0 ) > 0 ) {
				$v = wc_get_product( $vid );
				if ( $v ) {
					$attrs   = array_values( array_filter( $v->get_variation_attributes() ) );
					$name    = $attrs ? wc_get_formatted_variation( $v, true, false, false ) : '';
					$sizes[] = function_exists( 'fx_size_norm' ) ? fx_size_norm( $name ) : $name;
				}
			}
		}
		$sizes = array_values( array_unique( array_filter( $sizes ) ) );
		if ( function_exists( 'fx_size_rank' ) ) {
			usort( $sizes, function ( $a, $b ) {
				return fx_size_rank( $a ) <=> fx_size_rank( $b );
			} );
		}
		if ( function_exists( 'fx_size_label' ) ) {
			$sizes = array_map( 'fx_size_label', $sizes ); // „OS” → „Uniwersalny” jak na przyciskach rozmiarów
		}
	} elseif ( (int) ( $d['store'][ $product->get_id() ] ?? 0 ) > 0 ) {
		$sizes = array( '' );
	}
	echo '<div class="fx-ls" data-fx-ls data-ship-label="' . esc_attr( $s['ship_label'] ) . '">';
	if ( $sizes ) {
		$where = fx_mod( 'store_city' ) ? fx_mod( 'store_city' ) : 'pod Wrocławiem';
		$txt   = array_filter( $sizes ) ? 'Do przymierzenia w sklepie (' . $where . '): <strong>' . esc_html( implode( ', ', $sizes ) ) . '</strong>' : 'Do obejrzenia w sklepie stacjonarnym (' . esc_html( $where ) . ')';
		echo '<p class="fx-ls__store">' . fx_icon( 'pin', 16 ) . '<span>' . $txt . ' · <a href="' . esc_url( home_url( '/#salon' ) ) . '">dojazd</a></span></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	echo '<p class="fx-ls__pick" hidden></p></div>';
}, 28 );

add_action( 'wp_footer', function () {
	if ( ! function_exists( 'is_product' ) || ! is_product() || ! fx_ls_settings()['on'] ) {
		return;
	}
	?>
	<script>
	(function(){var box=document.querySelector('[data-fx-ls]');if(!box||!window.jQuery)return;var pick=box.querySelector('.fx-ls__pick');
	var f=jQuery('form.variations_form');
	f.on('found_variation',function(e,v){var t=[];if(v.fx_ship)t.push('✓ '+box.dataset.shipLabel);if(v.fx_store)t.push('✓ Ten rozmiar jest w sklepie stacjonarnym');pick.textContent=t.join(' · ');pick.hidden=!t.length});
	f.on('reset_data hide_variation',function(){pick.hidden=true});
	})();
	</script>
	<?php
}, 31 );

/* =====================================================================
 * Po zamówieniu: odejmij sprzedane sztuki od listy (do następnej synchronizacji)
 * ===================================================================== */

add_action( 'woocommerce_reduce_order_stock', function ( $order ) {
	$d = get_option( FX_LS_DATA, array() );
	if ( ! is_array( $d ) || empty( $d['at'] ) ) {
		return;
	}
	$changed = false;
	foreach ( $order->get_items() as $item ) {
		$id  = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
		$qty = (int) $item->get_quantity();
		foreach ( array( 'ship', 'store' ) as $kind ) {
			if ( isset( $d[ $kind ][ $id ] ) ) {
				$d[ $kind ][ $id ] = max( 0, (int) $d[ $kind ][ $id ] - $qty );
				if ( ! $d[ $kind ][ $id ] ) {
					unset( $d[ $kind ][ $id ] );
				}
				$changed = true;
			}
		}
	}
	if ( $changed ) {
		update_option( FX_LS_DATA, $d, false );
		if ( function_exists( 'fx_cache_touch' ) ) {
			fx_cache_touch();
		}
	}
} );

/* =====================================================================
 * Synchronizacja z BaseLinkerem (w tle, porcjami)
 * ===================================================================== */

/** Najbliższy termin synchronizacji (czas Warszawy). */
function fx_ls_next_time() {
	$tz  = new DateTimeZone( 'Europe/Warsaw' );
	$now = new DateTimeImmutable( 'now', $tz );
	$best = null;
	foreach ( preg_split( '/[\s,;]+/', (string) fx_ls_settings()['times'] ) as $t ) {
		if ( ! preg_match( '/^(\d{1,2})[:.](\d{2})$/', trim( $t ), $m ) ) {
			continue;
		}
		$c = $now->setTime( min( 23, (int) $m[1] ), min( 59, (int) $m[2] ) );
		if ( $c <= $now ) {
			$c = $c->modify( '+1 day' );
		}
		if ( ! $best || $c < $best ) {
			$best = $c;
		}
	}
	return $best ? $best->getTimestamp() : 0;
}

function fx_ls_schedule() {
	wp_clear_scheduled_hook( FX_LS_EVENT, array( 'start' ) );
	$t = fx_ls_settings()['on'] ? fx_ls_next_time() : 0;
	if ( $t ) {
		wp_schedule_single_event( $t, FX_LS_EVENT, array( 'start' ) );
	}
}

add_action( 'init', function () {
	if ( fx_ls_settings()['on'] && ! wp_next_scheduled( FX_LS_EVENT, array( 'start' ) ) && ! wp_next_scheduled( FX_LS_EVENT, array( 'step' ) ) ) {
		fx_ls_schedule();
	}
} );

add_action( FX_LS_EVENT, function ( $mode = 'start' ) {
	if ( 'start' === $mode ) {
		fx_ls_sync_start();
		fx_ls_schedule(); // kolejny termin dnia
	}
	$done = fx_ls_sync_step( 20 );
	if ( ! $done ) {
		wp_schedule_single_event( time() + 5, FX_LS_EVENT, array( 'step' ) );
	}
} );

/** Zapytanie do BaseLinkera (ten sam klient co Termin wysyłki). */
function fx_ls_call( $method, $params ) {
	list( $token ) = fx_ls_api();
	if ( function_exists( 'fox_bl_delivery_api_call' ) ) {
		return fox_bl_delivery_api_call( $method, $params, $token );
	}
	return new WP_Error( 'fx_ls_no_client', 'Brak modułu Termin wysyłki FOX.' );
}

function fx_ls_sync_start() {
	// Raz w tygodniu od nowa SKU/EAN z Base (gdyby ktoś zmienił SKU produktu).
	if ( (int) get_option( 'fx_ls_skumap_at' ) < time() - WEEK_IN_SECONDS ) {
		update_option( FX_LS_SKUMAP, array(), false );
		update_option( 'fx_ls_skumap_at', time(), false );
	}
	update_option(
		FX_LS_STATE,
		array(
			'phase'   => 'stock',
			'page'    => 1,
			'started' => time(),
			'raw'     => array(), // blid => [ ship, store, parent_blid ]
			'need'    => array(), // blid produktów, dla których trzeba dociągnąć SKU/EAN
			'error'   => '',
		),
		false
	);
}

/**
 * Jeden krok synchronizacji (do $seconds sekund). Zwraca true, gdy koniec.
 */
function fx_ls_sync_step( $seconds = 20 ) {
	$st = get_option( FX_LS_STATE );
	if ( ! is_array( $st ) || empty( $st['phase'] ) || 'done' === $st['phase'] ) {
		return true;
	}
	list( $token, $inv ) = fx_ls_api();
	if ( ! $token || ! $inv ) {
		$st['phase'] = 'done';
		$st['error'] = 'Brak tokenu API lub ID katalogu w ustawieniach „Termin wysyłki FOX”.';
		update_option( FX_LS_STATE, $st, false );
		return true;
	}
	$ship_keys  = fx_ls_ship_keys();
	$store_keys = fx_ls_keys( fx_ls_settings()['store_wh'] );
	$t0         = microtime( true );
	$skumap     = get_option( FX_LS_SKUMAP, array() );
	$skumap     = is_array( $skumap ) ? $skumap : array();

	$sum = function ( $stock, $keys ) {
		$n = 0;
		foreach ( $keys as $k ) {
			foreach ( (array) $stock as $wk => $q ) {
				if ( sanitize_key( (string) $wk ) === $k ) {
					$n += max( 0, (int) floor( (float) $q ) );
				}
			}
		}
		return $n;
	};

	while ( microtime( true ) - $t0 < $seconds ) {
		if ( 'stock' === $st['phase'] ) {
			// 1. Stany wszystkich produktów, po 1000 na stronę.
			$r = fx_ls_call( 'getInventoryProductsStock', array( 'inventory_id' => $inv, 'page' => (int) $st['page'] ) );
			if ( is_wp_error( $r ) ) {
				$st['error'] = $r->get_error_message();
				$st['phase'] = 'done';
				break;
			}
			$products = (array) ( $r['products'] ?? array() );
			foreach ( $products as $pid => $p ) {
				$pid = (int) ( $p['product_id'] ?? $pid );
				$var = (array) ( $p['variants'] ?? array() );
				if ( $var ) {
					foreach ( $var as $vid => $vs ) {
						$vs   = isset( $vs['stock'] ) && is_array( $vs['stock'] ) ? $vs['stock'] : $vs;
						$a    = $sum( $vs, $ship_keys );
						$b    = $sum( $vs, $store_keys );
						if ( $a || $b ) {
							$st['raw'][ (int) $vid ] = array( $a, $b, $pid );
							if ( ! isset( $skumap[ (int) $vid ] ) ) {
								$st['need'][ $pid ] = true;
							}
						}
					}
				} else {
					$a = $sum( $p['stock'] ?? array(), $ship_keys );
					$b = $sum( $p['stock'] ?? array(), $store_keys );
					if ( $a || $b ) {
						$st['raw'][ $pid ] = array( $a, $b, 0 );
						if ( ! isset( $skumap[ $pid ] ) ) {
							$st['need'][ $pid ] = true;
						}
					}
				}
			}
			if ( count( $products ) < 1000 ) {
				$st['phase'] = 'data';
			} else {
				$st['page']++;
				usleep( 700000 ); // < 100 zapytań/min w BaseLinkerze
			}
		} elseif ( 'data' === $st['phase'] ) {
			// 2. SKU/EAN tylko dla nowych produktów z towarem na miejscu (reszta z pamięci).
			$need = array_slice( array_keys( (array) $st['need'] ), 0, 100 );
			if ( ! $need ) {
				$st['phase'] = 'map';
				continue;
			}
			$r = fx_ls_call( 'getInventoryProductsData', array( 'inventory_id' => $inv, 'products' => array_map( 'intval', $need ) ) );
			if ( is_wp_error( $r ) ) {
				$st['error'] = $r->get_error_message();
				$st['phase'] = 'map'; // co mamy, to zapisz
				continue;
			}
			foreach ( (array) ( $r['products'] ?? array() ) as $pid => $p ) {
				$skumap[ (int) $pid ] = array( (string) ( $p['sku'] ?? '' ), (string) ( $p['ean'] ?? '' ) );
				foreach ( (array) ( $p['variants'] ?? array() ) as $vid => $v ) {
					$skumap[ (int) $vid ] = array( (string) ( $v['sku'] ?? '' ), (string) ( $v['ean'] ?? '' ) );
				}
			}
			foreach ( $need as $pid ) {
				unset( $st['need'][ $pid ] );
				if ( ! isset( $skumap[ (int) $pid ] ) ) {
					$skumap[ (int) $pid ] = array( '', '' ); // nie pytaj w kółko o produkt bez danych
				}
			}
			update_option( FX_LS_SKUMAP, $skumap, false );
			usleep( 700000 );
		} elseif ( 'map' === $st['phase'] ) {
			// 3. Powiązanie z produktami sklepu: SKU, a gdy brak – EAN.
			fx_ls_finish( $st, $skumap );
			$st['phase'] = 'done';
			break;
		} else {
			break;
		}
	}
	update_option( FX_LS_STATE, $st, false );
	return 'done' === $st['phase'];
}

function fx_ls_finish( &$st, $skumap ) {
	global $wpdb;
	$skus = array();
	$eans = array();
	foreach ( $st['raw'] as $blid => $row ) {
		list( $sku, $ean ) = $skumap[ $blid ] ?? array( '', '' );
		if ( '' !== $sku ) {
			$skus[ $sku ] = true;
		}
		if ( '' !== $ean ) {
			$eans[ $ean ] = true;
		}
	}
	$by_sku = array();
	foreach ( array_chunk( array_keys( $skus ), 500 ) as $chunk ) {
		$ph   = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT pm.meta_value sku, p.ID, p.post_parent FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_sku' AND p.post_type IN ('product','product_variation') AND p.post_status = 'publish' AND pm.meta_value IN ($ph)", $chunk ) ); // phpcs:ignore
		foreach ( $rows as $r ) {
			$by_sku[ $r->sku ] = array( (int) $r->ID, (int) $r->post_parent );
		}
	}
	$by_ean = array();
	$keys   = "'_woosea_ean','_global_unique_id'";
	foreach ( array_chunk( array_keys( $eans ), 500 ) as $chunk ) {
		$ph   = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT pm.meta_value ean, p.ID, p.post_parent FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key IN ($keys) AND p.post_type IN ('product','product_variation') AND p.post_status = 'publish' AND pm.meta_value IN ($ph)", $chunk ) ); // phpcs:ignore
		foreach ( $rows as $r ) {
			$by_ean[ $r->ean ] = array( (int) $r->ID, (int) $r->post_parent );
		}
	}
	$out     = array( 'at' => time(), 'ship' => array(), 'store' => array(), 'parent' => array(), 'stats' => array() );
	$matched = 0;
	$miss    = array();
	foreach ( $st['raw'] as $blid => $row ) {
		list( $sku, $ean ) = $skumap[ $blid ] ?? array( '', '' );
		$hit = ( '' !== $sku && isset( $by_sku[ $sku ] ) ) ? $by_sku[ $sku ] : ( ( '' !== $ean && isset( $by_ean[ $ean ] ) ) ? $by_ean[ $ean ] : null );
		if ( ! $hit ) {
			if ( count( $miss ) < 30 ) {
				$miss[] = $sku ? $sku : ( $ean ? 'EAN ' . $ean : 'Base #' . $blid );
			}
			continue;
		}
		list( $id, $parent ) = $hit;
		$matched++;
		if ( $row[0] ) {
			$out['ship'][ $id ] = ( $out['ship'][ $id ] ?? 0 ) + $row[0];
		}
		if ( $row[1] ) {
			$out['store'][ $id ] = ( $out['store'][ $id ] ?? 0 ) + $row[1];
		}
		if ( $parent ) {
			$out['parent'][ $id ] = $parent;
		}
	}
	$out['stats'] = array(
		'base_items' => count( $st['raw'] ),
		'matched'    => $matched,
		'ship'       => count( $out['ship'] ),
		'store'      => count( $out['store'] ),
		'missing'    => $miss,
		'seconds'    => time() - (int) $st['started'],
		'error'      => $st['error'],
	);
	update_option( FX_LS_DATA, $out, false );
	unset( $st['raw'], $st['need'] );
	if ( function_exists( 'fx_cache_touch' ) ) {
		fx_cache_touch(); // filtry kategorii odświeżą się same
	}
}

/* =====================================================================
 * Panel: WooCommerce → Wysyłka 24h / sklep
 * ===================================================================== */

add_action( 'admin_menu', function () {
	add_submenu_page( 'woocommerce', 'Wysyłka 24h i sklep stacjonarny', 'Wysyłka 24h / sklep', 'manage_woocommerce', 'fx-local-stock', 'fx_ls_admin_page' );
}, 60 );

add_action( 'admin_post_fx_ls_save', function () {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( 'Brak uprawnień.' );
	}
	check_admin_referer( 'fx_ls_save' );
	$in = wp_unslash( $_POST );
	update_option(
		FX_LS_OPT,
		array(
			'on'          => empty( $in['on'] ) ? 0 : 1,
			'badge'       => empty( $in['badge'] ) ? 0 : 1,
			'store_wh'    => implode( ', ', fx_ls_keys( $in['store_wh'] ?? '' ) ),
			'ship_wh'     => implode( ', ', fx_ls_keys( $in['ship_wh'] ?? '' ) ),
			'times'       => sanitize_text_field( $in['times'] ?? '' ),
			'ship_label'  => sanitize_text_field( $in['ship_label'] ?? '' ),
			'store_label' => sanitize_text_field( $in['store_label'] ?? '' ),
		),
		false
	);
	fx_ls_schedule();
	if ( ! empty( $in['sync_now'] ) ) {
		fx_ls_sync_start();
		$done = fx_ls_sync_step( 25 );
		if ( ! $done ) {
			wp_schedule_single_event( time() + 5, FX_LS_EVENT, array( 'step' ) );
		}
	}
	wp_safe_redirect( admin_url( 'admin.php?page=fx-local-stock&saved=1' ) );
	exit;
} );

function fx_ls_admin_page() {
	$s     = fx_ls_settings();
	$d     = fx_ls_data();
	$st    = get_option( FX_LS_STATE, array() );
	$stats = (array) ( $d['stats'] ?? array() );
	list( $token, $inv ) = fx_ls_api();
	$next  = wp_next_scheduled( FX_LS_EVENT, array( 'start' ) );
	$fmt   = function ( $ts ) {
		return $ts ? wp_date( 'd.m.Y H:i', $ts ) : '—';
	};
	?>
	<div class="wrap">
		<h1>Wysyłka 24h i sklep stacjonarny</h1>
		<p>Sklep w tle pobiera z BaseLinkera stany wybranych magazynów i oznacza produkty: filtry „<?php echo esc_html( $s['ship_label'] ); ?>” i „<?php echo esc_html( $s['store_label'] ); ?>” na listach, etykieta na kafelku, rozmiary do przymierzenia na karcie produktu. Klienci nie odpytują Base – czytają gotową listę.</p>
		<?php if ( ! $token || ! $inv ) : ?>
			<div class="notice notice-error"><p>Brak tokenu API lub ID katalogu – uzupełnij je w ustawieniach „Termin wysyłki FOX”.</p></div>
		<?php endif; ?>
		<?php if ( ! empty( $_GET['saved'] ) ) : // phpcs:ignore ?>
			<div class="notice notice-success is-dismissible"><p>Zapisano.</p></div>
		<?php endif; ?>

		<h2>Ostatnia synchronizacja</h2>
		<table class="widefat striped" style="max-width:760px">
			<tr><th>Kiedy</th><td><?php echo esc_html( $fmt( $d['at'] ) ); ?><?php echo ! empty( $st['phase'] ) && 'done' !== $st['phase'] ? ' <strong>(trwa kolejna – odśwież za chwilę)</strong>' : ''; ?></td></tr>
			<tr><th>Następna</th><td><?php echo esc_html( $fmt( $next ) ); ?></td></tr>
			<tr><th>Pozycje z Base na tych magazynach</th><td><?php echo (int) ( $stats['base_items'] ?? 0 ); ?> (powiązane ze sklepem: <?php echo (int) ( $stats['matched'] ?? 0 ); ?>)</td></tr>
			<tr><th><?php echo esc_html( $s['ship_label'] ); ?></th><td><?php echo (int) ( $stats['ship'] ?? 0 ); ?> produktów / rozmiarów</td></tr>
			<tr><th><?php echo esc_html( $s['store_label'] ); ?></th><td><?php echo (int) ( $stats['store'] ?? 0 ); ?> produktów / rozmiarów</td></tr>
			<tr><th>Czas</th><td><?php echo (int) ( $stats['seconds'] ?? 0 ); ?> s</td></tr>
			<?php if ( ! empty( $stats['error'] ) || ! empty( $st['error'] ) ) : ?>
				<tr><th>Błąd</th><td style="color:#b32d2e"><?php echo esc_html( $stats['error'] ?? $st['error'] ); ?></td></tr>
			<?php endif; ?>
			<?php if ( ! empty( $stats['missing'] ) ) : ?>
				<tr><th>Nie znaleziono w sklepie (SKU/EAN, max 30)</th><td><code><?php echo esc_html( implode( ', ', $stats['missing'] ) ); ?></code></td></tr>
			<?php endif; ?>
		</table>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'fx_ls_save' ); ?>
			<input type="hidden" name="action" value="fx_ls_save">
			<h2>Ustawienia</h2>
			<table class="form-table" role="presentation">
				<tr><th>Włączone</th><td><label><input type="checkbox" name="on" value="1" <?php checked( $s['on'] ); ?>> Filtry, etykiety i synchronizacja</label></td></tr>
				<tr><th><label for="fx_ls_store">Magazyn sklepu stacjonarnego</label></th><td><input id="fx_ls_store" class="regular-text code" name="store_wh" value="<?php echo esc_attr( $s['store_wh'] ); ?>"><p class="description">Klucz magazynu z Base, np. <code>bl_33043</code> (kilka – po przecinku).</p></td></tr>
				<tr><th><label for="fx_ls_ship">Magazyny „<?php echo esc_html( $s['ship_label'] ); ?>”</label></th><td><input id="fx_ls_ship" class="regular-text code" name="ship_wh" value="<?php echo esc_attr( $s['ship_wh'] ); ?>" placeholder="<?php echo esc_attr( implode( ', ', fx_ls_ship_keys() ) ); ?>"><p class="description">Puste = magazyny z czasem 0 dni w „Termin wysyłki FOX” (teraz: <code><?php echo esc_html( implode( ', ', fx_ls_ship_keys() ) ); ?></code>).</p></td></tr>
				<tr><th><label for="fx_ls_times">Godziny synchronizacji</label></th><td><input id="fx_ls_times" class="regular-text" name="times" value="<?php echo esc_attr( $s['times'] ); ?>"><p class="description">Czas polski, po przecinku. Sprzedane sztuki znikają z listy od razu po zamówieniu.</p></td></tr>
				<tr><th><label for="fx_ls_l1">Nazwa filtra wysyłki</label></th><td><input id="fx_ls_l1" class="regular-text" name="ship_label" value="<?php echo esc_attr( $s['ship_label'] ); ?>"></td></tr>
				<tr><th><label for="fx_ls_l2">Nazwa filtra sklepu</label></th><td><input id="fx_ls_l2" class="regular-text" name="store_label" value="<?php echo esc_attr( $s['store_label'] ); ?>"></td></tr>
				<tr><th>Etykieta na kafelku</th><td><label><input type="checkbox" name="badge" value="1" <?php checked( $s['badge'] ); ?>> Pokaż „<?php echo esc_html( $s['ship_label'] ); ?>” na zdjęciu produktu</label></td></tr>
			</table>
			<p>
				<button class="button button-primary" name="save" value="1">Zapisz</button>
				<button class="button" name="sync_now" value="1">Zapisz i synchronizuj teraz</button>
			</p>
			<p class="description">Pewne odpalanie o wyznaczonych godzinach: ustaw w panelu hostingu zadanie cron co 5 minut: <code>wget -q -O - <?php echo esc_html( site_url( 'wp-cron.php?doing_wp_cron' ) ); ?> &gt;/dev/null 2&gt;&amp;1</code></p>
		</form>
	</div>
	<?php
}
