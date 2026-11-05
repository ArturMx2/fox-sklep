<?php
/**
 * Przeceny – kampania (np. Black Week) z trzema progami rabatu:
 *  1) wyprzedaż Powerbike (status „Wyprzedaż” w importerze na airoh, dopasowanie po SKU / EAN) – np. −30%,
 *  2) półka – rozmiary leżące w sklepie stacjonarnym (stany z „Wysyłka dziś / sklep”) – np. −30%,
 *  3) pozostałe produkty – np. −20% (0 = bez rabatu).
 * Wariant dostaje największy pasujący rabat, liczony od ceny regularnej; głębsza istniejąca promocja
 * (np. z „Przecen Powerbike”) zostaje. Ceny nie są zapisywane w produktach – po końcu kampanii wracają same.
 * W koszyku rabat półki obejmuje tylko tyle sztuk, ile leży na półce; reszta dostaje swój próg.
 * Wykluczenia: produkty i kategorie (z podkategoriami); bony nigdy.
 * Panel: WooCommerce → Przeceny.
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

define( 'FX_PROMO_OPT', 'fx_promo_settings' );
define( 'FX_PROMO_PB', 'fx_promo_pbsale' ); // [ 'at', 'ids' => [ id => 1 ], 'n', 'err' ]

function fx_promo_settings() {
	static $s = null;
	if ( null === $s ) {
		$d = array(
			'on'        => 0,
			'from'      => '',
			'to'        => '',
			'label'     => 'Black Week',
			'pct_pb'    => 30,
			'pct_shelf' => 30,
			'pct_rest'  => 20,
			'ex_ids'    => array(),
			'ex_cats'   => array(),
			'stack'     => 0,
			'note'      => 1,
		);
		$v = get_option( FX_PROMO_OPT, array() );
		$s = array_merge( $d, is_array( $v ) ? $v : array() );
		$s['ex_ids']  = array_map( 'intval', (array) $s['ex_ids'] );
		$s['ex_cats'] = array_map( 'intval', (array) $s['ex_cats'] );
		foreach ( array( 'pct_pb', 'pct_shelf', 'pct_rest' ) as $k ) {
			$s[ $k ] = max( 0, min( 90, (float) $s[ $k ] ) );
		}
	}
	return $s;
}

function fx_promo_ts( $v ) {
	if ( ! $v ) {
		return 0;
	}
	$d = DateTime::createFromFormat( 'Y-m-d\TH:i', $v, wp_timezone() );
	return $d ? $d->getTimestamp() : 0;
}

function fx_promo_active() {
	static $on = null;
	if ( null === $on ) {
		$s    = fx_promo_settings();
		$from = fx_promo_ts( $s['from'] );
		$to   = fx_promo_ts( $s['to'] );
		$now  = time();
		$on   = ! empty( $s['on'] ) && max( $s['pct_pb'], $s['pct_shelf'], $s['pct_rest'] ) > 0
			&& ( ! $from || $now >= $from ) && ( ! $to || $now < $to );
	}
	return $on;
}

/** Sztuk na półce (0 = brak albo moduł stanów sklepu wyłączony). */
function fx_promo_shelf_qty( $id ) {
	if ( ! function_exists( 'fx_ls_data' ) ) {
		return 0;
	}
	return max( 0, (int) ( fx_ls_data()['store'][ (int) $id ] ?? 0 ) );
}

/** Warianty / produkty proste ze statusem „Wyprzedaż” w Powerbike. */
function fx_promo_pb_ids() {
	static $ids = null;
	if ( null === $ids ) {
		$d   = get_option( FX_PROMO_PB, array() );
		$ids = is_array( $d ) && ! empty( $d['ids'] ) ? $d['ids'] : array();
	}
	return $ids;
}

