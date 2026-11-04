<?php
/**
 * Analityka wyszukiwarki: WooCommerce → Wyszukiwarka → Analityka.
 *
 * Okresy 7 / 14 / 30 / 60 / 90 / 180 / 365 dni albo własny zakres, porównanie z poprzednim okresem
 * tej samej długości, eksport każdego raportu do CSV (Excel: UTF-8, średnik).
 *
 * Dane: tabela {prefix}fx_search_log (search.php), zdarzenia:
 *   dd    – wyszukanie w oknie podpowiedzi (po 1,5 s bez pisania),
 *   page  – wejście na stronę wyników,
 *   ddc / pagec – kliknięcie produktu (z pozycją),
 *   cart  – dodanie do koszyka produktu znalezionego wyszukiwarką,
 *   order – pozycja zamówienia z produktem znalezionym wyszukiwarką (wartość w zł).
 * „Wyszukanie” liczymy raz na sesję, frazę i 30 minut – okno + strona wyników to jedno wyszukanie.
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

const FX_SS_PERIODS = array( 7, 14, 30, 60, 90, 180, 365 );

/** Zakres z adresu: [ od, do, etykieta, poprzedni od, poprzedni do, parametry ]. */
function fx_ss_period() {
	$tz    = wp_timezone();
	$today = new DateTimeImmutable( 'today', $tz );
	$p     = isset( $_GET['p'] ) ? sanitize_key( wp_unslash( $_GET['p'] ) ) : '30'; // phpcs:ignore
	$od    = isset( $_GET['od'] ) ? sanitize_text_field( wp_unslash( $_GET['od'] ) ) : ''; // phpcs:ignore
	$do    = isset( $_GET['do'] ) ? sanitize_text_field( wp_unslash( $_GET['do'] ) ) : ''; // phpcs:ignore
	if ( 'own' === $p && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $od ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $do ) ) {
		$a = new DateTimeImmutable( $od, $tz );
		$b = new DateTimeImmutable( $do, $tz );
		if ( $b < $a ) {
			list( $a, $b ) = array( $b, $a );
		}
		$label = $a->format( 'd.m.Y' ) . ' – ' . $b->format( 'd.m.Y' );
	} else {
		$days  = in_array( (int) $p, FX_SS_PERIODS, true ) ? (int) $p : 30;
		$p     = (string) $days;
		$a     = $today->modify( '-' . ( $days - 1 ) . ' days' );
		$b     = $today;
		$label = 'ostatnie ' . $days . ' dni';
	}
	$from = $a->getTimestamp();
	$to   = $b->modify( '+1 day' )->getTimestamp() - 1;
	$len  = $to - $from + 1;
	return array(
		'from'  => $from,
		'to'    => $to,
		'label' => $label,
		'pfrom' => $from - $len,
		'pto'   => $from - 1,
		'args'  => array( 'p' => $p, 'od' => $a->format( 'Y-m-d' ), 'do' => $b->format( 'Y-m-d' ) ),
		'days'  => (int) round( $len / DAY_IN_SECONDS ),
	);
}

/** Klucz „jednego wyszukania”: sesja (albo wpis bez sesji) + fraza + 30 minut. */
function fx_ss_key() {
	return "CONCAT(CASE WHEN sid = '' THEN id ELSE sid END, '|', qn, '|', FLOOR(ts / 1800))";
}

const FX_SS_SEARCH = "src IN ('dd','page')";
const FX_SS_CLICK  = "src IN ('ddc','pagec')";

/** Podsumowanie okresu. */
function fx_ss_summary( $from, $to ) {
	global $wpdb;
	$l = fx_srch_log_table();
	$k = fx_ss_key();
	$s = FX_SS_SEARCH;
	$c = FX_SS_CLICK;
	$r = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore
		"SELECT
			COUNT(DISTINCT CASE WHEN $s THEN $k END) searches,
			COUNT(DISTINCT CASE WHEN $s AND n = 0 THEN $k END) zero,
			COUNT(DISTINCT CASE WHEN $s AND sid <> '' THEN sid END) sessions,
			COUNT(DISTINCT CASE WHEN $c AND sid <> '' THEN sid END) click_sessions,
			SUM(CASE WHEN $c THEN 1 ELSE 0 END) clicks,
			AVG(CASE WHEN $c AND pos > 0 THEN pos END) avg_pos,
			SUM(CASE WHEN src = 'cart' THEN 1 ELSE 0 END) carts,
			COUNT(DISTINCT CASE WHEN src = 'order' THEN oid END) orders,
			SUM(CASE WHEN src = 'order' THEN val ELSE 0 END) revenue,
			COUNT(DISTINCT CASE WHEN $s AND dev = 'm' THEN $k END) mobile,
			COUNT(DISTINCT CASE WHEN $s AND dev = 'd' THEN $k END) desktop,
			COUNT(DISTINCT CASE WHEN $s THEN qn END) phrases
		FROM $l WHERE ts BETWEEN %d AND %d",
		$from,
		$to
	), ARRAY_A );
	$r = array_map( 'floatval', (array) $r );
	// Sprzedaż sklepu w okresie (tabela statystyk WooCommerce) – udział wyszukiwarki.
	$r['shop_revenue'] = 0;
	$stats             = $wpdb->prefix . 'wc_order_stats';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $stats ) ) === $stats ) { // phpcs:ignore
		$tz                = wp_timezone();
		$r['shop_revenue'] = (float) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore
			"SELECT SUM(total_sales) FROM $stats WHERE parent_id = 0 AND status NOT IN ('wc-failed','wc-cancelled','wc-refunded','wc-pending','wc-checkout-draft','trash') AND date_created BETWEEN %s AND %s",
			wp_date( 'Y-m-d H:i:s', $from, $tz ),
			wp_date( 'Y-m-d H:i:s', $to, $tz )
		) );
	}
	return $r;
}

/** Dzień po dniu. */
function fx_ss_days( $from, $to ) {
	global $wpdb;
	$l    = fx_srch_log_table();
	$k    = fx_ss_key();
	$s    = FX_SS_SEARCH;
	$c    = FX_SS_CLICK;
	$off  = (int) wp_timezone()->getOffset( new DateTime( '@' . $from ) );
	$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore
		"SELECT FLOOR((ts + %d) / 86400) d,
			COUNT(DISTINCT CASE WHEN $s THEN $k END) searches,
			COUNT(DISTINCT CASE WHEN $s AND n = 0 THEN $k END) zero,
			SUM(CASE WHEN $c THEN 1 ELSE 0 END) clicks,
			SUM(CASE WHEN src = 'cart' THEN 1 ELSE 0 END) carts,
			COUNT(DISTINCT CASE WHEN src = 'order' THEN oid END) orders,
			SUM(CASE WHEN src = 'order' THEN val ELSE 0 END) revenue
		FROM $l WHERE ts BETWEEN %d AND %d GROUP BY FLOOR((ts + %d) / 86400)",
		$off,
		$from,
		$to,
		$off
	), ARRAY_A );
	$by = array();
	foreach ( (array) $rows as $r ) {
		$by[ (int) $r['d'] ] = $r;
	}
	$out = array();
	for ( $t = $from; $t <= $to; $t += DAY_IN_SECONDS ) {
		$d     = (int) floor( ( $t + $off ) / DAY_IN_SECONDS );
		$r     = $by[ $d ] ?? array();
		$out[] = array(
			'Dzień'          => wp_date( 'Y-m-d', $t ),
			'Wyszukania'     => (int) ( $r['searches'] ?? 0 ),
			'Bez wyników'    => (int) ( $r['zero'] ?? 0 ),
			'Kliknięcia'     => (int) ( $r['clicks'] ?? 0 ),
			'Do koszyka'     => (int) ( $r['carts'] ?? 0 ),
			'Zamówienia'     => (int) ( $r['orders'] ?? 0 ),
			'Sprzedaż (zł)'  => round( (float) ( $r['revenue'] ?? 0 ), 2 ),
		);
	}
	return $out;
}

