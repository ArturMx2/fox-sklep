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
const FX_LS_LOG      = 'fx_ls_log';

/** Dziennik synchronizacji (ostatnie 400 wpisów, czas polski). */
function fx_ls_log( $msg ) {
	$log   = get_option( FX_LS_LOG, array() );
	$log   = is_array( $log ) ? $log : array();
	$log[] = array( time(), (string) $msg );
	if ( count( $log ) > 400 ) {
		$log = array_slice( $log, -400 );
	}
	update_option( FX_LS_LOG, $log, false );
}

function fx_ls_log_text( $limit = 200 ) {
	$log = array_slice( (array) get_option( FX_LS_LOG, array() ), -$limit );
	$tz  = new DateTimeZone( 'Europe/Warsaw' );
	$out = array();
	foreach ( $log as $row ) {
		$out[] = wp_date( 'd.m H:i:s', (int) $row[0], $tz ) . '  ' . $row[1];
	}
	return implode( "\n", $out );
}

/* =====================================================================
 * Ustawienia
 * ===================================================================== */

/* Jednorazowo: „Wysyłka 24h” → „Wysyłka dziś” (towar ze sklepu wysyłamy tego samego dnia, reszta i tak w 24h). */
add_action( 'init', function () {
	$s = get_option( FX_LS_OPT );
	if ( is_array( $s ) && 'Wysyłka 24h' === ( $s['ship_label'] ?? '' ) && ! get_option( 'fx_ls_label_v2' ) ) {
		$s['ship_label'] = 'Wysyłka dziś';
		update_option( FX_LS_OPT, $s, false );
	}
	if ( ! get_option( 'fx_ls_label_v2' ) ) {
		update_option( 'fx_ls_label_v2', 1, false );
	}
} );

function fx_ls_settings() {
	$d = array(
		'on'          => 1,
		'store_wh'    => 'bl_33043',
		'ship_wh'     => '', // puste = magazyny „0 dni” z Terminu wysyłki FOX
		'times'       => '6:00, 11:00, 14:00',
		'ship_label'  => 'Wysyłka dziś',
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
	// Puste = ten sam magazyn co sklep stacjonarny (towar fizycznie u nas). Magazyny „0 dni” z Terminu
	// wysyłki się nie nadają – jest wśród nich duży magazyn dostawcy (dziesiątki tysięcy pozycji).
	return $keys ? $keys : fx_ls_keys( $s['store_wh'] );
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
		if ( 'ship' === $kind && function_exists( 'fox_bl_delivery_get_settings' ) ) {
			$note = 'przy zamówieniu do ' . fox_bl_delivery_get_settings()['cutoff_time'];
		}
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
	f.on('found_variation',function(e,v){pick.textContent=v.fx_store?'✓ Ten rozmiar jest w sklepie stacjonarnym – możesz go przymierzyć':'';pick.hidden=!v.fx_store});
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
		fx_ls_sync_start( 'harmonogram' );
		fx_ls_schedule(); // kolejny termin dnia
	}
	$done = fx_ls_sync_step( 45, 'cron' );
	if ( ! $done ) {
		wp_schedule_single_event( time() + 5, FX_LS_EVENT, array( 'step' ) );
	}
} );

/** Zapytanie do BaseLinkera (ten sam klient co Termin wysyłki). */
function fx_ls_call( $method, $params ) {
	list( $token ) = fx_ls_api();
	// Własne zapytanie z dłuższym czasem: zbiorcze stany z dużego katalogu Base potrafią iść 20–60 s
	// (moduł Terminu wysyłki ma 15 s – wystarcza dla pojedynczego produktu, nie dla 1000).
	$r = wp_remote_post(
		'https://api.baselinker.com/connector.php',
		array(
			'timeout' => 90,
			'headers' => array( 'X-BLToken' => $token ),
			'body'    => array(
				'method'     => $method,
				'parameters' => wp_json_encode( $params ),
			),
		)
	);
	if ( is_wp_error( $r ) ) {
		return $r;
	}
	if ( 200 !== (int) wp_remote_retrieve_response_code( $r ) ) {
		return new WP_Error( 'fx_ls_http', 'BaseLinker: kod HTTP ' . (int) wp_remote_retrieve_response_code( $r ) );
	}
	$data = json_decode( wp_remote_retrieve_body( $r ), true );
	if ( ! is_array( $data ) ) {
		return new WP_Error( 'fx_ls_json', 'BaseLinker zwrócił nieprawidłową odpowiedź.' );
	}
	if ( 'SUCCESS' !== ( $data['status'] ?? '' ) ) {
		return new WP_Error( 'fx_ls_api', sanitize_text_field( $data['error_message'] ?? 'Błąd BaseLinkera.' ) );
	}
	return $data;
}

