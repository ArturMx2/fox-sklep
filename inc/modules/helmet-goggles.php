<?php
/**
 * Oferta „Kask + gogle −20%”.
 *
 * Zasada: na każdy kask w koszyku jedne gogle (najdroższe w koszyku) tańsze o X% (Dostosuj → Narzędzia sklepu).
 * Rabat nalicza się sam – bez kodu. Nie łączy się z rabatem za komplet (liczy się korzystniejszy).
 *
 * Gdzie klient o tym słyszy (od pierwszego kontaktu do kasy):
 *  1. Karta kasku – pod przyciskiem: „Dobierz gogle −20%”.
 *  2. Okno „Produkt został dodany do koszyka” po dodaniu kasku – 3 pasujące gogle z ceną po rabacie
 *     i przyciskiem „Dodaj” (bez wychodzenia z okna).
 *  3. Karta gogli, gdy kask już jest w koszyku – cena po rabacie.
 *  4. Koszyk – brak gogli: przypomnienie z propozycjami; są gogle: cena przekreślona + „−20% z kaskiem”
 *     i wiersz „Rabat kask + gogle” w podsumowaniu.
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

function fx_hg_pct() {
	return max( 0, min( 50, (float) fx_tool( 'hg_pct' ) ) );
}

/** Typ produktu: 'kask', 'gogle' albo ''. Po nazwie (KASK…/GOGLE…), a gdy nazwa nie mówi – po kategorii. */
function fx_hg_type( $product ) {
	$product = $product instanceof WC_Product ? $product : wc_get_product( $product );
	if ( ! $product ) {
		return '';
	}
	if ( $product->is_type( 'variation' ) ) {
		$product = wc_get_product( $product->get_parent_id() );
		if ( ! $product ) {
			return '';
		}
	}
	static $cache = array();
	$id = $product->get_id();
	if ( isset( $cache[ $id ] ) ) {
		return $cache[ $id ];
	}
	$name = mb_strtolower( $product->get_name(), 'UTF-8' );
	$type = '';
	// Szyby, folie, zrywki, daszki, wkładki itp. to akcesoria – nie gogle ani kask.
	if ( ! preg_match( '/\b(szyb|lens|folia|zrywk|tear|roll|daszek|daszk|visor|wkład|wklad|pads|poduszk|torb|pokrowiec|naklejk|sticker)/u', $name ) ) {
		if ( preg_match( '/^\s*(kask|helmet)/u', $name ) ) {
			$type = 'kask';
		} elseif ( preg_match( '/^\s*(gogl|goggle)/u', $name ) ) {
			$type = 'gogle';
		}
	}
	return $cache[ $id ] = apply_filters( 'fx_hg_type', $type, $product );
}

/** Kaski i gogle w koszyku: [ 'helmets' => liczba sztuk, 'goggles' => [klucz pozycji => sztuki] ]. */
function fx_hg_cart_state( $cart = null ) {
	$cart = $cart ? $cart : ( function_exists( 'WC' ) ? WC()->cart : null );
	$out  = array( 'helmets' => 0, 'goggles' => array() );
	if ( ! $cart ) {
		return $out;
	}
	foreach ( $cart->get_cart() as $k => $item ) {
		$t = fx_hg_type( (int) $item['product_id'] );
		if ( 'kask' === $t ) {
			$out['helmets'] += (int) $item['quantity'];
		} elseif ( 'gogle' === $t ) {
			$out['goggles'][ $k ] = (int) $item['quantity'];
		}
	}
	return $out;
}

/* ---------- Rabat w koszyku (po rabacie za komplet – bierzemy korzystniejszy) ---------- */
add_action( 'woocommerce_before_calculate_totals', function ( $cart ) {
	if ( ( is_admin() && ! wp_doing_ajax() ) || ! $cart ) {
		return;
	}
	$GLOBALS['fx_hg_saved'] = 0;
	$pct = fx_hg_pct();
	if ( $pct <= 0 ) {
		return;
	}
	$st = fx_hg_cart_state( $cart );
	if ( ! $st['helmets'] || ! $st['goggles'] ) {
		return;
	}
	// Najdroższe gogle najpierw – klient zyskuje najwięcej.
	$lines = array();
	foreach ( $st['goggles'] as $k => $q ) {
		$item = $cart->cart_contents[ $k ];
		$line = wc_get_product( $item['variation_id'] ? $item['variation_id'] : $item['product_id'] );
		if ( $line ) {
			$lines[ $k ] = (float) $line->get_price();
		}
	}
	arsort( $lines );
	$left = $st['helmets'];
	foreach ( $lines as $k => $orig ) {
		if ( $left <= 0 || $orig <= 0 ) {
			break;
		}
		$item  = $cart->cart_contents[ $k ];
		$qty   = (int) $item['quantity'];
		$units = min( $left, $qty );
		$left -= $units;
		$hg    = $orig * ( 1 - ( $pct / 100 ) * $units / $qty );
		$now   = (float) $item['data']->get_price(); // po ewentualnym rabacie za komplet
		if ( $hg < $now ) {
			$item['data']->set_price( round( $hg, wc_get_price_decimals() ) );
			$cart->cart_contents[ $k ]['fx_hg_units'] = $units;
			$cart->cart_contents[ $k ]['fx_hg_orig']  = $orig;
			$cart->cart_contents[ $k ]['fx_kit_units'] = 0; // bez podwójnego oznaczenia rabatu za komplet
			if ( isset( $GLOBALS['fx_kit_saved'] ) ) {
				$GLOBALS['fx_kit_saved'] = max( 0, $GLOBALS['fx_kit_saved'] - ( $orig - $now ) * $qty );
			}
			$GLOBALS['fx_hg_saved'] += ( $orig - $hg ) * $qty;
		}
	}
}, 25 );

