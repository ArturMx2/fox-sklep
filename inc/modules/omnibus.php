<?php
/**
 * Omnibus: najniższa cena z 30 dni przed obniżką.
 *
 * Historia cen: tabela {prefix}fx_price_log – wiersz przy każdej zmianie ceny (produkt prosty albo wariant):
 * - od razu, gdy zmienia się zapisana cena (_price) – panel, import, BaseLinker, REST;
 * - skanem w tle, gdy zmienia się kampania z modułu Przeceny (start, koniec, zmiana procentów) – te ceny
 *   nie są zapisywane w bazie, więc skan zapisuje cenę faktycznie widoczną w sklepie;
 * - pełnym skanem raz w tygodniu (zabezpieczenie na zmiany wprost w bazie).
 *
 * Cena odniesienia = najniższa cena z 30 dni przed początkiem obecnej obniżki
 * (początek = pierwsza zapisana cena niższa od regularnej w nieprzerwanym ciągu obniżek).
 * Brak historii (np. tuż po wdrożeniu) – cena regularna.
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

define( 'FX_OMNI_DB', 1 );

function fx_omni_table() {
	global $wpdb;
	return $wpdb->prefix . 'fx_price_log';
}

add_action( 'init', function () {
	if ( (int) get_option( 'fx_omni_db' ) === FX_OMNI_DB ) {
		return;
	}
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$t = fx_omni_table();
	dbDelta( "CREATE TABLE {$t} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		pid BIGINT UNSIGNED NOT NULL,
		price DECIMAL(12,2) NOT NULL,
		ts INT UNSIGNED NOT NULL,
		PRIMARY KEY  (id),
		KEY pid_ts (pid, ts)
	) {$wpdb->get_charset_collate()};" );
	update_option( 'fx_omni_db', FX_OMNI_DB, false );
	fx_omni_scan_start( 'start' ); // pierwsze zapisanie cen wszystkich produktów
} );

/* =====================================================================
 * Zapis historii
 * ===================================================================== */

/** Zapisuje cenę, gdy różni się od ostatnio zapisanej. */
function fx_omni_log( $pid, $price, $ts = 0 ) {
	global $wpdb;
	$pid   = (int) $pid;
	$price = round( (float) $price, 2 );
	if ( $pid <= 0 || $price <= 0 ) {
		return;
	}
	$t    = fx_omni_table();
	$last = $wpdb->get_var( $wpdb->prepare( "SELECT price FROM {$t} WHERE pid = %d ORDER BY ts DESC, id DESC LIMIT 1", $pid ) ); // phpcs:ignore
	if ( null !== $last && abs( (float) $last - $price ) < 0.005 ) {
		return;
	}
	$wpdb->insert( $t, array( 'pid' => $pid, 'price' => $price, 'ts' => $ts ? (int) $ts : time() ), array( '%d', '%f', '%d' ) );
	wp_cache_delete( 'fx_omni_' . $pid, 'fx_omni' );
}

/** Cena widoczna w sklepie (z kampanią z Przecen, bez cen koszykowych). */
function fx_omni_live_price( WC_Product $p ) {
	return (float) $p->get_price();
}

// Zmiana zapisanej ceny – od razu.
$fx_omni_meta = function ( $meta_id, $post_id, $key, $value ) {
	if ( '_price' !== $key || is_array( $value ) ) {
		return;
	}
	$type = get_post_type( $post_id );
	if ( 'product_variation' !== $type && ( 'product' !== $type || ! has_term( 'simple', 'product_type', $post_id ) ) ) {
		return;
	}
	// Po zapisie w kolejce – gdy obiekt produktu jest już aktualny (także kampania z Przecen).
	$GLOBALS['fx_omni_dirty'][ (int) $post_id ] = 1;
};
add_action( 'added_post_meta', $fx_omni_meta, 10, 4 );
add_action( 'updated_post_meta', $fx_omni_meta, 10, 4 );
add_action( 'shutdown', function () {
	if ( empty( $GLOBALS['fx_omni_dirty'] ) ) {
		return;
	}
	foreach ( array_keys( $GLOBALS['fx_omni_dirty'] ) as $id ) {
		$p = wc_get_product( $id );
		if ( $p ) {
			fx_omni_log( $id, fx_omni_live_price( $p ) );
		}
	}
} );

/* ---------- Skan w tle ---------- */

/** Podpis kampanii z Przecen – gdy się zmieni, ceny w sklepie się zmieniły. */
function fx_omni_promo_sig() {
	if ( ! function_exists( 'fx_promo_active' ) || ! fx_promo_active() ) {
		return 'off';
	}
	$s  = fx_promo_settings();
	$pb = defined( 'FX_PROMO_PB' ) ? get_option( FX_PROMO_PB, array() ) : array();
	return md5( wp_json_encode( array( $s['pct_pb'], $s['pct_shelf'], $s['pct_rest'], $s['ex_ids'], $s['ex_cats'], $s['brands'], $s['rounding'], $s['stack'], (int) ( $pb['at'] ?? 0 ) ) ) );
}