function fx_promo_excluded( $parent_id ) {
	static $cache = array();
	$parent_id = (int) $parent_id;
	if ( isset( $cache[ $parent_id ] ) ) {
		return $cache[ $parent_id ];
	}
	$s = fx_promo_settings();
	if ( in_array( $parent_id, $s['ex_ids'], true ) ) {
		return $cache[ $parent_id ] = true;
	}
	$vouchers = function_exists( 'fx_tool' ) ? array_map( 'intval', explode( ',', (string) fx_tool( 'voucher_ids' ) ) ) : array();
	if ( in_array( $parent_id, $vouchers, true ) ) {
		return $cache[ $parent_id ] = true;
	}
	if ( $s['ex_cats'] ) {
		foreach ( wc_get_product_cat_ids( $parent_id ) as $c ) {
			if ( array_intersect( array_merge( array( (int) $c ), array_map( 'intval', get_ancestors( $c, 'product_cat' ) ) ), $s['ex_cats'] ) ) {
				return $cache[ $parent_id ] = true;
			}
		}
	}
	return $cache[ $parent_id ] = false;
}

/**
 * Rabat dla wariantu / produktu prostego: [ procent, źródło ('pb'|'shelf'|'rest'), procent bez półki ].
 * [0, '', 0] = bez rabatu.
 */
function fx_promo_rule( $product ) {
	$none = array( 0, '', 0 );
	if ( ! fx_promo_active() || ! $product instanceof WC_Product || ! empty( $GLOBALS['fx_promo_bypass'] ) ) {
		return $none;
	}
	if ( isset( $GLOBALS['fx_promo_priced'][ spl_object_id( $product ) ] ) ) {
		return $none; // pozycja koszyka z ceną już policzoną
	}
	if ( ! $product->is_type( 'simple' ) && ! $product->is_type( 'variation' ) ) {
		return $none;
	}
	$id     = $product->get_id();
	$parent = $product->is_type( 'variation' ) ? $product->get_parent_id() : $id;
	if ( fx_promo_excluded( $parent ) ) {
		return $none;
	}
	$s    = fx_promo_settings();
	$cand = array( 'rest' => $s['pct_rest'] );
	$pb   = fx_promo_pb_ids();
	if ( isset( $pb[ $id ] ) || ( $product->is_type( 'variation' ) && isset( $pb[ $parent ] ) ) ) {
		$cand['pb'] = $s['pct_pb'];
	}
	$alt = max( $cand );
	if ( fx_promo_shelf_qty( $id ) > 0 ) {
		$cand['shelf'] = $s['pct_shelf'];
	}
	arsort( $cand );
	$pct = reset( $cand );
	return $pct > 0 ? array( (float) $pct, key( $cand ), (float) $alt ) : $none;
}

/** Cena po rabacie (od ceny regularnej) albo null, gdy obecna jest niższa. */
function fx_promo_price( $product, $pct, $current = null ) {
	$reg = (float) $product->get_regular_price( 'edit' );
	if ( $reg <= 0 || $pct <= 0 ) {
		return null;
	}
	$deal = round( $reg * ( 1 - $pct / 100 ), wc_get_price_decimals() );
	$cur  = null === $current ? (float) $product->get_price( 'edit' ) : (float) $current;
	$cur  = $cur > 0 ? $cur : $reg;
	return $deal < $cur ? $deal : null;
}

/* ---------- Ceny w sklepie ---------- */

$fx_promo_cb = function ( $value, $product ) {
	$r = fx_promo_rule( $product );
	if ( ! $r[0] ) {
		return $value;
	}
	$p = fx_promo_price( $product, $r[0] );
	return null === $p ? $value : $p;
};
foreach ( array( 'woocommerce_product_variation_get_price', 'woocommerce_product_variation_get_sale_price', 'woocommerce_product_get_price', 'woocommerce_product_get_sale_price', 'woocommerce_variation_prices_price', 'woocommerce_variation_prices_sale_price' ) as $fx_hook ) {
	add_filter( $fx_hook, $fx_promo_cb, 50, 2 );
}

add_filter( 'woocommerce_get_variation_prices_hash', function ( $hash ) {
	if ( fx_promo_active() ) {
		$s      = fx_promo_settings();
		$pb     = get_option( FX_PROMO_PB, array() );
		$hash[] = 'fxpromo|' . md5( wp_json_encode( array( $s['pct_pb'], $s['pct_shelf'], $s['pct_rest'], $s['ex_ids'], $s['ex_cats'] ) ) ) . '|' . (int) ( $pb['at'] ?? 0 ) . '|' . ( function_exists( 'fx_ls_data' ) ? (int) fx_ls_data()['at'] : 0 );
	}
	return $hash;
} );