/** Frazy: wyszukania, sesje, wyniki, kliknięcia, koszyk, zamówienia, sprzedaż, zmiana do poprzedniego okresu. */
function fx_ss_phrases( $per, $limit = 5000 ) {
	global $wpdb;
	$l    = fx_srch_log_table();
	$k    = fx_ss_key();
	$s    = FX_SS_SEARCH;
	$c    = FX_SS_CLICK;
	$sql  = "SELECT qn, MAX(q) q,
			COUNT(DISTINCT CASE WHEN $s THEN $k END) searches,
			COUNT(DISTINCT CASE WHEN $s AND sid <> '' THEN sid END) sessions,
			ROUND(AVG(CASE WHEN $s THEN n END)) results,
			SUM(CASE WHEN $c THEN 1 ELSE 0 END) clicks,
			AVG(CASE WHEN $c AND pos > 0 THEN pos END) pos,
			SUM(CASE WHEN src = 'cart' THEN 1 ELSE 0 END) carts,
			COUNT(DISTINCT CASE WHEN src = 'order' THEN oid END) orders,
			SUM(CASE WHEN src = 'order' THEN val ELSE 0 END) revenue
		FROM $l WHERE ts BETWEEN %d AND %d AND qn <> '' GROUP BY qn";
	$cur  = $wpdb->get_results( $wpdb->prepare( $sql, $per['from'], $per['to'] ), ARRAY_A ); // phpcs:ignore
	$prev = array();
	foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT qn, COUNT(DISTINCT CASE WHEN $s THEN $k END) searches FROM $l WHERE ts BETWEEN %d AND %d AND qn <> '' GROUP BY qn", $per['pfrom'], $per['pto'] ), ARRAY_A ) as $r ) { // phpcs:ignore
		$prev[ $r['qn'] ] = (int) $r['searches'];
	}
	$out = array();
	foreach ( (array) $cur as $r ) {
		$se    = (int) $r['searches'];
		$pv    = $prev[ $r['qn'] ] ?? 0;
		$out[] = array(
			'Fraza'              => $r['q'],
			'Wyszukania'         => $se,
			'Osoby'              => (int) $r['sessions'],
			'Śr. wyników'        => null === $r['results'] ? '' : (int) $r['results'],
			'Kliknięcia'         => (int) $r['clicks'],
			'CTR %'              => $se ? round( 100 * min( 1, $r['clicks'] / $se ) ) : '',
			'Śr. pozycja'        => $r['pos'] ? round( (float) $r['pos'], 1 ) : '',
			'Do koszyka'         => (int) $r['carts'],
			'Zamówienia'         => (int) $r['orders'],
			'Sprzedaż (zł)'      => round( (float) $r['revenue'], 2 ),
			'Poprzedni okres'    => $pv,
			'Zmiana %'           => $pv ? round( 100 * ( $se - $pv ) / $pv ) : ( $se ? 'nowa' : '' ),
		);
	}
	usort( $out, function ( $a, $b ) {
		return $b['Wyszukania'] <=> $a['Wyszukania'] ?: $b['Sprzedaż (zł)'] <=> $a['Sprzedaż (zł)'];
	} );
	return array_slice( $out, 0, $limit );
}

/** Frazy bez wyników + co klienci wpisali zaraz potem (ta sama sesja, do 10 minut, z wynikami). */
function fx_ss_zero( $per, $limit = 5000 ) {
	global $wpdb;
	$l    = fx_srch_log_table();
	$k    = fx_ss_key();
	$s    = FX_SS_SEARCH;
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT qn, MAX(q) q, COUNT(DISTINCT $k) searches, COUNT(DISTINCT CASE WHEN sid <> '' THEN sid END) sessions, MAX(ts) last FROM $l WHERE ts BETWEEN %d AND %d AND $s AND n = 0 AND qn <> '' GROUP BY qn ORDER BY searches DESC LIMIT %d", $per['from'], $per['to'], $limit ), ARRAY_A ); // phpcs:ignore
	$next = array();
	foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT a.qn z, MAX(b.q) nq, COUNT(DISTINCT a.sid) c FROM $l a JOIN $l b ON b.sid = a.sid AND b.ts > a.ts AND b.ts <= a.ts + 600 AND b.n > 0 AND b.src IN ('dd','page') AND b.qn <> a.qn WHERE a.ts BETWEEN %d AND %d AND a.src IN ('dd','page') AND a.n = 0 AND a.sid <> '' GROUP BY a.qn, b.qn ORDER BY c DESC LIMIT 3000", $per['from'], $per['to'] ), ARRAY_A ) as $r ) { // phpcs:ignore
		if ( count( $next[ $r['z'] ] ?? array() ) < 3 ) {
			$next[ $r['z'] ][] = $r['nq'] . ' (' . $r['c'] . ')';
		}
	}
	$map = function_exists( 'fx_srch_map' ) ? fx_srch_map( fx_srch_settings()['map'] ?? '' ) : array();
	$out = array();
	foreach ( (array) $rows as $r ) {
		$out[] = array(
			'Fraza'                  => $r['q'],
			'Wyszukania'             => (int) $r['searches'],
			'Osoby'                  => (int) $r['sessions'],
			'Ostatnio'               => wp_date( 'Y-m-d H:i', (int) $r['last'] ),
			'Potem szukali (osoby)'  => implode( ', ', $next[ $r['qn'] ] ?? array() ),
			'Poprawka'               => $map[ $r['qn'] ] ?? ( isset( $next[ $r['qn'] ][0] ) ? preg_replace( '/ \(\d+\)$/', '', $next[ $r['qn'] ][0] ) : '' ),
		);
	}
	return $out;
}

/** Rosnące i nowe frazy względem poprzedniego okresu. */
function fx_ss_rising( $per, $limit = 5000 ) {
	$rows = array_filter( fx_ss_phrases( $per ), function ( $r ) {
		return $r['Wyszukania'] >= 3 && $r['Wyszukania'] > $r['Poprzedni okres'];
	} );
	usort( $rows, function ( $a, $b ) {
		return ( $b['Wyszukania'] - $b['Poprzedni okres'] ) <=> ( $a['Wyszukania'] - $a['Poprzedni okres'] );
	} );
	$out = array();
	foreach ( array_slice( $rows, 0, $limit ) as $r ) {
		$out[] = array(
			'Fraza'           => $r['Fraza'],
			'Wyszukania'      => $r['Wyszukania'],
			'Poprzedni okres' => $r['Poprzedni okres'],
			'Przyrost'        => $r['Wyszukania'] - $r['Poprzedni okres'],
			'Zmiana %'        => $r['Zmiana %'],
			'Śr. wyników'     => $r['Śr. wyników'],
			'Sprzedaż (zł)'   => $r['Sprzedaż (zł)'],
		);
	}
	return $out;
}

/** Produkty znalezione wyszukiwarką: kliknięcia, pozycja, koszyk, zamówienia, sprzedaż, główna fraza. */
function fx_ss_products( $per, $limit = 5000 ) {
	global $wpdb;
	$l    = fx_srch_log_table();
	$c    = FX_SS_CLICK;
	$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore
		"SELECT pid,
			SUM(CASE WHEN $c THEN 1 ELSE 0 END) clicks,
			AVG(CASE WHEN $c AND pos > 0 THEN pos END) pos,
			SUM(CASE WHEN src = 'cart' THEN 1 ELSE 0 END) carts,
			COUNT(DISTINCT CASE WHEN src = 'order' THEN oid END) orders,
			SUM(CASE WHEN src = 'order' THEN n ELSE 0 END) qty,
			SUM(CASE WHEN src = 'order' THEN val ELSE 0 END) revenue
		FROM $l WHERE ts BETWEEN %d AND %d AND pid > 0 GROUP BY pid ORDER BY revenue DESC, clicks DESC LIMIT %d",
		$per['from'],
		$per['to'],
		$limit
	), ARRAY_A );
	$top = array();
	foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT pid, MAX(q) q, COUNT(*) c FROM $l WHERE ts BETWEEN %d AND %d AND pid > 0 AND $c GROUP BY pid, qn ORDER BY c DESC", $per['from'], $per['to'] ), ARRAY_A ) as $r ) { // phpcs:ignore
		if ( ! isset( $top[ $r['pid'] ] ) ) {
			$top[ $r['pid'] ] = $r['q'];
		}
	}
	$out = array();
	foreach ( (array) $rows as $r ) {
		$p     = wc_get_product( (int) $r['pid'] );
		$out[] = array(
			'ID'              => (int) $r['pid'],
			'Produkt'         => $p ? $p->get_name() : '(usunięty) #' . $r['pid'],
			'SKU'             => $p ? $p->get_sku() : '',
			'Najczęstsza fraza' => $top[ $r['pid'] ] ?? '',
			'Kliknięcia'      => (int) $r['clicks'],
			'Śr. pozycja'     => $r['pos'] ? round( (float) $r['pos'], 1 ) : '',
			'Do koszyka'      => (int) $r['carts'],
			'Zamówienia'      => (int) $r['orders'],
			'Sztuk'           => (int) $r['qty'],
			'Sprzedaż (zł)'   => round( (float) $r['revenue'], 2 ),
		);
	}
	return $out;
}

/** Surowe zdarzenia (eksport). */
function fx_ss_raw( $per ) {
	global $wpdb;
	$names = array( 'dd' => 'wyszukanie (okno)', 'page' => 'wyszukanie (strona wyników)', 'ddc' => 'kliknięcie (okno)', 'pagec' => 'kliknięcie (wyniki)', 'cart' => 'do koszyka', 'order' => 'zamówienie' );
	$out   = array();
	foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . fx_srch_log_table() . ' WHERE ts BETWEEN %d AND %d ORDER BY ts', $per['from'], $per['to'] ), ARRAY_A ) as $r ) { // phpcs:ignore
		$out[] = array(
			'Data'        => wp_date( 'Y-m-d H:i:s', (int) $r['ts'] ),
			'Zdarzenie'   => $names[ $r['src'] ] ?? $r['src'],
			'Fraza'       => $r['q'],
			'Wyniki / szt.' => (int) $r['n'],
			'Produkt ID'  => $r['pid'] ? (int) $r['pid'] : '',
			'Pozycja'     => $r['pos'] ? (int) $r['pos'] : '',
			'Zamówienie'  => $r['oid'] ? (int) $r['oid'] : '',
			'Wartość (zł)' => 'order' === $r['src'] ? (float) $r['val'] : '',
			'Urządzenie'  => 'm' === $r['dev'] ? 'telefon' : ( 'd' === $r['dev'] ? 'komputer' : '' ),
			'Sesja'       => $r['sid'],
		);
	}
	return $out;
}

/* =====================================================================
 * Eksport CSV
 * ===================================================================== */