function fx_omni_scan_start( $why ) {
	$st = get_option( 'fx_omni_scan', array() );
	update_option( 'fx_omni_scan', array( 'after' => 0, 'done' => 0, 'why' => $why, 'started' => time(), 'running' => 1, 'last' => $st['last'] ?? 0 ), false );
	if ( ! wp_next_scheduled( 'fx_omni_tick' ) ) {
		wp_schedule_single_event( time() + 5, 'fx_omni_tick' );
	}
}

add_action( 'init', function () {
	// Kampania wystartowała / skończyła się / zmieniła procenty – przeskanuj ceny.
	$sig = fx_omni_promo_sig();
	if ( get_option( 'fx_omni_sig' ) !== $sig ) {
		update_option( 'fx_omni_sig', $sig, false );
		if ( (int) get_option( 'fx_omni_db' ) === FX_OMNI_DB ) {
			fx_omni_scan_start( 'off' === $sig ? 'koniec kampanii' : 'kampania' );
		}
	}
	if ( ! wp_next_scheduled( 'fx_omni_weekly' ) ) {
		wp_schedule_event( time() + DAY_IN_SECONDS, 'weekly', 'fx_omni_weekly' );
	}
}, 30 );
add_action( 'fx_omni_weekly', function () {
	fx_omni_scan_start( 'tygodniowy' );
} );

add_action( 'fx_omni_tick', function () {
	global $wpdb;
	$st = get_option( 'fx_omni_scan', array() );
	if ( empty( $st['running'] ) ) {
		return;
	}
	$ids = $wpdb->get_col( $wpdb->prepare( // phpcs:ignore
		"SELECT p.ID FROM {$wpdb->posts} p
		 WHERE p.ID > %d AND p.post_status = 'publish'
		 AND ( p.post_type = 'product_variation'
		   OR ( p.post_type = 'product' AND EXISTS ( SELECT 1 FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id JOIN {$wpdb->terms} t ON t.term_id = tt.term_id WHERE tr.object_id = p.ID AND tt.taxonomy = 'product_type' AND t.slug = 'simple' ) ) )
		 ORDER BY p.ID ASC LIMIT 300",
		(int) $st['after']
	) );
	$t0    = microtime( true );
	$broke = false;
	foreach ( $ids as $id ) {
		$p = wc_get_product( $id );
		if ( $p ) {
			fx_omni_log( $id, fx_omni_live_price( $p ) );
		}
		$st['after'] = (int) $id;
		$st['done']++;
		if ( microtime( true ) - $t0 > 20 ) {
			$broke = true;
			break;
		}
	}
	if ( ! $broke && count( $ids ) < 300 ) {
		$st['running'] = 0;
		$st['last']    = time();
	} else {
		wp_schedule_single_event( time() + 20, 'fx_omni_tick' );
	}
	update_option( 'fx_omni_scan', $st, false );
} );

/* =====================================================================
 * Cena odniesienia
 * ===================================================================== */

/** Historia z ostatnich 400 dni, od najnowszej: [ [ts, price], … ]. */
function fx_omni_rows( $pid ) {
	$rows = wp_cache_get( 'fx_omni_' . $pid, 'fx_omni' );
	if ( false === $rows ) {
		global $wpdb;
		$t    = fx_omni_table();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ts, price FROM {$t} WHERE pid = %d AND ts > %d ORDER BY ts DESC, id DESC LIMIT 200", $pid, time() - 400 * DAY_IN_SECONDS ), ARRAY_N ); // phpcs:ignore
		wp_cache_set( 'fx_omni_' . $pid, $rows, 'fx_omni', HOUR_IN_SECONDS );
	}
	return (array) $rows;
}

/**
 * Najniższa cena z 30 dni przed obniżką dla produktu prostego albo wariantu w promocji.
 *
 * @return float|null null – produkt nie jest przeceniony.
 */