/* „Tylko promocje” w kategoriach – także produkty objęte kampanią. */
add_filter( 'fx_sale_ids', function ( $ids ) {
	if ( ! fx_promo_active() ) {
		return $ids;
	}
	static $extra = null;
	if ( null === $extra ) {
		global $wpdb;
		$s     = fx_promo_settings();
		$extra = array();
		if ( $s['pct_rest'] > 0 ) {
			$extra = array_map( 'intval', $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish'" ) ); // phpcs:ignore
		} else {
			$parents = array();
			if ( $s['pct_shelf'] > 0 && function_exists( 'fx_ls_data' ) ) {
				$d = fx_ls_data();
				foreach ( (array) $d['store'] as $id => $q ) {
					if ( $q > 0 ) {
						$parents[ (int) ( $d['parent'][ $id ] ?? $id ) ] = true;
					}
				}
			}
			if ( $s['pct_pb'] > 0 ) {
				$pb = get_option( FX_PROMO_PB, array() );
				foreach ( (array) ( $pb['parents'] ?? array() ) as $p ) {
					$parents[ (int) $p ] = true;
				}
			}
			$extra = array_keys( $parents );
		}
		$extra = array_values( array_filter( $extra, function ( $p ) { return ! fx_promo_excluded( $p ); } ) );
	}
	return array_values( array_unique( array_merge( $ids, $extra ) ) );
} );

/* ---------- Karta produktu ---------- */
add_action( 'woocommerce_single_product_summary', function () {
	global $product;
	$s = fx_promo_settings();
	if ( ! fx_promo_active() || empty( $s['note'] ) || ! $product instanceof WC_Product ) {
		return;
	}
	$best  = 0;
	$base  = 0;
	$shelf = array();
	$items = $product->is_type( 'variable' ) ? array_filter( array_map( 'wc_get_product', $product->get_children() ) ) : array( $product );
	foreach ( $items as $v ) {
		if ( ! $v->is_in_stock() ) {
			continue;
		}
		$r = fx_promo_rule( $v );
		if ( ! $r[0] || null === fx_promo_price( $v, $r[0] ) ) {
			continue;
		}
		$best = max( $best, $r[0] );
		$base = $base ? min( $base, $r[2] ? $r[2] : $r[0] ) : ( $r[2] ? $r[2] : $r[0] );
		if ( 'shelf' === $r[1] && $r[0] > $r[2] && $v->is_type( 'variation' ) ) {
			$names = array();
			foreach ( $v->get_variation_attributes() as $tax => $val ) {
				$tax     = str_replace( 'attribute_', '', $tax );
				$t       = taxonomy_exists( $tax ) ? get_term_by( 'slug', $val, $tax ) : null;
				$names[] = $t ? $t->name : $val;
			}
			$shelf[] = function_exists( 'fx_size_label' ) ? fx_size_label( implode( ' / ', $names ) ) : implode( ' / ', $names );
		}
	}
	if ( ! $best ) {
		return;
	}
	$f    = function ( $x ) {
		return '−' . wc_format_localized_decimal( $x ) . '%';
	};
	$head = trim( $s['label'] ) ? esc_html( $s['label'] ) . ': ' : '';
	if ( $shelf && $base && $base < $best ) {
		$text = '<strong>' . $head . esc_html( $f( $base ) ) . '</strong>, a na rozmiary dostępne od ręki (' . esc_html( implode( ', ', array_unique( $shelf ) ) ) . ') <strong>' . esc_html( $f( $best ) ) . '</strong>';
	} elseif ( $shelf ) {
		$text = '<strong>' . $head . esc_html( $f( $best ) ) . '</strong> na rozmiary dostępne od ręki: <strong>' . esc_html( implode( ', ', array_unique( $shelf ) ) ) . '</strong>';
	} else {
		$text = '<strong>' . $head . esc_html( $f( $best ) ) . '</strong>';
	}
	$to = fx_promo_ts( $s['to'] );
	if ( $to ) {
		$text .= ' · do ' . esc_html( wp_date( 'j.m, H:i', $to ) );
	}
	echo '<p class="fx-promo-note">' . $text . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
}, 11 );

add_action( 'wp_head', function () {
	if ( fx_promo_active() && function_exists( 'is_product' ) && is_product() ) {
		echo '<style>.fx-promo-note{margin:10px 0 14px;padding:10px 14px;border-radius:10px;background:#0c0a08;color:#fff;font-size:14px;line-height:1.45}</style>';
	}
} );

/* ---------- Koszyk ---------- */
add_action( 'woocommerce_before_calculate_totals', function ( $cart ) {
	if ( ( is_admin() && ! wp_doing_ajax() ) || ! $cart || ! fx_promo_active() ) {
		return;
	}
	$stack = ! empty( fx_promo_settings()['stack'] );
	foreach ( $cart->get_cart() as $k => $item ) {
		unset( $GLOBALS['fx_promo_priced'][ spl_object_id( $item['data'] ) ] );
		$id   = $item['variation_id'] ? (int) $item['variation_id'] : (int) $item['product_id'];
		$line = wc_get_product( $id );
		$r    = $line ? fx_promo_rule( $line ) : array( 0, '', 0 );
		if ( ! $r[0] ) {
			continue;
		}
		$GLOBALS['fx_promo_bypass'] = true;
		$normal = (float) wc_get_product( $id )->get_price(); // bez kampanii
		$GLOBALS['fx_promo_bypass'] = false;
		$deal = fx_promo_price( $line, $r[0], $normal );
		if ( null === $deal ) {
			continue;
		}
		$qty = max( 1, (int) $item['quantity'] );
		// Półka: rabat półki na tyle sztuk, ile leży; reszta – próg bez półki (wyprzedaż / pozostałe).
		$units = $qty;
		$rest  = $deal;
		if ( 'shelf' === $r[1] ) {
			$units = min( $qty, fx_promo_shelf_qty( $id ) );
			$alt   = $r[2] ? fx_promo_price( $line, $r[2], $normal ) : null;
			$rest  = null === $alt ? $normal : $alt;
		}
		$now = (float) $item['data']->get_price(); // po rabacie za komplet / kask + gogle (liczonym od ceny kampanii)
		if ( ! $stack && $now < $deal ) {
			if ( isset( $GLOBALS['fx_kit_saved'] ) && ! empty( $item['fx_kit_units'] ) ) {
				$GLOBALS['fx_kit_saved'] = max( 0, $GLOBALS['fx_kit_saved'] - ( $deal - $now ) * $qty );
			}
			if ( isset( $GLOBALS['fx_hg_saved'] ) && ! empty( $item['fx_hg_units'] ) ) {
				$GLOBALS['fx_hg_saved'] = max( 0, $GLOBALS['fx_hg_saved'] - ( $deal - $now ) * $qty );
			}
			$cart->cart_contents[ $k ]['fx_kit_units'] = 0;
			$cart->cart_contents[ $k ]['fx_hg_units']  = 0;
			$now = $deal;
		}
		$price = $now + ( $rest - $deal ) * ( $qty - $units ) / $qty;
		$item['data']->set_price( round( $price, wc_get_price_decimals() ) );
		$GLOBALS['fx_promo_priced'][ spl_object_id( $item['data'] ) ] = true;
		$cart->cart_contents[ $k ]['fx_promo'] = array( $r[0], $r[1], $units, $qty );
	}
}, 40 );

add_filter( 'woocommerce_get_item_data', function ( $data, $item ) {
	if ( empty( $item['fx_promo'] ) || ! fx_promo_active() ) {
		return $data;
	}
	list( $pct, $src, $units, $qty ) = $item['fx_promo'];
	$s   = fx_promo_settings();
	$txt = '−' . wc_format_localized_decimal( $pct ) . '%';
	if ( 'shelf' === $src ) {
		$txt .= ' (od ręki' . ( $units < $qty ? ', ' . (int) $units . ' z ' . (int) $qty . ' szt.' : '' ) . ')';
	}
	$data[] = array( 'key' => trim( $s['label'] ) ? $s['label'] : 'Promocja', 'value' => $txt );
	return $data;
}, 10, 2 );

/* ---------- Wyprzedaż Powerbike: lista z importera ---------- */

/** Pobranie listy „Wyprzedaż” (warianty i produkty proste sklepu). */
function fx_promo_pb_refresh() {
	if ( ! function_exists( 'fxp_fetch' ) || ! function_exists( 'fxp_resolve' ) ) {
		return 'Brak modułu parametrów z importera.';
	}
	try {
		$items = fxp_fetch( 50, 2000, array( 'status' => 'Wyprzedaż', 'attributes' => 0 ) );
	} catch ( Exception $e ) {
		$old        = get_option( FX_PROMO_PB, array() );
		$old        = is_array( $old ) ? $old : array();
		$old['err'] = $e->getMessage();
		update_option( FX_PROMO_PB, $old, false ); // stara lista zostaje
		return $e->getMessage();
	}
	$un      = array();
	$found   = fxp_resolve( $items, $un, false );
	$ids     = array();
	$parents = array();
	foreach ( array_keys( $found ) as $id ) {
		$ids[ (int) $id ] = 1;
		$pp               = 'product_variation' === get_post_type( $id ) ? (int) wp_get_post_parent_id( $id ) : (int) $id;
		$parents[ $pp ]   = $pp;
	}
	update_option( FX_PROMO_PB, array( 'at' => time(), 'ids' => $ids, 'parents' => array_values( $parents ), 'n' => count( $items ), 'unmatched' => count( $un ), 'err' => '' ), false );
	return '';
}

/* Odświeżanie listy wyprzedaży: codziennie i na start kampanii; cache stron – na start i koniec. */
add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'fx_promo_daily' ) ) {
		wp_schedule_event( time() + 600, 'twicedaily', 'fx_promo_daily' );
	}
} );
add_action( 'fx_promo_daily', function () {
	$s = fx_promo_settings();
	if ( ! empty( $s['on'] ) && $s['pct_pb'] > 0 ) {
		fx_promo_pb_refresh();
	}
} );
add_action( 'fx_promo_edge', function () {
	$s = fx_promo_settings();
	if ( ! empty( $s['on'] ) && $s['pct_pb'] > 0 ) {
		fx_promo_pb_refresh();
	}
	do_action( 'litespeed_purge_all' );
	if ( function_exists( 'fx_cache_touch' ) ) {
		fx_cache_touch();
	}
} );
function fx_promo_schedule() {
	wp_clear_scheduled_hook( 'fx_promo_edge' );
	$s = fx_promo_settings();
	foreach ( array( $s['from'], $s['to'] ) as $t ) {
		$ts = fx_promo_ts( $t );
		if ( $ts > time() ) {
			wp_schedule_single_event( $ts + 5, 'fx_promo_edge' );
		}
	}
}