add_action( 'admin_post_fx_srch_export', function () {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( 'Brak uprawnień' );
	}
	check_admin_referer( 'fx_srch_export' );
	$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'frazy'; // phpcs:ignore
	$per  = fx_ss_period();
	@set_time_limit( 120 ); // phpcs:ignore
	switch ( $view ) {
		case 'dni':
			$rows = fx_ss_days( $per['from'], $per['to'] );
			break;
		case 'bez-wynikow':
			$rows = fx_ss_zero( $per );
			break;
		case 'rosnace':
			$rows = fx_ss_rising( $per );
			break;
		case 'produkty':
			$rows = fx_ss_products( $per );
			break;
		case 'bez-klikniecia':
			$rows = fx_ss_noclick( $per );
			break;
		case 'spadajace':
			$rows = fx_ss_falling( $per );
			break;
		case 'braki':
			$rows = fx_ss_oos( $per );
			break;
		case 'zachowanie':
			$rows = fx_ss_behaviour_rows( $per );
			break;
		case 'miesiace':
			$rows = fx_ss_months( 36 );
			break;
		case 'surowe':
			$rows = fx_ss_raw( $per );
			break;
		default:
			$view = 'frazy';
			$rows = fx_ss_phrases( $per );
	}
	nocache_headers();
	header( 'Content-Type: text/csv; charset=UTF-8' );
	header( 'Content-Disposition: attachment; filename="wyszukiwarka-' . $view . '-' . $per['args']['od'] . '_' . $per['args']['do'] . '.csv"' );
	$out = fopen( 'php://output', 'w' ); // phpcs:ignore
	fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore — polskie znaki w Excelu
	if ( $rows ) {
		fputcsv( $out, array_keys( $rows[0] ), ';' );
		foreach ( $rows as $r ) {
			fputcsv(
				$out,
				array_map( function ( $v ) {
					return is_float( $v ) ? str_replace( '.', ',', (string) $v ) : $v; // przecinek dziesiętny
				}, array_values( $r ) ),
				';'
			);
		}
	} else {
		fputcsv( $out, array( 'Brak danych w okresie' ), ';' );
	}
	fclose( $out ); // phpcs:ignore
	exit;
} );

/* =====================================================================
 * Panel
 * ===================================================================== */