add_filter( 'woocommerce_cart_item_price', function ( $price, $item ) {
	if ( empty( $item['fx_hg_units'] ) || empty( $item['fx_hg_orig'] ) ) {
		return $price;
	}
	return '<del>' . wc_price( $item['fx_hg_orig'] ) . '</del> ' . $price . '<br><small class="fx-kit-badge fx-hg-badge">−' . esc_html( wc_format_localized_decimal( fx_hg_pct() ) ) . '% z kaskiem</small>';
}, 25, 2 );

function fx_hg_saved_row() {
	$saved = isset( $GLOBALS['fx_hg_saved'] ) ? (float) $GLOBALS['fx_hg_saved'] : 0;
	if ( $saved > 0.009 ) {
		echo '<tr class="fx-kit-saved fx-hg-saved"><th>Rabat kask + gogle</th><td data-title="Rabat kask + gogle">−' . wc_price( $saved ) . ' <small>(już w cenach)</small></td></tr>'; // phpcs:ignore
	}
}
add_action( 'woocommerce_cart_totals_before_order_total', 'fx_hg_saved_row', 6 );
add_action( 'woocommerce_review_order_before_order_total', 'fx_hg_saved_row', 6 );

/* ---------- Propozycje gogli ---------- */

/** Do 3 gogli: najpierw ta sama grafika co kask, potem najczęściej kupowane dostępne gogle. */
function fx_hg_suggest( $helmet_id = 0, $limit = 3 ) {
	$ids = array();
	if ( $helmet_id && function_exists( 'fx_kit_gear_for' ) && function_exists( 'fx_kit_parse_name' ) ) {
		list( , $key ) = fx_kit_parse_name( get_the_title( $helmet_id ) );
		$g = $key ? fx_kit_gear_for( 'gogle', $key ) : 0;
		if ( $g ) {
			$ids[] = $g;
		}
	}
	$pool = get_transient( 'fx_hg_pool' );
	if ( ! is_array( $pool ) ) {
		$pool = array();
		$q    = wc_get_products(
			array(
				'status'       => 'publish',
				'stock_status' => 'instock',
				'limit'        => 60,
				'orderby'      => 'popularity',
				'order'        => 'DESC',
				'return'       => 'ids',
				's'            => 'gogle',
			)
		);
		foreach ( $q as $id ) {
			if ( 'gogle' === fx_hg_type( $id ) ) {
				$pool[] = (int) $id;
			}
		}
		set_transient( 'fx_hg_pool', $pool, 6 * HOUR_IN_SECONDS );
	}
	$in_cart = array();
	if ( WC()->cart ) {
		foreach ( WC()->cart->get_cart() as $item ) {
			$in_cart[ (int) $item['product_id'] ] = true;
		}
	}
	foreach ( $pool as $id ) {
		if ( count( $ids ) >= $limit ) {
			break;
		}
		if ( ! in_array( $id, $ids, true ) && empty( $in_cart[ $id ] ) ) {
			$ids[] = $id;
		}
	}
	return array_values(
		array_filter(
			array_slice( $ids, 0, $limit ),
			function ( $id ) use ( $in_cart ) {
				$p = wc_get_product( $id );
				return $p && $p->is_visible() && $p->is_in_stock() && $p->is_purchasable() && empty( $in_cart[ $id ] );
			}
		)
	);
}
add_action( 'woocommerce_update_product', function () {
	delete_transient( 'fx_hg_pool' );
} );

/** Cena po rabacie (najniższa dostępna dla gogli z rozmiarami/wersjami). */
function fx_hg_prices( $p ) {
	$reg = $p->is_type( 'variable' ) ? (float) $p->get_variation_price( 'min', true ) : (float) wc_get_price_to_display( $p );
	return array( $reg, $reg * ( 1 - fx_hg_pct() / 100 ) );
}