/* =====================================================================
 * Panel: WooCommerce → Przeceny
 * ===================================================================== */
add_action( 'admin_menu', function () {
	add_submenu_page( 'woocommerce', 'Przeceny', 'Przeceny', 'manage_woocommerce', 'fx-promo', 'fx_promo_page' );
}, 61 );

add_action( 'admin_enqueue_scripts', function ( $hook ) {
	if ( false !== strpos( (string) $hook, 'fx-promo' ) ) {
		wp_enqueue_script( 'wc-enhanced-select' );
		wp_enqueue_style( 'woocommerce_admin_styles' );
	}
} );

add_action( 'admin_post_fx_promo', function () {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( 'Brak uprawnień.' );
	}
	check_admin_referer( 'fx_promo' );
	$in  = wp_unslash( $_POST ); // phpcs:ignore
	$msg = 'Zapisano. Cache stron wyczyszczony.';
	if ( 'pb' === ( $in['do'] ?? '' ) ) {
		$err = fx_promo_pb_refresh();
		$msg = $err ? 'Wyprzedaż Powerbike: ' . $err : 'Lista wyprzedaży Powerbike odświeżona.';
	} else {
		$dt  = function ( $v ) {
			$v = trim( (string) $v );
			return preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $v ) ? $v : '';
		};
		$num = function ( $v ) {
			return max( 0, min( 90, (float) str_replace( ',', '.', (string) $v ) ) );
		};
		update_option(
			FX_PROMO_OPT,
			array(
				'on'        => empty( $in['on'] ) ? 0 : 1,
				'from'      => $dt( $in['from'] ?? '' ),
				'to'        => $dt( $in['to'] ?? '' ),
				'label'     => sanitize_text_field( $in['label'] ?? '' ),
				'pct_pb'    => $num( $in['pct_pb'] ?? 0 ),
				'pct_shelf' => $num( $in['pct_shelf'] ?? 0 ),
				'pct_rest'  => $num( $in['pct_rest'] ?? 0 ),
				'ex_ids'    => array_values( array_filter( array_map( 'absint', (array) ( $in['ex_ids'] ?? array() ) ) ) ),
				'ex_cats'   => array_values( array_filter( array_map( 'absint', (array) ( $in['ex_cats'] ?? array() ) ) ) ),
				'stack'     => empty( $in['stack'] ) ? 0 : 1,
				'note'      => empty( $in['note'] ) ? 0 : 1,
			),
			false
		);
		if ( ! empty( $in['on'] ) && (float) ( $in['pct_pb'] ?? 0 ) > 0 ) {
			$err = fx_promo_pb_refresh();
			if ( $err ) {
				$msg .= ' Uwaga – lista wyprzedaży Powerbike: ' . $err;
			}
		}
		fx_promo_schedule();
	}
	do_action( 'litespeed_purge_all' );
	if ( function_exists( 'fx_cache_touch' ) ) {
		fx_cache_touch();
	}
	wp_safe_redirect( add_query_arg( array( 'page' => 'fx-promo', 'msg' => rawurlencode( $msg ) ), admin_url( 'admin.php' ) ) );
	exit;
} );