function fx_omni_ref( $p ) {
	static $memo = array();
	if ( ! $p instanceof WC_Product || $p->is_type( array( 'variable', 'grouped', 'external' ) ) ) {
		return null;
	}
	$id = $p->get_id();
	if ( array_key_exists( $id, $memo ) ) {
		return $memo[ $id ];
	}
	$reg = (float) $p->get_regular_price();
	$cur = fx_omni_live_price( $p );
	if ( $reg <= 0 || $cur <= 0 || $cur >= $reg ) {
		return $memo[ $id ] = null;
	}
	$rows = fx_omni_rows( $id );
	// Początek obecnej obniżki: najstarszy wiersz w ciągu (od najnowszego) cen niższych od regularnej.
	$start = 0;
	$i     = 0;
	foreach ( $rows as $i => $r ) {
		if ( (float) $r[1] >= $reg - 0.005 ) {
			break;
		}
		$start = (int) $r[0];
	}
	if ( ! $start ) {
		$start = time(); // obniżka jeszcze nie zapisana (zapis po skanie) – liczy się 30 ostatnich dni
	}
	$from = $start - 30 * DAY_IN_SECONDS;
	$min  = null;
	foreach ( $rows as $r ) {
		$ts = (int) $r[0];
		if ( $ts >= $start ) {
			continue;
		}
		$min = null === $min ? (float) $r[1] : min( $min, (float) $r[1] );
		if ( $ts <= $from ) {
			break; // cena obowiązująca na początku okna – dalej już nie trzeba
		}
	}
	if ( null === $min ) {
		$min = $reg; // brak historii sprzed obniżki
	}
	return $memo[ $id ] = round( $min, 2 );
}

/** Dla wariantowego: wariant w promocji z najniższą ceną – [ ref, wariant ] albo null. */
function fx_omni_ref_variable( WC_Product $p ) {
	$best = null;
	foreach ( $p->get_visible_children() as $vid ) {
		$v = wc_get_product( $vid );
		if ( ! $v || ! $v->is_on_sale() ) {
			continue;
		}
		$ref = fx_omni_ref( $v );
		if ( null !== $ref && ( null === $best || (float) $v->get_price() < (float) $best[1]->get_price() ) ) {
			$best = array( $ref, $v );
		}
	}
	return $best;
}

/** Podstawa procentu obniżki: najniższa z 30 dni (ustawienie) albo cena regularna. */
function fx_omni_base( WC_Product $p ) {
	$reg = (float) $p->get_regular_price();
	if ( ! fx_tool( 'omni_pct' ) ) {
		return $reg;
	}
	$ref = fx_omni_ref( $p );
	return null === $ref ? $reg : $ref;
}

function fx_omni_html( $ref, $small = false ) {
	$label = trim( (string) fx_tool( 'omni_text' ) );
	return '<p class="fx-omni' . ( $small ? ' fx-omni--s' : '' ) . '">' . esc_html( '' !== $label ? $label : 'Najniższa cena z 30 dni przed obniżką:' ) . ' ' . wc_price( $ref ) . '</p>';
}

/* =====================================================================
 * Wyświetlanie
 * ===================================================================== */

// Przekreślona cena = najniższa cena z 30 dni (a nie regularna). Gdy produkt był w tym czasie tańszy – bez przekreślenia.
add_filter( 'woocommerce_get_price_html', function ( $html, $product ) {
	if ( ! fx_tool( 'omni_on' ) || ! fx_tool( 'omni_strike' ) || ! $product instanceof WC_Product || $product->is_type( array( 'variable', 'grouped', 'external' ) ) || ! $product->is_on_sale() ) {
		return $html;
	}
	$ref = fx_omni_ref( $product );
	if ( null === $ref ) {
		return $html;
	}
	$now = wc_get_price_to_display( $product );
	$was = wc_get_price_to_display( $product, array( 'price' => $ref ) );
	$out = $was > $now ? wc_format_sale_price( $was, $now ) : wc_price( $now );
	return $out . $product->get_price_suffix();
}, 40, 2 );

// Karta produktu – pod ceną (produkt prosty).
add_action( 'woocommerce_single_product_summary', function () {
	global $product;
	if ( ! fx_tool( 'omni_on' ) || ! $product instanceof WC_Product || $product->is_type( 'variable' ) || ! $product->is_on_sale() ) {
		return;
	}
	$ref = fx_omni_ref( $product );
	if ( null !== $ref ) {
		echo fx_omni_html( $ref ); // phpcs:ignore
	}
}, 10 ); // zaraz pod ceną (cena WooCommerce też ma 10, ale jest dodana wcześniej), przed „Kupujesz taniej”

// Wariant – po wybraniu rozmiaru, pod ceną wariantu.
add_filter( 'woocommerce_available_variation', function ( $data, $product, $variation ) {
	if ( function_exists( 'fx_omni_base' ) && $variation->is_on_sale() ) {
		$data['fx_base'] = (float) wc_get_price_to_display( $variation, array( 'price' => fx_omni_base( $variation ) ) ); // podstawa procentu
	}
	if ( fx_tool( 'omni_on' ) && $variation->is_on_sale() ) {
		$ref = fx_omni_ref( $variation );
		if ( null !== $ref ) {
			$data['fx_omni'] = fx_omni_html( $ref ); // skrypt wstawia pod cenę u góry
		}
	}
	return $data;
}, 30, 3 );