function fx_ls_sync_start( $who = 'ręcznie' ) {
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
			'touched' => time(),
			'seen'    => array(), // klucze magazynów z towarem (diagnostyka)
		),
		false
	);
	list( $token, $inv ) = fx_ls_api();
	fx_ls_log( '=== START (' . $who . ') – katalog Base ' . $inv . ( $token ? '' : ' – BRAK TOKENU' ) . '; sklep: ' . implode( ',', fx_ls_keys( fx_ls_settings()['store_wh'] ) ) . '; wysyłka 24h: ' . implode( ',', fx_ls_ship_keys() ) . '; SKU/EAN w pamięci: ' . count( (array) get_option( FX_LS_SKUMAP, array() ) ) );
}

/**
 * Jeden krok synchronizacji (do $seconds sekund). Zwraca true, gdy koniec.
 */
function fx_ls_sync_step( $seconds = 20, $who = '' ) {
	$st = get_option( FX_LS_STATE );
	if ( ! is_array( $st ) || empty( $st['phase'] ) || 'done' === $st['phase'] ) {
		return true;
	}
	// Jeden krok naraz (cron i panel nie mogą pracować równolegle).
	if ( get_transient( 'fx_ls_lock' ) ) {
		return false;
	}
	set_transient( 'fx_ls_lock', 1, 5 * MINUTE_IN_SECONDS );
	$st = fx_ls_sync_step_run( $st, $seconds, $who );
	delete_transient( 'fx_ls_lock' );
	return 'done' === $st['phase'];
}