function fx_promo_page() {
	$s      = fx_promo_settings();
	$active = fx_promo_active();
	$pb     = get_option( FX_PROMO_PB, array() );
	$d      = function_exists( 'fx_ls_data' ) ? fx_ls_data() : array( 'store' => array(), 'at' => 0 );
	$shelf  = 0;
	foreach ( (array) $d['store'] as $q ) {
		$shelf += $q > 0 ? 1 : 0;
	}
	$msg = isset( $_GET['msg'] ) ? sanitize_text_field( wp_unslash( $_GET['msg'] ) ) : ''; // phpcs:ignore
	$fmt = function ( $t ) {
		return $t ? wp_date( 'j.m.Y H:i', (int) $t ) : 'brak';
	};
	?>
	<div class="wrap">
		<h1>Przeceny</h1>
		<p>Kampania rabatowa (np. Black Week) bez zapisu cen w produktach: w podanym czasie ceny w sklepie, w koszyku i w feedach są obniżone, po końcu wracają same. Każdy rozmiar dostaje <strong>największy pasujący rabat</strong>, liczony od ceny regularnej; jeśli produkt ma już głębszą promocję (np. z „Przecen Powerbike”), zostaje ta głębsza.</p>
		<?php if ( $msg ) : ?><div class="notice notice-info"><p><?php echo esc_html( $msg ); ?></p></div><?php endif; ?>
		<div class="notice <?php echo $active ? 'notice-success' : 'notice-warning'; ?> inline"><p><strong><?php echo $active ? 'Kampania TRWA' : 'Kampania nieaktywna'; ?></strong><?php echo $s['to'] ? ' · koniec: ' . esc_html( $fmt( fx_promo_ts( $s['to'] ) ) ) : ''; ?></p></div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'fx_promo' ); ?><input type="hidden" name="action" value="fx_promo">
			<table class="form-table">
				<tr><th>Włączona</th><td><label><input type="checkbox" name="on" value="1" <?php checked( $s['on'] ); ?>> kampania działa w podanym czasie</label></td></tr>
				<tr><th>Od – do</th><td><input type="datetime-local" name="from" value="<?php echo esc_attr( $s['from'] ); ?>"> – <input type="datetime-local" name="to" value="<?php echo esc_attr( $s['to'] ); ?>"><p class="description">Czas polski. Puste „od” = od razu. Na start i koniec cache stron czyści się sam.</p></td></tr>
				<tr><th>Nazwa</th><td><input type="text" name="label" value="<?php echo esc_attr( $s['label'] ); ?>" class="regular-text"><p class="description">Na karcie produktu i przy pozycji w koszyku.</p></td></tr>
				<tr><th>Wyprzedaż Powerbike</th><td><input type="number" name="pct_pb" value="<?php echo esc_attr( $s['pct_pb'] ); ?>" min="0" max="90" step="0.5" style="width:80px"> %
					<p class="description">Rozmiary ze statusem „Wyprzedaż” w importerze (lista: <?php echo (int) count( (array) ( $pb['ids'] ?? array() ) ); ?> rozmiarów / produktów, odświeżona <?php echo esc_html( $fmt( $pb['at'] ?? 0 ) ); ?><?php echo ! empty( $pb['err'] ) ? ' – <strong>błąd: ' . esc_html( $pb['err'] ) . '</strong>' : ''; ?>). Odświeża się przy zapisie, na start kampanii i 2× dziennie.</p></td></tr>
				<tr><th>Półka (sklep stacjonarny)</th><td><input type="number" name="pct_shelf" value="<?php echo esc_attr( $s['pct_shelf'] ); ?>" min="0" max="90" step="0.5" style="width:80px"> %
					<p class="description">Rozmiary leżące w sklepie (teraz: <?php echo (int) $shelf; ?>, stany z <?php echo esc_html( $fmt( $d['at'] ?? 0 ) ); ?>). W koszyku – tylko tyle sztuk, ile leży na półce.</p></td></tr>
				<tr><th>Pozostałe produkty</th><td><input type="number" name="pct_rest" value="<?php echo esc_attr( $s['pct_rest'] ); ?>" min="0" max="90" step="0.5" style="width:80px"> %<p class="description">Wszystko inne (0 = bez rabatu).</p></td></tr>
				<tr><th>Wyklucz produkty</th><td>
					<select class="wc-product-search" multiple style="width:100%;max-width:600px" name="ex_ids[]" data-placeholder="Szukaj produktu…" data-action="woocommerce_json_search_products">
						<?php foreach ( $s['ex_ids'] as $pid ) : $p = wc_get_product( $pid ); if ( $p ) : ?>
							<option value="<?php echo (int) $pid; ?>" selected><?php echo esc_html( wp_strip_all_tags( $p->get_formatted_name() ) ); ?></option>
						<?php endif; endforeach; ?>
					</select><p class="description">Wykluczone produkty nie mają żadnego rabatu kampanii (bony podarunkowe – zawsze).</p></td></tr>
				<tr><th>Wyklucz kategorie</th><td>
					<select class="wc-enhanced-select" multiple style="width:100%;max-width:600px" name="ex_cats[]" data-placeholder="Wybierz kategorie…">
						<?php foreach ( get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) ) as $t ) : ?>
							<option value="<?php echo (int) $t->term_id; ?>" <?php selected( in_array( (int) $t->term_id, $s['ex_cats'], true ) ); ?>><?php echo esc_html( $t->name . ( $t->parent && get_term( $t->parent ) ? ' (' . get_term( $t->parent )->name . ')' : '' ) ); ?></option>
						<?php endforeach; ?>
					</select><p class="description">Z podkategoriami.</p></td></tr>
				<tr><th>Łączenie rabatów</th><td><label><input type="checkbox" name="stack" value="1" <?php checked( $s['stack'] ); ?>> dolicz rabat za komplet i „kask + gogle” do ceny z kampanii</label><p class="description">Odznaczone: klient dostaje korzystniejszy rabat, nie oba. Kupony działają według swoich ustawień (np. „Wyklucz produkty w promocji”).</p></td></tr>
				<tr><th>Karta produktu</th><td><label><input type="checkbox" name="note" value="1" <?php checked( $s['note'] ); ?>> pasek z rabatem (np. „Black Week: −20%, a na rozmiary dostępne od ręki (M, L) −30%”)</label></td></tr>
			</table>
			<p><?php submit_button( 'Zapisz', 'primary', 'submit', false ); ?> <button class="button" name="do" value="pb">Odśwież listę wyprzedaży Powerbike</button></p>
		</form>
	</div>
	<?php
}