function fx_srch_admin_page() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}
	$tab  = isset( $_GET['tab'] ) && 'ustawienia' === $_GET['tab'] ? 'ustawienia' : 'analityka'; // phpcs:ignore
	$base = admin_url( 'admin.php?page=fx-szukaj' );
	echo '<div class="wrap"><h1>Wyszukiwarka FOX</h1><nav class="nav-tab-wrapper">';
	echo '<a class="nav-tab' . ( 'analityka' === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( $base ) . '">Analityka</a>';
	echo '<a class="nav-tab' . ( 'ustawienia' === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( $base . '&tab=ustawienia' ) . '">Ustawienia i indeks</a></nav>';
	if ( 'ustawienia' === $tab ) {
		fx_srch_settings_page();
	} else {
		fx_ss_page();
	}
	echo '</div>';
}

function fx_ss_num( $v, $dec = 0 ) {
	return number_format_i18n( (float) $v, $dec );
}

/** Zmiana względem poprzedniego okresu: „▲ 12%”. */
function fx_ss_delta( $cur, $prev, $inverse = false ) {
	if ( ! $prev ) {
		return $cur ? '<span class="fx-ss-d">nowe</span>' : '';
	}
	$pct = round( 100 * ( $cur - $prev ) / $prev );
	if ( 0 === (int) $pct ) {
		return '<span class="fx-ss-d">bez zmian</span>';
	}
	$good = $inverse ? $pct < 0 : $pct > 0;
	return '<span class="fx-ss-d ' . ( $good ? 'is-up' : 'is-down' ) . '">' . ( $pct > 0 ? '▲ ' : '▼ ' ) . abs( $pct ) . '%</span>';
}

function fx_ss_page() {
	$per   = fx_ss_period();
	$views = array(
		'podsumowanie' => 'Podsumowanie',
		'frazy'        => 'Frazy',
		'bez-wynikow'  => 'Bez wyników',
		'bez-klikniecia' => 'Bez kliknięcia',
		'rosnace'      => 'Trendy',
		'produkty'     => 'Produkty',
		'braki'        => 'Brakujący towar',
		'zachowanie'   => 'Zachowanie',
	);
	$view  = isset( $_GET['view'] ) && isset( $views[ $_GET['view'] ] ) ? sanitize_key( $_GET['view'] ) : 'podsumowanie'; // phpcs:ignore
	$base  = admin_url( 'admin.php?page=fx-szukaj' );
	$url   = function ( $args ) use ( $base, $per, $view ) {
		return add_query_arg( array_merge( array( 'view' => $view ), $per['args'], $args ), $base );
	};
	$exp   = function ( $v ) use ( $per ) {
		return wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'fx_srch_export', 'view' => $v ), $per['args'] ), admin_url( 'admin-post.php' ) ), 'fx_srch_export' );
	};
	?>
	<div class="fx-ss">
		<div class="fx-ss-bar">
			<div class="fx-ss-periods">
				<?php foreach ( FX_SS_PERIODS as $d ) : ?>
					<a class="button<?php echo (string) $d === $per['args']['p'] ? ' button-primary' : ''; ?>" href="<?php echo esc_url( $url( array( 'p' => $d, 'od' => null, 'do' => null ) ) ); ?>"><?php echo (int) $d; ?> dni</a>
				<?php endforeach; ?>
			</div>
			<form method="get" class="fx-ss-own">
				<input type="hidden" name="page" value="fx-szukaj"><input type="hidden" name="view" value="<?php echo esc_attr( $view ); ?>"><input type="hidden" name="p" value="own">
				<label>od <input type="date" name="od" value="<?php echo esc_attr( $per['args']['od'] ); ?>"></label>
				<label>do <input type="date" name="do" value="<?php echo esc_attr( $per['args']['do'] ); ?>"></label>
				<button class="button<?php echo 'own' === $per['args']['p'] ? ' button-primary' : ''; ?>">Pokaż</button>
			</form>
		</div>
		<p class="fx-ss-meta">Okres: <strong><?php echo esc_html( $per['label'] ); ?></strong> · porównanie z poprzednimi <?php echo (int) $per['days']; ?> dniami (<?php echo esc_html( wp_date( 'd.m', $per['pfrom'] ) . '–' . wp_date( 'd.m.Y', $per['pto'] ) ); ?>)</p>
		<nav class="fx-ss-views">
			<?php foreach ( $views as $k => $label ) : ?>
				<a class="<?php echo $k === $view ? 'is-on' : ''; ?>" href="<?php echo esc_url( add_query_arg( array_merge( array( 'view' => $k ), $per['args'] ), $base ) ); ?>"><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
			<span class="fx-ss-exp">Eksport CSV:
				<?php if ( 'podsumowanie' === $view ) : ?>
					<a href="<?php echo esc_url( $exp( 'dni' ) ); ?>">dzień po dniu</a> ·
					<a href="<?php echo esc_url( $exp( 'miesiace' ) ); ?>">miesiące</a> ·
				<?php elseif ( 'rosnace' === $view ) : ?>
					<a href="<?php echo esc_url( $exp( 'rosnace' ) ); ?>">rosnące</a> ·
					<a href="<?php echo esc_url( $exp( 'spadajace' ) ); ?>">spadające</a> ·
				<?php else : ?>
					<a href="<?php echo esc_url( $exp( $view ) ); ?>">ten raport</a> ·
				<?php endif; ?>
				<a href="<?php echo esc_url( $exp( 'surowe' ) ); ?>">wszystkie zdarzenia</a>
			</span>
		</nav>
		<?php
		switch ( $view ) {
			case 'frazy':
				fx_ss_table( fx_ss_phrases( $per, 300 ), 'Pierwsze 300 fraz według liczby wyszukań – pełna lista w eksporcie CSV.', array( 'Fraza' => 'q' ) );
				break;
			case 'bez-wynikow':
				if ( isset( $_GET['poprawka'] ) ) { // phpcs:ignore
					echo '<div class="notice notice-success"><p>Zapisano: „' . esc_html( sanitize_text_field( wp_unslash( $_GET['poprawka'] ) ) ) . '” pokazuje teraz wyniki dla wybranej frazy (działa od razu w podpowiedziach i na stronie wyników).</p></div>'; // phpcs:ignore
				}
				fx_ss_table( fx_ss_zero( $per, 300 ), '„Potem szukali” – co ci sami klienci wpisali w ciągu 10 minut i dostali wyniki. W kolumnie „Poprawka” wpisz frazę (podpowiadamy najczęstszą) i zapisz – od tej chwili klient wpisujący tę frazę zobaczy wyniki dla poprawionej.', array( 'Poprawka' => 'fix' ) );
				break;
			case 'bez-klikniecia':
				fx_ss_table( fx_ss_noclick( $per, 300 ), 'Klient dostał wyniki, ale w nic nie kliknął. Wysoki odsetek = wyniki nie pasują do tego, czego szukał (kolejność, brak modelu lub rozmiaru, ceny). Sprawdź frazę w sklepie i popraw przypięciem, synonimem albo mnożnikiem.', array( 'Fraza' => 'q' ) );
				break;
			case 'rosnace':
				echo '<h2>Rosnące i nowe</h2>';
				fx_ss_table( fx_ss_rising( $per, 200 ), 'Frazy wyszukiwane częściej niż w poprzednim okresie (min. 3 wyszukania). „nowa” = nie było jej wcześniej. Dobry sygnał, co zamówić u dystrybutora.', array( 'Fraza' => 'q' ) );
				echo '<h2 style="margin-top:24px">Spadające</h2>';
				fx_ss_table( fx_ss_falling( $per, 200 ), 'Frazy wyszukiwane rzadziej niż w poprzednim okresie (min. 3 wyszukania wcześniej) – koniec sezonu albo moda, która mija.', array( 'Fraza' => 'q' ) );
				break;
			case 'braki':
				fx_ss_table( fx_ss_oos( $per, 300 ), 'Frazy pasujące do produktów z katalogu, które są NIEDOSTĘPNE (nie pokazujemy ich w wyszukiwarce). Na górze frazy, przy których klient widzi mało albo nic. Gotowa lista do zamówienia u dystrybutora. Liczone dla 300 najczęstszych fraz okresu, odświeżane co 6 godzin.', array( 'Fraza' => 'q' ) );
				break;
			case 'zachowanie':
				fx_ss_behaviour_view( $per );
				break;
			case 'produkty':
				fx_ss_table( fx_ss_products( $per, 300 ), 'Produkty kliknięte w wyszukiwarce. Koszyk i zamówienia – gdy klient kupił produkt znaleziony wyszukiwarką (do 7 dni od kliknięcia).', array( 'Produkt' => 'pid' ) );
				break;
			default:
				fx_ss_summary_view( $per );
		}
		?>
	</div>
	<style>
		.fx-ss-bar{display:flex;flex-wrap:wrap;gap:10px 24px;align-items:center;margin:16px 0 6px}
		.fx-ss-periods{display:flex;flex-wrap:wrap;gap:6px}
		.fx-ss-own{display:flex;gap:8px;align-items:center}
		.fx-ss-meta{color:#50575e;margin:6px 0 14px}
		.fx-ss-views{display:flex;flex-wrap:wrap;gap:4px 18px;align-items:center;border-bottom:1px solid #dcdcde;margin-bottom:16px}
		.fx-ss-views a{display:inline-block;padding:8px 2px;text-decoration:none;color:#1d2327;border-bottom:3px solid transparent;font-weight:500}
		.fx-ss-views a.is-on{border-color:#f26522}
		.fx-ss-exp{margin-left:auto;color:#50575e}.fx-ss-exp a{border:0!important;padding:0!important;color:#2271b1!important}
		.fx-ss-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;margin-bottom:18px}
		.fx-ss-kpi{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px 14px}
		.fx-ss-kpi b{display:block;font-size:24px;line-height:1.2;margin:4px 0}
		.fx-ss-kpi small{color:#50575e}
		.fx-ss-d{font-size:12px;color:#50575e;margin-left:4px}.fx-ss-d.is-up{color:#008a20}.fx-ss-d.is-down{color:#d63638}
		.fx-ss-chart{display:flex;align-items:flex-end;gap:2px;height:140px;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px 12px 0;margin-bottom:6px}
		.fx-ss-chart span{flex:1;background:#2271b1;min-height:1px;position:relative;border-radius:2px 2px 0 0}
		.fx-ss-chart span i{position:absolute;left:0;right:0;bottom:0;background:#d63638;border-radius:2px 2px 0 0}
		.fx-ss-legend{color:#50575e;font-size:12px;margin-bottom:18px}.fx-ss-legend i{display:inline-block;width:10px;height:10px;border-radius:2px;margin:0 4px 0 10px;vertical-align:-1px}
		.fx-ss table.widefat td,.fx-ss table.widefat th{white-space:nowrap}
		.fx-ss table.widefat td:first-child{white-space:normal;min-width:180px}
		.fx-ss-scroll{overflow:auto;max-height:70vh;border:1px solid #dcdcde}
		.fx-ss-scroll table{border:0}
		.fx-ss-scroll thead th{position:sticky;top:0;background:#f6f7f7;z-index:1}
		.fx-ss-box{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:6px 16px 10px;margin-bottom:16px;min-width:0}
		.fx-ss-box h2{font-size:15px;margin:10px 0}
		.fx-ss-two{display:grid;grid-template-columns:repeat(auto-fit,minmax(420px,1fr));gap:0 16px}
		.fx-ss-svg{width:100%;height:auto;display:block}
		.fx-ss-svg text{font-size:11px;fill:#646970}
		.fx-ss-svg .g{stroke:#f0f0f1}
		.fx-ss-bars .r{display:grid;grid-template-columns:minmax(120px,38%) 1fr auto;gap:10px;align-items:center;margin:5px 0;font-size:13px}
		.fx-ss-bars .b{background:#f0f0f1;border-radius:4px;height:14px;overflow:hidden}
		.fx-ss-bars .b i{display:block;height:100%;border-radius:4px}
		.fx-ss-bars .v{white-space:nowrap}.fx-ss-bars small{color:#646970}
		.fx-ss-heat{border-collapse:separate;border-spacing:2px;width:100%;font-size:11px}
		.fx-ss-heat th{font-weight:400;color:#646970;padding:0 2px}
		.fx-ss-heat td{height:18px;border-radius:3px;min-width:12px}
		.fx-ss-fix{display:flex;gap:6px;white-space:nowrap}.fx-ss-fix input{width:180px}
	</style>
	<?php
}

/** Widok „Zachowanie”. */
function fx_ss_behaviour_view( $per ) {
	$b = fx_ss_behaviour( $per );
	echo '<div class="fx-ss-two">';
	echo '<div class="fx-ss-box"><h2>Skąd wyszukanie</h2>' . fx_ss_bars( $b['via'] ?? array() ) . '<p class="description">Jak klient doszedł do wyników: wpisał w okno, kliknął „Często szukane” albo podpowiedź frazy, „Zobacz wszystkie”, Enter.</p></div>'; // phpcs:ignore
	echo '<div class="fx-ss-box"><h2>Urządzenie</h2>' . fx_ss_bars( $b['dev'] ?? array(), '#8c5ac8' ) . '</div>'; // phpcs:ignore
	echo '<div class="fx-ss-box"><h2>Pozycja klikniętego wyniku</h2>' . fx_ss_bars( $b['pos'] ?? array(), '#00a32a' ) . '<p class="description">Większość kliknięć w pierwsze 4–8 wyników = dobra kolejność. Dużo kliknięć dalej = warto poprawić mnożniki albo przypięcia.</p></div>'; // phpcs:ignore
	echo '<div class="fx-ss-box"><h2>Filtry użyte na wynikach</h2>' . fx_ss_bars( $b['flt'] ?? array(), '#f26522' ) . '<h3>Najczęstsze wartości</h3>' . fx_ss_bars( $b['fval'] ?? array(), '#f26522' ) . '</div>'; // phpcs:ignore
	echo '<div class="fx-ss-box"><h2>Kategorie kliknięte w oknie podpowiedzi</h2>' . fx_ss_bars( $b['cat'] ?? array(), '#2271b1' ) . '</div>'; // phpcs:ignore
	echo '<div class="fx-ss-box"><h2>Kiedy klienci szukają</h2>' . fx_ss_heat( $b['heat'] ) . '<p class="description">Dni tygodnia × godziny (czas polski). Ciemniej = więcej wyszukań – dobre godziny na kampanie i posty.</p></div>'; // phpcs:ignore
	echo '</div>';
}

function fx_ss_summary_view( $per ) {
	$c    = fx_ss_summary( $per['from'], $per['to'] );
	$p    = fx_ss_summary( $per['pfrom'], $per['pto'] );
	$pct  = function ( $a, $b ) {
		return $b ? round( 100 * $a / $b, 1 ) : 0;
	};
	$kpis = array(
		array( 'Wyszukania', fx_ss_num( $c['searches'] ), fx_ss_delta( $c['searches'], $p['searches'] ), 'różnych fraz: ' . fx_ss_num( $c['phrases'] ) ),
		array( 'Osoby szukające', fx_ss_num( $c['sessions'] ), fx_ss_delta( $c['sessions'], $p['sessions'] ), 'telefon ' . $pct( $c['mobile'], $c['mobile'] + $c['desktop'] ) . '% · komputer ' . $pct( $c['desktop'], $c['mobile'] + $c['desktop'] ) . '%' ),
		array( 'Bez wyników', $pct( $c['zero'], $c['searches'] ) . '%', fx_ss_delta( $pct( $c['zero'], $c['searches'] ), $pct( $p['zero'], $p['searches'] ), true ), fx_ss_num( $c['zero'] ) . ' wyszukań' ),
		array( 'Klikają w produkt', $pct( $c['click_sessions'], $c['sessions'] ) . '%', fx_ss_delta( $pct( $c['click_sessions'], $c['sessions'] ), $pct( $p['click_sessions'], $p['sessions'] ) ), 'osób szukających · śr. pozycja klikniętego: ' . ( $c['avg_pos'] ? fx_ss_num( $c['avg_pos'], 1 ) : '—' ) ),
		array( 'Do koszyka', fx_ss_num( $c['carts'] ), fx_ss_delta( $c['carts'], $p['carts'] ), 'produkty znalezione wyszukiwarką' ),
		array( 'Zamówienia', fx_ss_num( $c['orders'] ), fx_ss_delta( $c['orders'], $p['orders'] ), 'z produktem znalezionym wyszukiwarką' ),
		array( 'Sprzedaż z wyszukiwarki', fx_ss_num( $c['revenue'], 2 ) . ' zł', fx_ss_delta( $c['revenue'], $p['revenue'] ), $c['shop_revenue'] ? $pct( $c['revenue'], $c['shop_revenue'] ) . '% sprzedaży sklepu (' . fx_ss_num( $c['shop_revenue'], 0 ) . ' zł)' : 'wartość pozycji z produktami z wyszukiwarki' ),
	);
	echo '<div class="fx-ss-kpis">';
	foreach ( $kpis as $k ) {
		echo '<div class="fx-ss-kpi"><small>' . esc_html( $k[0] ) . '</small><b>' . esc_html( $k[1] ) . wp_kses_post( $k[2] ) . '</b><small>' . esc_html( $k[3] ) . '</small></div>';
	}
	echo '</div>';
	// Wykresy: wyszukania i kliknięcia dzień po dniu (przerywana = poprzedni okres), sprzedaż, 24 miesiące.
	$days  = fx_ss_days( $per['from'], $per['to'] );
	$prevd = fx_ss_days( $per['pfrom'], $per['pto'] );
	$cnt   = count( $days );
	$prev  = function ( $col ) use ( $prevd, $cnt ) {
		return array_slice( array_pad( array_column( $prevd, $col ), $cnt, 0 ), 0, $cnt );
	};
	$lab   = array_map( function ( $d ) {
		return wp_date( 'd.m', strtotime( $d['Dzień'] . ' 12:00' ) );
	}, $days );
	echo '<div class="fx-ss-box"><h2>Wyszukania dzień po dniu</h2>';
	echo fx_ss_svg_line( // phpcs:ignore — SVG zbudowane z esc_*
		$lab,
		array(
			array( 'wyszukania', array_column( $days, 'Wyszukania' ), '#2271b1' ),
			array( 'wyszukania – poprzedni okres', $prev( 'Wyszukania' ), '#2271b1', true ),
			array( 'kliknięcia w produkt', array_column( $days, 'Kliknięcia' ), '#00a32a' ),
			array( 'bez wyników', array_column( $days, 'Bez wyników' ), '#d63638' ),
		)
	);
	echo '</div><div class="fx-ss-two"><div class="fx-ss-box"><h2>Sprzedaż z wyszukiwarki</h2>';
	echo fx_ss_svg_line( $lab, array( array( 'sprzedaż (zł)', array_column( $days, 'Sprzedaż (zł)' ), '#f26522' ), array( 'poprzedni okres', $prev( 'Sprzedaż (zł)' ), '#f26522', true ), array( 'do koszyka (szt., prawa oś)', array_column( $days, 'Do koszyka' ), '#8c5ac8', false, true ) ), 300, ' szt.', 600 ); // phpcs:ignore
	$months = fx_ss_months( 24 );
	echo '</div><div class="fx-ss-box"><h2>Ostatnie 24 miesiące</h2>';
	echo fx_ss_svg_line( array_map( function ( $m ) { return substr( $m['Miesiąc'], 5 ) . '.' . substr( $m['Miesiąc'], 2, 2 ); }, $months ), array( array( 'wyszukania', array_column( $months, 'Wyszukania' ), '#2271b1' ), array( 'sprzedaż (zł, prawa oś)', array_column( $months, 'Sprzedaż (zł)' ), '#f26522', false, true ) ), 300, ' zł', 600 ); // phpcs:ignore
	echo '</div></div>';
	echo '<div class="fx-srch-cards" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px">';
	echo '<div><h2>Top 10 fraz</h2>';
	fx_ss_table( array_map( function ( $r ) {
		return array( 'Fraza' => $r['Fraza'], 'Wyszukania' => $r['Wyszukania'], 'Zmiana %' => $r['Zmiana %'], 'Sprzedaż (zł)' => $r['Sprzedaż (zł)'] );
	}, fx_ss_phrases( $per, 10 ) ), '', array( 'Fraza' => 'q' ), false );
	echo '</div><div><h2>Top 10 bez wyników</h2>';
	fx_ss_table( array_map( function ( $r ) {
		return array( 'Fraza' => $r['Fraza'], 'Wyszukania' => $r['Wyszukania'], 'Potem szukali' => $r['Potem szukali (osoby)'] );
	}, fx_ss_zero( $per, 10 ) ), '', array(), false );
	echo '</div></div>';
	echo '<p class="description" style="margin-top:16px">Wyszukanie liczymy raz na osobę, frazę i 30 minut (okno podpowiedzi + strona wyników = jedno wyszukanie). Osoba = anonimowa sesja przeglądarki, bez danych osobowych. Zdarzenia przechowujemy 400 dni, dzienne podsumowania (wykres 24 miesięcy) – na stałe.</p>';
	echo '<p class="description">Dane dla programu analitycznego (JSON): <code>' . esc_html( rest_url( 'fx/v1/szukaj-raport' ) ) . '?od=RRRR-MM-DD&amp;do=RRRR-MM-DD&amp;klucz=' . esc_html( fx_ss_api_key() ) . '</code></p>';
}

/**
 * Tabela raportu. $links: kolumna => 'q' (link do wyników) albo 'pid' (link do edycji produktu).
 */
function fx_ss_table( $rows, $note = '', $links = array(), $scroll = true ) {
	if ( $note ) {
		echo '<p class="description">' . esc_html( $note ) . '</p>';
	}
	if ( ! $rows ) {
		echo '<p>Brak danych w tym okresie.</p>';
		return;
	}
	echo $scroll ? '<div class="fx-ss-scroll">' : '';
	echo '<table class="widefat striped"><thead><tr>';
	foreach ( array_keys( $rows[0] ) as $h ) {
		if ( 'ID' !== $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
	}
	echo '</tr></thead><tbody>';
	foreach ( $rows as $r ) {
		echo '<tr>';
		foreach ( $r as $k => $v ) {
			if ( 'ID' === $k ) {
				continue;
			}
			$cell = is_float( $v ) ? fx_ss_num( $v, floor( $v ) == $v ? 0 : 2 ) : (string) $v; // phpcs:ignore
			if ( isset( $links[ $k ] ) && 'fix' === $links[ $k ] ) {
				$cell = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="fx-ss-fix">' . wp_nonce_field( 'fx_ss_map', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="fx_ss_map"><input type="hidden" name="from" value="' . esc_attr( $r['Fraza'] ) . '"><input type="text" name="to" value="' . esc_attr( (string) $v ) . '" placeholder="pokaż wyniki dla…"><button class="button button-small">Zapisz</button></form>';
			} elseif ( isset( $links[ $k ] ) && 'q' === $links[ $k ] ) {
				$cell = '<a href="' . esc_url( add_query_arg( array( 's' => $v, 'post_type' => 'product' ), home_url( '/' ) ) ) . '" target="_blank">' . esc_html( $cell ) . '</a>';
			} elseif ( isset( $links[ $k ] ) && 'pid' === $links[ $k ] && ! empty( $r['ID'] ) ) {
				$cell = '<a href="' . esc_url( get_edit_post_link( $r['ID'] ) ) . '">' . esc_html( $cell ) . '</a>';
			} else {
				$cell = esc_html( $cell );
			}
			echo '<td>' . $cell . '</td>'; // phpcs:ignore — zbudowane wyżej z esc_*
		}
		echo '</tr>';
	}
	echo '</tbody></table>';
	echo $scroll ? '</div>' : '';
}

/* =====================================================================
 * Dzienne podsumowania – trzymane na stałe (dziennik zdarzeń: 400 dni)
 * Wykres miesięczny i porównania rok do roku po wygaśnięciu dziennika.
 * ===================================================================== */

function fx_ss_day_table() {
	global $wpdb;
	return $wpdb->prefix . 'fx_search_day';
}

function fx_ss_install() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( 'CREATE TABLE ' . fx_ss_day_table() . " (
		day date NOT NULL,
		qn varchar(100) NOT NULL DEFAULT '',
		q varchar(100) NOT NULL DEFAULT '',
		searches int(10) unsigned NOT NULL DEFAULT 0,
		zero int(10) unsigned NOT NULL DEFAULT 0,
		clicks int(10) unsigned NOT NULL DEFAULT 0,
		carts int(10) unsigned NOT NULL DEFAULT 0,
		orders int(10) unsigned NOT NULL DEFAULT 0,
		revenue decimal(12,2) NOT NULL DEFAULT 0,
		PRIMARY KEY  (day,qn)
	) " . $wpdb->get_charset_collate() . ';' );
}

/** Podsumowuje zakończone dni (od ostatniego podsumowanego do wczoraj), najwyżej $max dni naraz. */
function fx_ss_aggregate( $max = 15 ) {
	global $wpdb;
	$t   = fx_ss_day_table();
	$l   = fx_srch_log_table();
	$tz  = wp_timezone();
	$y   = new DateTimeImmutable( 'yesterday', $tz );
	$last = (string) get_option( 'fx_ss_agg_last', '' );
	if ( '' === $last ) {
		$first = (int) $wpdb->get_var( "SELECT MIN(ts) FROM $l" ); // phpcs:ignore
		if ( ! $first ) {
			return;
		}
		$day = ( new DateTimeImmutable( '@' . $first ) )->setTimezone( $tz )->setTime( 0, 0 );
	} else {
		$day = ( new DateTimeImmutable( $last, $tz ) )->modify( '+1 day' );
	}
	$k = fx_ss_key();
	$s = FX_SS_SEARCH;
	$c = FX_SS_CLICK;
	for ( $i = 0; $i < $max && $day <= $y; $i++, $day = $day->modify( '+1 day' ) ) {
		$from = $day->getTimestamp();
		$to   = $day->modify( '+1 day' )->getTimestamp() - 1;
		$d    = $day->format( 'Y-m-d' );
		$wpdb->delete( $t, array( 'day' => $d ) ); // phpcs:ignore
		$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore
			"SELECT qn, MAX(q) q,
				COUNT(DISTINCT CASE WHEN $s THEN $k END) searches,
				COUNT(DISTINCT CASE WHEN $s AND n = 0 THEN $k END) zero,
				SUM(CASE WHEN $c THEN 1 ELSE 0 END) clicks,
				SUM(CASE WHEN src = 'cart' THEN 1 ELSE 0 END) carts,
				COUNT(DISTINCT CASE WHEN src = 'order' THEN oid END) orders,
				SUM(CASE WHEN src = 'order' THEN val ELSE 0 END) revenue
			FROM $l WHERE ts BETWEEN %d AND %d AND qn <> '' GROUP BY qn",
			$from,
			$to
		), ARRAY_A );
		foreach ( (array) $rows as $r ) {
			$wpdb->insert( $t, array( 'day' => $d, 'qn' => $r['qn'], 'q' => $r['q'], 'searches' => (int) $r['searches'], 'zero' => (int) $r['zero'], 'clicks' => (int) $r['clicks'], 'carts' => (int) $r['carts'], 'orders' => (int) $r['orders'], 'revenue' => (float) $r['revenue'] ) ); // phpcs:ignore
		}
		update_option( 'fx_ss_agg_last', $d, false );
	}
}
add_action( 'fx_srch_cron', function () {
	if ( (string) get_option( 'fx_ss_agg_last' ) !== wp_date( 'Y-m-d', time() - DAY_IN_SECONDS ) ) {
		fx_ss_aggregate();
	}
}, 20 );

/** Miesiące (z podsumowań dziennych): [ 'Miesiąc', 'Wyszukania', 'Bez wyników', 'Kliknięcia', 'Zamówienia', 'Sprzedaż (zł)' ]. */
function fx_ss_months( $n = 24 ) {
	global $wpdb;
	$t    = fx_ss_day_table();
	$from = ( new DateTimeImmutable( 'first day of this month', wp_timezone() ) )->modify( '-' . ( $n - 1 ) . ' months' )->format( 'Y-m-01' );
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT SUBSTR(day,1,7) m, SUM(searches) s, SUM(zero) z, SUM(clicks) c, SUM(orders) o, SUM(revenue) r FROM $t WHERE day >= %s GROUP BY SUBSTR(day,1,7)", $from ), ARRAY_A ); // phpcs:ignore
	$by   = array();
	foreach ( (array) $rows as $r ) {
		$by[ $r['m'] ] = $r;
	}
	// Bieżący miesiąc: dni jeszcze niepodsumowane (dziś, a gdy automat nie zdążył – też wcześniejsze) z dziennika.
	$cur   = wp_date( 'Y-m' );
	$mon   = ( new DateTimeImmutable( 'first day of this month', wp_timezone() ) )->setTime( 0, 0 );
	$last  = (string) get_option( 'fx_ss_agg_last', '' );
	$since = $last ? ( new DateTimeImmutable( $last, wp_timezone() ) )->modify( '+1 day' ) : $mon;
	$tod   = fx_ss_summary( max( $mon->getTimestamp(), $since->getTimestamp() ), time() );
	$out  = array();
	$m    = new DateTimeImmutable( $from, wp_timezone() );
	for ( $i = 0; $i < $n; $i++, $m = $m->modify( '+1 month' ) ) {
		$k     = $m->format( 'Y-m' );
		$r     = $by[ $k ] ?? array();
		$extra = $k === $cur ? $tod : array();
		$out[] = array(
			'Miesiąc'       => $k,
			'Wyszukania'    => (int) ( $r['s'] ?? 0 ) + (int) ( $extra['searches'] ?? 0 ),
			'Bez wyników'   => (int) ( $r['z'] ?? 0 ) + (int) ( $extra['zero'] ?? 0 ),
			'Kliknięcia'    => (int) ( $r['c'] ?? 0 ) + (int) ( $extra['clicks'] ?? 0 ),
			'Zamówienia'    => (int) ( $r['o'] ?? 0 ) + (int) ( $extra['orders'] ?? 0 ),
			'Sprzedaż (zł)' => round( (float) ( $r['r'] ?? 0 ) + (float) ( $extra['revenue'] ?? 0 ), 2 ),
		);
	}
	return $out;
}

/* =====================================================================
 * Nowe raporty
 * ===================================================================== */

/** Spadające frazy (min. 3 wyszukania w poprzednim okresie). */
function fx_ss_falling( $per, $limit = 5000 ) {
	global $wpdb;
	$l   = fx_srch_log_table();
	$k   = fx_ss_key();
	$s   = FX_SS_SEARCH;
	$cur = array();
	foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT qn, COUNT(DISTINCT CASE WHEN $s THEN $k END) searches FROM $l WHERE ts BETWEEN %d AND %d AND qn <> '' GROUP BY qn", $per['from'], $per['to'] ), ARRAY_A ) as $r ) { // phpcs:ignore
		$cur[ $r['qn'] ] = (int) $r['searches'];
	}
	$out = array();
	foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT qn, MAX(q) q, COUNT(DISTINCT CASE WHEN $s THEN $k END) searches FROM $l WHERE ts BETWEEN %d AND %d AND qn <> '' GROUP BY qn", $per['pfrom'], $per['pto'] ), ARRAY_A ) as $r ) { // phpcs:ignore
		$pv = (int) $r['searches'];
		$cv = $cur[ $r['qn'] ] ?? 0;
		if ( $pv >= 3 && $cv < $pv ) {
			$out[] = array( 'Fraza' => $r['q'], 'Wyszukania' => $cv, 'Poprzedni okres' => $pv, 'Spadek' => $pv - $cv, 'Zmiana %' => round( 100 * ( $cv - $pv ) / $pv ) );
		}
	}
	usort( $out, function ( $a, $b ) {
		return $b['Spadek'] <=> $a['Spadek'];
	} );
	return array_slice( $out, 0, $limit );
}

/** Wyszukania z wynikami, po których klient nic nie kliknął. */
function fx_ss_noclick( $per, $limit = 5000 ) {
	global $wpdb;
	$l    = fx_srch_log_table();
	$k    = fx_ss_key();
	$s    = FX_SS_SEARCH;
	$c    = FX_SS_CLICK;
	$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore
		"SELECT qn, MAX(q) q,
			COUNT(DISTINCT CASE WHEN $s AND n > 0 THEN $k END) searches,
			COUNT(DISTINCT CASE WHEN $c THEN $k END) clicked,
			ROUND(AVG(CASE WHEN $s AND n > 0 THEN n END)) results
		FROM $l WHERE ts BETWEEN %d AND %d AND qn <> '' GROUP BY qn",
		$per['from'],
		$per['to']
	), ARRAY_A );
	$out = array();
	foreach ( (array) $rows as $r ) {
		$se = (int) $r['searches'];
		$no = max( 0, $se - (int) $r['clicked'] );
		if ( $se >= 2 && $no > 0 ) {
			$out[] = array( 'Fraza' => $r['q'], 'Wyszukania z wynikami' => $se, 'Bez kliknięcia' => $no, 'Bez kliknięcia %' => round( 100 * $no / $se ), 'Śr. wyników' => (int) $r['results'] );
		}
	}
	usort( $out, function ( $a, $b ) {
		return $b['Bez kliknięcia'] <=> $a['Bez kliknięcia'] ?: $b['Bez kliknięcia %'] <=> $a['Bez kliknięcia %'];
	} );
	return array_slice( $out, 0, $limit );
}

/**
 * Popyt na brakujący towar: frazy pasujące do produktów z katalogu, które są niedostępne
 * (w wyszukiwarce ich nie ma, więc klient ich nie widzi). Gotowa lista do zamówienia u dystrybutora.
 * Liczone na żądanie dla 300 najczęstszych fraz okresu; pamięć 6 h.
 */
function fx_ss_oos( $per, $limit = 5000 ) {
	$key = 'fx_ss_oos_' . md5( $per['from'] . '|' . $per['to'] );
	$hit = get_transient( $key );
	if ( is_array( $hit ) ) {
		return array_slice( $hit, 0, $limit );
	}
	global $wpdb;
	@set_time_limit( 120 ); // phpcs:ignore
	$lk  = $wpdb->prefix . 'wc_product_meta_lookup';
	$cat = $wpdb->get_results( "SELECT p.ID id, p.post_title t, l.sku sku, l.total_sales ts FROM {$wpdb->posts} p JOIN $lk l ON l.product_id = p.ID WHERE p.post_type = 'product' AND p.post_status = 'publish' AND l.stock_status = 'outofstock'", ARRAY_A ); // phpcs:ignore
	$items = array();
	foreach ( (array) $cat as $r ) {
		$tw      = explode( ' ', fx_srch_norm( $r['t'] ) );
		$xw      = array_filter( array( fx_srch_norm( $r['sku'] ), preg_replace( '/[^0-9a-z]/i', '', strtolower( (string) $r['sku'] ) ) ) );
		$items[] = array( $r, $tw, $xw, implode( ' ', $tw ) . ' ' . implode( ' ', $xw ) );
	}
	$top = array_slice( fx_ss_phrases( $per ), 0, 300 );
	$out = array();
	foreach ( $top as $ph ) {
		if ( $ph['Wyszukania'] < 2 ) {
			continue;
		}
		$tk = fx_srch_tokens( $ph['Fraza'] );
		if ( ! $tk['tokens'] ) {
			continue;
		}
		$found = array();
		foreach ( $items as $it ) {
			$ok = true;
			foreach ( $tk['tokens'] as $alts ) {
				$maybe = false;
				foreach ( $alts as $a ) {
					$st = fx_srch_stem( $a );
					if ( false !== strpos( $it[3], $st ? $st : $a ) ) {
						$maybe = true;
						break;
					}
				}
				if ( ! $maybe || fx_srch_tok_score( $alts, $it[1], $it[2] ) <= 0 ) {
					$ok = false;
					break;
				}
			}
			if ( $ok ) {
				$found[] = $it[0];
			}
		}
		if ( ! $found ) {
			continue;
		}
		usort( $found, function ( $a, $b ) {
			return (int) $b['ts'] <=> (int) $a['ts'];
		} );
		$out[] = array(
			'Fraza'                  => $ph['Fraza'],
			'Wyszukania'             => $ph['Wyszukania'],
			'Śr. wyników dostępnych' => $ph['Śr. wyników'],
			'Niedostępne pasujące'   => count( $found ),
			'Najlepiej sprzedawane z nich' => implode( ' · ', array_map( function ( $r ) {
				return $r['t'] . ( $r['sku'] ? ' (' . $r['sku'] . ')' : '' );
			}, array_slice( $found, 0, 3 ) ) ),
		);
	}
	usort( $out, function ( $a, $b ) {
		// Najpierw frazy, przy których klient widzi mało albo nic, potem według popytu.
		$wa = $a['Wyszukania'] * ( (int) $a['Śr. wyników dostępnych'] <= 2 ? 3 : 1 );
		$wb = $b['Wyszukania'] * ( (int) $b['Śr. wyników dostępnych'] <= 2 ? 3 : 1 );
		return $wb <=> $wa;
	} );
	set_transient( $key, $out, 6 * HOUR_IN_SECONDS );
	return array_slice( $out, 0, $limit );
}

/** Zachowanie: źródła, urządzenia, pozycje kliknięć, filtry, kategorie z okna, godziny × dni tygodnia. */
function fx_ss_behaviour( $per ) {
	global $wpdb;
	$l   = fx_srch_log_table();
	$k   = fx_ss_key();
	$s   = FX_SS_SEARCH;
	$c   = FX_SS_CLICK;
	$out = array();
	$via = array( 'typed' => 'wpisane w okno', 'pop' => '„Często szukane”', 'phr' => 'podpowiedź frazy', 'all' => '„Zobacz wszystkie”', 'enter' => 'Enter / przycisk Szukaj', 'link' => 'link z zewnątrz / zakładka' );
	foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT via, COUNT(*) n FROM $l WHERE ts BETWEEN %d AND %d AND $s AND via <> '' GROUP BY via ORDER BY n DESC", $per['from'], $per['to'] ), ARRAY_A ) as $r ) { // phpcs:ignore
		$out['via'][ $via[ $r['via'] ] ?? $r['via'] ] = (int) $r['n'];
	}
	foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT dev, COUNT(DISTINCT $k) n FROM $l WHERE ts BETWEEN %d AND %d AND $s AND dev <> '' GROUP BY dev", $per['from'], $per['to'] ), ARRAY_A ) as $r ) { // phpcs:ignore
		$out['dev'][ 'm' === $r['dev'] ? 'telefon' : 'komputer' ] = (int) $r['n'];
	}
	$pos = array();
	foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT pos, src, COUNT(*) n FROM $l WHERE ts BETWEEN %d AND %d AND $c AND pos > 0 GROUP BY pos, src", $per['from'], $per['to'] ), ARRAY_A ) as $r ) { // phpcs:ignore
		$p             = (int) $r['pos'] > 12 ? '13+' : (string) (int) $r['pos'];
		$pos[ $p ]     = ( $pos[ $p ] ?? 0 ) + (int) $r['n'];
	}
	uksort( $pos, function ( $a, $b ) {
		return (int) $a <=> (int) $b;
	} );
	$out['pos'] = $pos;
	$names      = array( 'rozmiar' => 'rozmiar', 'kolor' => 'kolor', 'promocja' => 'tylko promocje', 'wysylka24' => 'wysyłka dziś', 'wsklepie' => 'w sklepie stacjonarnym', 'przedsprzedaz' => 'przedsprzedaż', 'orderby' => 'sortowanie' );
	$flt        = array();
	$fval       = array();
	foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT x FROM $l WHERE ts BETWEEN %d AND %d AND src = 'flt'", $per['from'], $per['to'] ) ) as $x ) { // phpcs:ignore
		foreach ( explode( '|', (string) $x ) as $pair ) {
			$kv = explode( '=', $pair, 2 );
			if ( 2 !== count( $kv ) ) {
				continue;
			}
			$n           = $names[ $kv[0] ] ?? $kv[0];
			$flt[ $n ]   = ( $flt[ $n ] ?? 0 ) + 1;
			if ( in_array( $kv[0], array( 'rozmiar', 'kolor', 'orderby' ), true ) ) {
				foreach ( explode( ',', $kv[1] ) as $v ) {
					$ord            = array( 'price' => 'cena rosnąco', 'price-desc' => 'cena malejąco', 'date' => 'najnowsze', 'popularity' => 'popularne', 'rating' => 'oceny', 'menu_order' => 'domyślne', 'relevance' => 'trafność' );
					$label          = $n . ': ' . ( 'rozmiar' === $kv[0] ? strtoupper( $v ) : ( 'orderby' === $kv[0] ? ( $ord[ $v ] ?? $v ) : $v ) );
					$fval[ $label ] = ( $fval[ $label ] ?? 0 ) + 1;
				}
			}
		}
	}
	arsort( $flt );
	arsort( $fval );
	$out['flt']  = $flt;
	$out['fval'] = array_slice( $fval, 0, 20, true );
	foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT x, COUNT(*) n FROM $l WHERE ts BETWEEN %d AND %d AND src = 'cat' AND x <> '' GROUP BY x ORDER BY n DESC LIMIT 20", $per['from'], $per['to'] ), ARRAY_A ) as $r ) { // phpcs:ignore
		$out['cat'][ $r['x'] ] = (int) $r['n'];
	}
	// Godziny × dni tygodnia (czas polski).
	$off  = (int) wp_timezone()->getOffset( new DateTime( '@' . $per['from'] ) );
	$heat = array_fill( 0, 7, array_fill( 0, 24, 0 ) );
	foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT FLOOR((ts + %d) / 3600) h, COUNT(DISTINCT $k) n FROM $l WHERE ts BETWEEN %d AND %d AND $s GROUP BY FLOOR((ts + %d) / 3600)", $off, $per['from'], $per['to'], $off ), ARRAY_A ) as $r ) { // phpcs:ignore
		$ts                  = (int) $r['h'] * 3600;
		$dow                 = ( (int) gmdate( 'N', $ts ) ) - 1; // 0 = poniedziałek
		$heat[ $dow ][ (int) gmdate( 'G', $ts ) ] += (int) $r['n'];
	}
	$out['heat'] = $heat;
	return $out;
}

/** Zachowanie jako tabela (eksport CSV). */
function fx_ss_behaviour_rows( $per ) {
	$b    = fx_ss_behaviour( $per );
	$rows = array();
	$map  = array( 'via' => 'Źródło wyszukania', 'dev' => 'Urządzenie', 'pos' => 'Pozycja klikniętego wyniku', 'flt' => 'Filtr na wynikach', 'fval' => 'Wartość filtra', 'cat' => 'Kategoria kliknięta w oknie' );
	foreach ( $map as $k => $label ) {
		foreach ( (array) ( $b[ $k ] ?? array() ) as $name => $n ) {
			$rows[] = array( 'Raport' => $label, 'Pozycja' => (string) $name, 'Liczba' => (int) $n );
		}
	}
	$days = array( 'pon', 'wt', 'śr', 'czw', 'pt', 'sob', 'nd' );
	foreach ( $b['heat'] as $d => $hours ) {
		foreach ( $hours as $h => $n ) {
			if ( $n ) {
				$rows[] = array( 'Raport' => 'Wyszukania wg dnia i godziny', 'Pozycja' => $days[ $d ] . ' ' . $h . ':00', 'Liczba' => $n );
			}
		}
	}
	return $rows;
}

/* =====================================================================
 * Wykresy (SVG rysowane na serwerze – bez zewnętrznych bibliotek)
 * ===================================================================== */

/**
 * Wykres liniowy. $series: [ [ 'name', [wartości], '#kolor', przerywana(bool), oś prawa(bool) ] ].
 */
function fx_ss_svg_line( $labels, $series, $h = 240, $fmt_right = '', $w = 960 ) {
	$pl   = 46;
	$pr   = $fmt_right ? 64 : 16;
	$pt   = 14;
	$pb   = 30;
	$n    = max( 1, count( $labels ) );
	$maxl = 1;
	$maxr = 1;
	foreach ( $series as $se ) {
		$m = $se[1] ? max( $se[1] ) : 0;
		if ( ! empty( $se[4] ) ) {
			$maxr = max( $maxr, $m );
		} else {
			$maxl = max( $maxl, $m );
		}
	}
	$nice = function ( $v ) {
		$p = pow( 10, floor( log10( max( 1, $v ) ) ) );
		foreach ( array( 1, 2, 2.5, 5, 10 ) as $m ) {
			if ( $v <= $m * $p ) {
				return $m * $p;
			}
		}
		return 10 * $p;
	};
	$maxl = $nice( $maxl );
	$maxr = $nice( $maxr );
	$x    = function ( $i ) use ( $pl, $pr, $w, $n ) {
		return $n > 1 ? $pl + $i * ( $w - $pl - $pr ) / ( $n - 1 ) : $pl + ( $w - $pl - $pr ) / 2;
	};
	$y    = function ( $v, $right ) use ( $pt, $pb, $h, $maxl, $maxr ) {
		return $pt + ( $h - $pt - $pb ) * ( 1 - $v / ( $right ? $maxr : $maxl ) );
	};
	$svg  = '<svg class="fx-ss-svg" viewBox="0 0 ' . $w . ' ' . $h . '" role="img">';
	for ( $g = 0; $g <= 4; $g++ ) {
		$gy   = $pt + ( $h - $pt - $pb ) * $g / 4;
		$svg .= '<line x1="' . $pl . '" x2="' . ( $w - $pr ) . '" y1="' . round( $gy, 1 ) . '" y2="' . round( $gy, 1 ) . '" class="g"/>';
		$svg .= '<text x="' . ( $pl - 6 ) . '" y="' . round( $gy + 4, 1 ) . '" text-anchor="end">' . esc_html( number_format_i18n( $maxl * ( 4 - $g ) / 4 ) ) . '</text>';
		if ( $fmt_right ) {
			$svg .= '<text x="' . ( $w - $pr + 6 ) . '" y="' . round( $gy + 4, 1 ) . '">' . esc_html( number_format_i18n( $maxr * ( 4 - $g ) / 4 ) . $fmt_right ) . '</text>';
		}
	}
	$step = max( 1, (int) ceil( $n / 12 ) );
	foreach ( $labels as $i => $lab ) {
		// Co $step etykieta; ostatnia tylko, gdy nie nachodzi na poprzednią.
		if ( ( 0 === $i % $step && $n - 1 - $i >= $step / 2 ) || $i === $n - 1 ) {
			$svg .= '<text x="' . round( $x( $i ), 1 ) . '" y="' . ( $h - 8 ) . '" text-anchor="middle">' . esc_html( $lab ) . '</text>';
		}
	}
	foreach ( $series as $se ) {
		$pts = array();
		foreach ( array_values( $se[1] ) as $i => $v ) {
			$pts[] = round( $x( $i ), 1 ) . ',' . round( $y( (float) $v, ! empty( $se[4] ) ), 1 );
		}
		if ( ! $pts ) {
			continue;
		}
		$svg .= '<polyline fill="none" stroke="' . esc_attr( $se[2] ) . '" stroke-width="2.2" stroke-linejoin="round" ' . ( ! empty( $se[3] ) ? 'stroke-dasharray="5 4" opacity=".7"' : '' ) . ' points="' . implode( ' ', $pts ) . '"/>';
		if ( count( $pts ) <= 60 && empty( $se[3] ) ) {
			foreach ( array_values( $se[1] ) as $i => $v ) {
				$svg .= '<circle cx="' . round( $x( $i ), 1 ) . '" cy="' . round( $y( (float) $v, ! empty( $se[4] ) ), 1 ) . '" r="3" fill="' . esc_attr( $se[2] ) . '"><title>' . esc_html( $labels[ $i ] . ' – ' . $se[0] . ': ' . number_format_i18n( (float) $v, floor( $v ) == $v ? 0 : 2 ) ) . '</title></circle>'; // phpcs:ignore
			}
		}
	}
	$svg .= '</svg><p class="fx-ss-legend">';
	foreach ( $series as $se ) {
		$svg .= '<i style="background:' . esc_attr( $se[2] ) . ( ! empty( $se[3] ) ? ';opacity:.6' : '' ) . '"></i>' . esc_html( $se[0] ) . ' ';
	}
	return $svg . '</p>';
}

/** Słupki poziome: [ etykieta => liczba ]. */
function fx_ss_bars( $data, $color = '#2271b1', $suffix = '' ) {
	if ( ! $data ) {
		return '<p class="description">Brak danych w tym okresie.</p>';
	}
	$max   = max( 1, max( $data ) );
	$total = max( 1, array_sum( $data ) );
	$h     = '<div class="fx-ss-bars">';
	foreach ( $data as $k => $v ) {
		$h .= '<div class="r"><span class="l">' . esc_html( (string) $k ) . '</span><span class="b"><i style="width:' . round( 100 * $v / $max, 1 ) . '%;background:' . esc_attr( $color ) . '"></i></span><span class="v">' . esc_html( number_format_i18n( $v ) . $suffix ) . ' <small>' . round( 100 * $v / $total ) . '%</small></span></div>';
	}
	return $h . '</div>';
}

/** Mapa cieplna: dni tygodnia × godziny. */
function fx_ss_heat( $heat ) {
	$max  = 1;
	foreach ( $heat as $row ) {
		$max = max( $max, max( $row ) );
	}
	$days = array( 'pon', 'wt', 'śr', 'czw', 'pt', 'sob', 'nd' );
	$h    = '<table class="fx-ss-heat"><thead><tr><th></th>';
	for ( $i = 0; $i < 24; $i++ ) {
		$h .= '<th>' . $i . '</th>';
	}
	$h .= '</tr></thead><tbody>';
	foreach ( $heat as $d => $row ) {
		$h .= '<tr><th>' . $days[ $d ] . '</th>';
		foreach ( $row as $hr => $v ) {
			$a  = $v ? 0.08 + 0.92 * $v / $max : 0;
			$h .= '<td style="background:rgba(34,113,177,' . round( $a, 2 ) . ')" title="' . esc_attr( $days[ $d ] . ' ' . $hr . ':00 – ' . $v . ' wyszukań' ) . '"></td>';
		}
		$h .= '</tr>';
	}
	return $h . '</tbody></table>';
}

/* =====================================================================
 * Poprawka frazy jednym kliknięciem (Bez wyników → „Pokaż wyniki dla…”)
 * ===================================================================== */

add_action( 'admin_post_fx_ss_map', function () {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( 'Brak uprawnień' );
	}
	check_admin_referer( 'fx_ss_map' );
	$from = trim( sanitize_text_field( wp_unslash( $_POST['from'] ?? '' ) ) ); // phpcs:ignore
	$to   = trim( sanitize_text_field( wp_unslash( $_POST['to'] ?? '' ) ) ); // phpcs:ignore
	if ( '' !== $from && '' !== $to && fx_srch_norm( $from ) !== fx_srch_norm( $to ) ) {
		$s     = get_option( FX_SRCH_OPT, array() );
		$s     = is_array( $s ) ? $s : array();
		$lines = array_filter( preg_split( '/\r\n|\r|\n/', (string) ( $s['map'] ?? '' ) ), function ( $l ) use ( $from ) {
			$p = explode( '=>', $l, 2 );
			return '' !== trim( $l ) && fx_srch_norm( $p[0] ) !== fx_srch_norm( $from );
		} );
		$lines[]  = $from . ' => ' . $to;
		$s['map'] = implode( "\n", $lines );
		update_option( FX_SRCH_OPT, $s, false );
		if ( function_exists( 'fx_srch_publish' ) && fx_srch_file() ) {
			fx_srch_publish();
		}
	}
	$back = wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=fx-szukaj&view=bez-wynikow' );
	wp_safe_redirect( add_query_arg( 'poprawka', rawurlencode( $from ), $back ) );
	exit;
} );

/* =====================================================================
 * Dane dla programu analitycznego (JSON): /wp-json/fx/v1/szukaj-raport?od=…&do=…&klucz=…
 * ===================================================================== */

function fx_ss_api_key() {
	$k = (string) get_option( 'fx_ss_api_key' );
	if ( '' === $k ) {
		$k = wp_generate_password( 32, false );
		update_option( 'fx_ss_api_key', $k, false );
	}
	return $k;
}

add_action( 'rest_api_init', function () {
	register_rest_route(
		'fx/v1',
		'/szukaj-raport',
		array(
			'methods'             => 'GET',
			'permission_callback' => function ( WP_REST_Request $r ) {
				return current_user_can( 'manage_woocommerce' ) || hash_equals( fx_ss_api_key(), (string) $r->get_param( 'klucz' ) );
			},
			'callback'            => function ( WP_REST_Request $r ) {
				$_GET['p']  = 'own';
				$_GET['od'] = (string) $r->get_param( 'od' );
				$_GET['do'] = (string) $r->get_param( 'do' );
				if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $_GET['od'] ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $_GET['do'] ) ) {
					$_GET['p'] = '30';
				}
				$per = fx_ss_period();
				return array(
					'okres'          => array( 'od' => $per['args']['od'], 'do' => $per['args']['do'] ),
					'podsumowanie'   => fx_ss_summary( $per['from'], $per['to'] ),
					'poprzedni'      => fx_ss_summary( $per['pfrom'], $per['pto'] ),
					'dni'            => fx_ss_days( $per['from'], $per['to'] ),
					'frazy'          => fx_ss_phrases( $per, 500 ),
					'bez_wynikow'    => fx_ss_zero( $per, 200 ),
					'bez_klikniecia' => fx_ss_noclick( $per, 200 ),
					'produkty'       => fx_ss_products( $per, 300 ),
					'braki'          => fx_ss_oos( $per, 200 ),
				);
			},
		)
	);
} );