// Kategorie i wyszukiwarka – pod ceną na kafelku.
add_action( 'woocommerce_after_shop_loop_item_title', function () {
	global $product;
	if ( ! fx_tool( 'omni_on' ) || ! fx_tool( 'omni_loop' ) || ! $product instanceof WC_Product || ! $product->is_on_sale() ) {
		return;
	}
	if ( $product->is_type( 'variable' ) ) {
		$b = fx_omni_ref_variable( $product );
		if ( $b ) {
			echo fx_omni_html( $b[0], true ); // phpcs:ignore
		}
		return;
	}
	$ref = fx_omni_ref( $product );
	if ( null !== $ref ) {
		echo fx_omni_html( $ref, true ); // phpcs:ignore
	}
}, 11 );

/* =====================================================================
 * Panel: podgląd w edycji produktu + status skanu
 * ===================================================================== */

add_action( 'woocommerce_product_options_pricing', function () {
	global $product_object;
	if ( $product_object instanceof WC_Product && ! $product_object->is_type( 'variable' ) ) {
		$ref = fx_omni_ref( $product_object );
		echo '<p class="form-field"><label>Omnibus</label><span>' . ( null === $ref ? 'produkt nie jest przeceniony' : 'najniższa cena z 30 dni przed obniżką: <strong>' . wp_kses_post( wc_price( $ref ) ) . '</strong>' ) . '</span></p>'; // phpcs:ignore
	}
} );
add_action( 'woocommerce_variation_options_pricing', function ( $loop, $data, $post ) {
	$v   = wc_get_product( $post->ID );
	$ref = $v ? fx_omni_ref( $v ) : null;
	if ( null !== $ref ) {
		echo '<p class="form-row form-row-full">Omnibus – najniższa cena z 30 dni przed obniżką: <strong>' . wp_kses_post( wc_price( $ref ) ) . '</strong></p>'; // phpcs:ignore
	}
}, 10, 3 );

add_action( 'admin_menu', function () {
	add_submenu_page( 'woocommerce', 'Omnibus – historia cen', 'Omnibus', 'manage_woocommerce', 'fx-omnibus', 'fx_omni_page' );
}, 60 );

function fx_omni_page() {
	global $wpdb;
	if ( isset( $_POST['fx_omni_scan'] ) && check_admin_referer( 'fx_omni_scan' ) ) {
		fx_omni_scan_start( 'ręczny' );
		echo '<div class="notice notice-success"><p>Skan cen uruchomiony w tle.</p></div>';
	}
	$st    = get_option( 'fx_omni_scan', array() );
	$t     = fx_omni_table();
	$rows  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" ); // phpcs:ignore
	$items = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT pid) FROM {$t}" ); // phpcs:ignore
	?>
	<div class="wrap">
		<h1>Omnibus – najniższa cena z 30 dni</h1>
		<p>Motyw zapisuje historię cen przy każdej zmianie ceny (także import i kampanie z modułu Przeceny) i przy przecenionych produktach pokazuje najniższą cenę z 30 dni przed obniżką. Ustawienia: Wygląd → Dostosuj → FOX.SKLEP.PL → Narzędzia sklepu.</p>
		<table class="widefat striped" style="max-width:640px">
			<tr><td>Zapisanych zmian cen</td><td><strong><?php echo esc_html( number_format_i18n( $rows ) ); ?></strong></td></tr>
			<tr><td>Produktów i wariantów z historią</td><td><strong><?php echo esc_html( number_format_i18n( $items ) ); ?></strong></td></tr>
			<tr><td>Skan cen</td><td><?php echo ! empty( $st['running'] ) ? 'trwa (' . esc_html( $st['why'] ?? '' ) . ') – sprawdzono ' . esc_html( number_format_i18n( (int) $st['done'] ) ) : 'ostatni: ' . ( ! empty( $st['last'] ) ? esc_html( wp_date( 'j.m.Y H:i', (int) $st['last'] ) ) : '—' ); ?></td></tr>
			<tr><td>Historia od</td><td><?php $first = (int) $wpdb->get_var( "SELECT MIN(ts) FROM {$t}" ); echo $first ? esc_html( wp_date( 'j.m.Y', $first ) ) : '—'; // phpcs:ignore ?></td></tr>
		</table>
		<p>Przez pierwsze 30 dni po wdrożeniu przy produktach, które już były przecenione, cena odniesienia to cena regularna (brak wcześniejszej historii).</p>
		<form method="post"><?php wp_nonce_field( 'fx_omni_scan' ); ?><button class="button" name="fx_omni_scan" value="1">Przeskanuj ceny teraz</button></form>
	</div>
	<?php
}