function fx_ls_sync_step_run( $st, $seconds, $who ) {
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- jedno zapytanie do Base może trwać ponad minutę
	}
	list( $token, $inv ) = fx_ls_api();
	if ( ! $token || ! $inv ) {
		$st['phase'] = 'done';
		$st['error'] = 'Brak tokenu API lub ID katalogu w ustawieniach „Termin wysyłki FOX”.';
		fx_ls_log( 'BŁĄD: ' . $st['error'] );
		update_option( FX_LS_STATE, $st, false );
		return $st;
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
			$c0 = microtime( true );
			fx_ls_log( 'Stany: pytam o stronę ' . (int) $st['page'] . ( $who ? ' (' . $who . ')' : '' ) . '…' );
			$r  = fx_ls_call( 'getInventoryProductsStock', array( 'inventory_id' => $inv, 'page' => (int) $st['page'] ) );
			$ct = round( microtime( true ) - $c0, 1 );
			if ( is_wp_error( $r ) ) {
				// Do 3 prób tej samej strony (kolejna za kilka sekund), dopiero potem przerwij.
				$st['fails'] = (int) ( $st['fails'] ?? 0 ) + 1;
				$st['error'] = $r->get_error_message() . ' (strona ' . (int) $st['page'] . ', próba ' . $st['fails'] . ' z 3)';
				fx_ls_log( 'BŁĄD po ' . $ct . ' s: ' . $st['error'] );
				if ( $st['fails'] >= 3 ) {
					$st['phase'] = 'done';
					fx_ls_log( '=== PRZERWANO – zostaje poprzednia lista' );
				}
				break;
			}
			$st['fails'] = 0;
			$st['error'] = '';
			$products = (array) ( $r['products'] ?? array() );
			$before   = count( (array) $st['raw'] );
			if ( 1 === (int) $st['page'] && $products ) {
				// Diagnostyka: jak wygląda jeden produkt w odpowiedzi Base.
				fx_ls_log( 'Przykład z Base: ' . substr( wp_json_encode( current( $products ) ), 0, 400 ) );
			}
			foreach ( $products as $pid => $p ) {
				foreach ( array_merge( array( (array) ( $p['stock'] ?? array() ) ), array_map( function ( $v ) {
					return isset( $v['stock'] ) && is_array( $v['stock'] ) ? $v['stock'] : (array) $v;
				}, (array) ( $p['variants'] ?? array() ) ) ) as $stk ) {
					foreach ( $stk as $wk => $q ) {
						if ( (float) $q > 0 ) {
							$st['seen'][ (string) $wk ] = ( $st['seen'][ (string) $wk ] ?? 0 ) + 1;
						}
					}
				}
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
			fx_ls_log( 'Stany: strona ' . (int) $st['page'] . ' – ' . count( $products ) . ' produktów w ' . $ct . ' s; z towarem na naszych magazynach na tej stronie: ' . ( count( (array) $st['raw'] ) - $before ) . ' (razem ' . count( (array) $st['raw'] ) . ')' );
			if ( count( $products ) < 1000 ) {
				$seen = (array) $st['seen'];
				arsort( $seen );
				$parts = array();
				foreach ( $seen as $k => $n ) {
					$parts[] = $k . ': ' . $n;
				}
				fx_ls_log( 'Stany: koniec, ' . (int) $st['page'] . ' stron. Magazyny z towarem w Base (liczba pozycji): ' . ( $parts ? implode( ', ', $parts ) : 'BRAK' ) );
				fx_ls_log( 'SKU/EAN do dociągnięcia: ' . count( (array) $st['need'] ) . ' produktów' );
				$st['phase'] = 'data';
			} else {
				$st['page']++;
				usleep( 700000 ); // < 100 zapytań/min w BaseLinkerze
			}
		} elseif ( 'data' === $st['phase'] ) {
			// 2. SKU/EAN tylko dla nowych produktów z towarem na miejscu (reszta z pamięci).
			if ( count( (array) $st['need'] ) > 15000 ) {
				// Bezpiecznik: tyle pozycji to nie „towar na półce”, tylko magazyn dostawcy.
				$st['error'] = 'Przerwano: ' . count( (array) $st['need'] ) . ' produktów do sprawdzenia – to za dużo jak na magazyn sklepu. Sprawdź magazyny w ustawieniach.';
				fx_ls_log( 'BEZPIECZNIK: ' . $st['error'] );
				update_option( FX_LS_SKUMAP, array(), false );
				$st['phase'] = 'done';
				break;
			}
			$need = array_slice( array_keys( (array) $st['need'] ), 0, 100 );
			if ( ! $need ) {
				$st['phase'] = 'map';
				continue;
			}
			$c0 = microtime( true );
			$r  = fx_ls_call( 'getInventoryProductsData', array( 'inventory_id' => $inv, 'products' => array_map( 'intval', $need ) ) );
			$ct = round( microtime( true ) - $c0, 1 );
			if ( is_wp_error( $r ) ) {
				$st['error'] = $r->get_error_message();
				fx_ls_log( 'BŁĄD SKU/EAN po ' . $ct . ' s: ' . $st['error'] . ' – zapisuję to, co mam' );
				$st['phase'] = 'map'; // co mamy, to zapisz
				continue;
			}
			fx_ls_log( 'SKU/EAN: ' . count( (array) ( $r['products'] ?? array() ) ) . ' z ' . count( $need ) . ' produktów w ' . $ct . ' s; zostało ' . max( 0, count( (array) $st['need'] ) - count( $need ) ) );
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
	$st['touched'] = time();
	update_option( FX_LS_STATE, $st, false );
	return $st;
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
	fx_ls_log( 'Powiązanie ze sklepem: ' . $matched . ' z ' . count( $st['raw'] ) . ' (po SKU: ' . count( $by_sku ) . ', po EAN: ' . count( $by_ean ) . '). Wysyłka 24h: ' . count( $out['ship'] ) . ', sklep: ' . count( $out['store'] ) . '.' . ( $miss ? ' Bez pary w sklepie m.in.: ' . implode( ', ', array_slice( $miss, 0, 10 ) ) : '' ) );
	fx_ls_log( '=== KONIEC w ' . ( time() - (int) $st['started'] ) . ' s' );
	unset( $st['raw'], $st['need'] );
	if ( function_exists( 'fx_cache_touch' ) ) {
		fx_cache_touch(); // filtry kategorii odświeżą się same
	}
}

/* Panel napędza synchronizację sam (bez czekania na zadania w tle). */
add_action( 'wp_ajax_fx_ls_tick', function () {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error();
	}
	check_ajax_referer( 'fx_ls_tick' );
	$done = fx_ls_sync_step( 40, 'panel' );
	$st   = (array) get_option( FX_LS_STATE, array() );
	wp_send_json_success(
		array(
			'done'  => $done,
			'busy'  => (bool) get_transient( 'fx_ls_lock' ),
			'phase' => $st['phase'] ?? '',
			'page'  => (int) ( $st['page'] ?? 0 ),
			'log'   => fx_ls_log_text( 200 ),
		)
	);
} );