/** Kafelki gogli z ceną po rabacie i przyciskiem dodania. */
function fx_hg_tiles( $ids, $context = 'modal' ) {
	if ( ! $ids ) {
		return '';
	}
	$h = '<ul class="fx-hg__list">';
	foreach ( $ids as $id ) {
		$p = wc_get_product( $id );
		if ( ! $p ) {
			continue;
		}
		list( $reg, $deal ) = fx_hg_prices( $p );
		$simple             = $p->is_type( 'simple' );
		$ajax               = 'modal' === $context ? ' ajax_add_to_cart add_to_cart_button' : '';
		$btn                = $simple
			? '<a href="' . esc_url( add_query_arg( 'add-to-cart', $id, wc_get_cart_url() ) ) . '" data-product_id="' . (int) $id . '" data-quantity="1" class="fx-hg__add' . $ajax . '" rel="nofollow">Dodaj</a>'
			: '<a href="' . esc_url( $p->get_permalink() ) . '" class="fx-hg__add">Wybierz</a>';
		$h .= '<li class="fx-hg__item"><a class="fx-hg__img" href="' . esc_url( $p->get_permalink() ) . '">' . $p->get_image( 'woocommerce_gallery_thumbnail' ) . '</a>'
			. '<div class="fx-hg__info"><a class="fx-hg__name" href="' . esc_url( $p->get_permalink() ) . '">' . esc_html( $p->get_name() ) . '</a>'
			. '<span class="fx-hg__price"><del>' . wc_price( $reg ) . '</del> <strong>' . wc_price( $deal ) . '</strong></span></div>' . $btn . '</li>';
	}
	return $h . '</ul>';
}

/* 1. Karta kasku: zapowiedź oferty pod przyciskiem. */
add_action( 'woocommerce_after_add_to_cart_button', function () {
	global $product;
	if ( fx_hg_pct() <= 0 || ! $product || 'kask' !== fx_hg_type( $product ) ) {
		return;
	}
	echo '<p class="fx-hg-teaser"><span class="fx-hg-teaser__tag">−' . esc_html( wc_format_localized_decimal( fx_hg_pct() ) ) . '%</span> Kup kask z goglami – <strong>gogle ' . esc_html( wc_format_localized_decimal( fx_hg_pct() ) ) . '% taniej</strong>. Rabat naliczy się sam w koszyku.</p>';
}, 30 );

/* 2. Okno po dodaniu kasku: gogle z ceną po rabacie. */
add_filter( 'fx_added_offer', function ( $html, $p, $item ) {
	if ( fx_hg_pct() <= 0 || 'kask' !== fx_hg_type( (int) $item['product_id'] ) ) {
		return $html;
	}
	$st = fx_hg_cart_state();
	if ( count( $st['goggles'] ) >= 1 && array_sum( $st['goggles'] ) >= $st['helmets'] ) {
		return $html . '<div class="fx-hg fx-hg--done"><strong>Masz kask i gogle w koszyku</strong> – gogle już są ' . esc_html( wc_format_localized_decimal( fx_hg_pct() ) ) . '% taniej.</div>';
	}
	$ids = fx_hg_suggest( (int) $item['product_id'], 3 );
	if ( ! $ids ) {
		return $html;
	}
	return $html . '<div class="fx-hg"><p class="fx-hg__title">Dorzuć gogle i zgarnij <span>−' . esc_html( wc_format_localized_decimal( fx_hg_pct() ) ) . '%</span></p><p class="fx-hg__sub">Rabat nalicza się automatycznie – bez kodu. Jedne gogle na każdy kask.</p>' . fx_hg_tiles( $ids ) . '</div>';
}, 10, 3 );

/* 3. Karta gogli, gdy kask jest w koszyku. */
add_action( 'woocommerce_single_product_summary', function () {
	global $product;
	if ( fx_hg_pct() <= 0 || ! $product || 'gogle' !== fx_hg_type( $product ) ) {
		return;
	}
	$st = fx_hg_cart_state();
	if ( ! $st['helmets'] ) {
		return;
	}
	list( $reg, $deal ) = fx_hg_prices( $product );
	echo '<p class="fx-hg-teaser fx-hg-teaser--on"><span class="fx-hg-teaser__tag">−' . esc_html( wc_format_localized_decimal( fx_hg_pct() ) ) . '%</span> Masz kask w koszyku – te gogle kupisz za <strong>' . wp_kses_post( ( $product->is_type( 'variable' ) ? 'od ' : '' ) . wc_price( $deal ) ) . '</strong>.</p>';
}, 11 );

/* 4. Koszyk: kask bez gogli – przypomnienie z propozycjami. */
add_action( 'woocommerce_after_cart_table', function () {
	if ( fx_hg_pct() <= 0 ) {
		return;
	}
	$st = fx_hg_cart_state();
	if ( ! $st['helmets'] || array_sum( $st['goggles'] ) >= $st['helmets'] ) {
		return;
	}
	$helmet = 0;
	foreach ( WC()->cart->get_cart() as $item ) {
		if ( 'kask' === fx_hg_type( (int) $item['product_id'] ) ) {
			$helmet = (int) $item['product_id'];
			break;
		}
	}
	$ids = fx_hg_suggest( $helmet, 3 );
	if ( ! $ids ) {
		return;
	}
	echo '<div class="fx-hg fx-hg--cart"><p class="fx-hg__title">Masz kask – dorzuć gogle <span>−' . esc_html( wc_format_localized_decimal( fx_hg_pct() ) ) . '%</span></p><p class="fx-hg__sub">Rabat nalicza się automatycznie w koszyku.</p>' . fx_hg_tiles( $ids, 'cart' ) . '</div>'; // phpcs:ignore
} );