/* =====================================================================
 * Panel: WooCommerce → Wysyłka 24h / sklep
 * ===================================================================== */

add_action( 'admin_menu', function () {
	add_submenu_page( 'woocommerce', 'Wysyłka 24h i sklep stacjonarny', 'Wysyłka 24h / sklep', 'manage_woocommerce', 'fx-local-stock', 'fx_ls_admin_page' );
}, 60 );

add_action( 'admin_post_fx_ls_stop', function () {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( 'Brak uprawnień.' );
	}
	check_admin_referer( 'fx_ls_stop' );
	$st = (array) get_option( FX_LS_STATE, array() );
	unset( $st['raw'], $st['need'] );
	$st['phase'] = 'done';
	$st['error'] = 'Przerwano ręcznie – zostaje poprzednia lista.';
	update_option( FX_LS_STATE, $st, false );
	wp_clear_scheduled_hook( FX_LS_EVENT, array( 'step' ) );
	delete_transient( 'fx_ls_lock' );
	fx_ls_log( '=== PRZERWANO RĘCZNIE' );
	wp_safe_redirect( admin_url( 'admin.php?page=fx-local-stock' ) );
	exit;
} );

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
		// W tle, żeby panel nie wisiał na długich odpowiedziach Base.
		fx_ls_sync_start( 'ręcznie z panelu' );
		wp_clear_scheduled_hook( FX_LS_EVENT, array( 'step' ) );
		wp_schedule_single_event( time(), FX_LS_EVENT, array( 'step' ) );
		spawn_cron();
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
		return $ts ? wp_date( 'd.m.Y H:i', $ts, new DateTimeZone( 'Europe/Warsaw' ) ) . ' (czas polski)' : '—';
	};
	$running = ! empty( $st['phase'] ) && 'done' !== $st['phase'];
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
			<tr><th>Kiedy</th><td><?php echo esc_html( $fmt( $d['at'] ) ); ?><?php echo $running ? ' <strong>(trwa synchronizacja: ' . ( 'stock' === $st['phase'] ? 'stany, strona ' . (int) $st['page'] : ( 'data' === $st['phase'] ? 'SKU/EAN, zostało ' . count( (array) ( $st['need'] ?? array() ) ) . ' produktów' : 'zapis' ) ) . ' – strona odświeża się sama)</strong>' : ''; ?></td></tr>
			<tr><th>Następna</th><td><?php echo esc_html( $fmt( $next ) ); ?></td></tr>
			<tr><th>Pozycje z Base na tych magazynach</th><td><?php echo (int) ( $stats['base_items'] ?? 0 ); ?> (powiązane ze sklepem: <?php echo (int) ( $stats['matched'] ?? 0 ); ?>)</td></tr>
			<tr><th><?php echo esc_html( $s['ship_label'] ); ?></th><td><?php echo (int) ( $stats['ship'] ?? 0 ); ?> produktów / rozmiarów</td></tr>
			<tr><th><?php echo esc_html( $s['store_label'] ); ?></th><td><?php echo (int) ( $stats['store'] ?? 0 ); ?> produktów / rozmiarów</td></tr>
			<tr><th>Czas</th><td><?php echo (int) ( $stats['seconds'] ?? 0 ); ?> s</td></tr>
			<?php $err = ! empty( $st['error'] ) ? $st['error'] : ( $stats['error'] ?? '' ); ?>
			<?php if ( $err ) : ?>
				<tr><th>Błąd</th><td style="color:#b32d2e"><?php echo esc_html( $err ); ?></td></tr>
			<?php endif; ?>
			<?php if ( ! empty( $stats['missing'] ) ) : ?>
				<tr><th>Nie znaleziono w sklepie (SKU/EAN, max 30)</th><td><code><?php echo esc_html( implode( ', ', $stats['missing'] ) ); ?></code></td></tr>
			<?php endif; ?>
		</table>

		<?php if ( $running ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:10px 0">
				<?php wp_nonce_field( 'fx_ls_stop' ); ?><input type="hidden" name="action" value="fx_ls_stop">
				<button class="button button-secondary">Przerwij synchronizację</button>
			</form>
		<?php endif; ?>
		<h2>Dziennik synchronizacji</h2>
		<p id="fx-ls-status" class="description"><?php echo $running ? 'Synchronizacja trwa – ta strona prowadzi ją dalej, nie zamykaj jej.' : 'Ostatnie wpisy (najnowsze na dole).'; ?></p>
		<pre id="fx-ls-log" style="max-width:1100px;max-height:420px;overflow:auto;background:#1d2327;color:#e5e5e5;padding:12px 14px;font-size:12px;line-height:1.5;white-space:pre-wrap"><?php echo esc_html( fx_ls_log_text( 200 ) ); ?></pre>
		<?php if ( $running ) : ?>
		<script>
		(function(){var log=document.getElementById('fx-ls-log'),stat=document.getElementById('fx-ls-status');log.scrollTop=log.scrollHeight;
		function tick(){var f=new FormData();f.append('action','fx_ls_tick');f.append('_ajax_nonce','<?php echo esc_js( wp_create_nonce( 'fx_ls_tick' ) ); ?>');
			fetch(ajaxurl,{method:'POST',body:f,credentials:'same-origin'}).then(function(r){return r.json()}).then(function(j){
				if(!j||!j.success){stat.textContent='Błąd odpowiedzi panelu – spróbuję ponownie za 10 s.';setTimeout(tick,10000);return}
				log.textContent=j.data.log;log.scrollTop=log.scrollHeight;
				if(j.data.done){stat.textContent='Gotowe – przeładowuję…';setTimeout(function(){location.reload()},1500);return}
				stat.textContent=(j.data.busy?'Krok w toku (inny proces), czekam…':'Synchronizacja trwa: '+(j.data.phase==='stock'?'stany, strona '+j.data.page:j.data.phase)+' – nie zamykaj tej strony.');
				setTimeout(tick,j.data.busy?5000:500);
			}).catch(function(){stat.textContent='Brak połączenia (np. limit czasu serwera) – ponawiam za 10 s.';setTimeout(tick,10000)});}
		tick();})();
		</script>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'fx_ls_save' ); ?>
			<input type="hidden" name="action" value="fx_ls_save">
			<h2>Ustawienia</h2>
			<table class="form-table" role="presentation">
				<tr><th>Włączone</th><td><label><input type="checkbox" name="on" value="1" <?php checked( $s['on'] ); ?>> Filtry, etykiety i synchronizacja</label></td></tr>
				<tr><th><label for="fx_ls_store">Magazyn sklepu stacjonarnego</label></th><td><input id="fx_ls_store" class="regular-text code" name="store_wh" value="<?php echo esc_attr( $s['store_wh'] ); ?>"><p class="description">Klucz magazynu z Base, np. <code>bl_33043</code> (kilka – po przecinku).</p></td></tr>
				<tr><th><label for="fx_ls_ship">Magazyny „<?php echo esc_html( $s['ship_label'] ); ?>”</label></th><td><input id="fx_ls_ship" class="regular-text code" name="ship_wh" value="<?php echo esc_attr( $s['ship_wh'] ); ?>" placeholder="<?php echo esc_attr( implode( ', ', fx_ls_ship_keys() ) ); ?>"><p class="description">Puste = ten sam magazyn co sklep stacjonarny. Dopisz inne tylko, jeśli towar z nich naprawdę wysyłacie w 24h (teraz: <code><?php echo esc_html( implode( ', ', fx_ls_ship_keys() ) ); ?></code>).</p></td></tr>
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
