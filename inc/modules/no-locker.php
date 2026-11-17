<?php
/**
 * „Bez Paczkomatu” dla produktów o określonej nazwie (gabaryty: duże torby, plastiki, błotniki…).
 *
 * Frazy: Dostosuj → Narzędzia sklepu → „Bez Paczkomatu – frazy w nazwie produktu” (jedna w linii, puste = wyłączone).
 * - produkt jest gabarytem, gdy jego nazwa (albo nazwa produktu głównego wariantu) zawiera którąś frazę (bez wielkości liter);
 * - gabaryt w paczce → znikają metody paczkomatowe (ID z „paczkomat” / „parcel_machine” / „easypack” albo „Paczkomat” w nazwie;
 *   na fox.sklep.pl: inpost_paczkomaty); gdyby nie została żadna metoda – zostają oryginalne stawki;
 * - komunikat na karcie produktu, w koszyku i w zamówieniu (odświeżany razem z podsumowaniem).
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

/** Lista fraz (małe litery). */
function fx_nl_phrases() {
	static $p = null;
	if ( null === $p ) {
		$p = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) fx_tool( 'no_locker' ) ) as $l ) {
			$l = trim( mb_strtolower( wp_strip_all_tags( $l ), 'UTF-8' ) );
			if ( '' !== $l ) {
				$p[] = $l;
			}
		}
	}
	return $p;
}

/** Czy produkt (prosty, wariantowy albo wariant) to gabaryt. */
function fx_nl_is_bulky( $product ) {
	static $memo = array();
	if ( ! $product instanceof WC_Product ) {
		return false;
	}
	$ph = fx_nl_phrases();
	if ( ! $ph ) {
		return false;
	}
	$id = $product->get_id();
	if ( isset( $memo[ $id ] ) ) {
		return $memo[ $id ];
	}
	$names = array( $product->get_name() );
	if ( $product->get_parent_id() ) {
		$names[] = get_the_title( $product->get_parent_id() );
	}
	$hay = mb_strtolower( wp_strip_all_tags( implode( ' | ', $names ) ), 'UTF-8' );
	foreach ( $ph as $f ) {
		if ( false !== mb_strpos( $hay, $f ) ) {
			return $memo[ $id ] = true;
		}
	}
	return $memo[ $id ] = false;
}

/** Czy stawka to Paczkomat. */
function fx_nl_is_locker_rate( $rate ) {
	$ids = strtolower( $rate->get_id() . ' ' . $rate->get_method_id() );
	return (bool) preg_match( '/paczkomat|parcel_machine|easypack/', $ids ) || false !== mb_stripos( wp_strip_all_tags( $rate->get_label() ), 'paczkomat' );
}

/** Nazwy gabarytów w koszyku (unikalne). */
function fx_nl_cart_bulky() {
	$out = array();
	if ( ! fx_nl_phrases() || ! function_exists( 'WC' ) || ! WC()->cart ) {
		return $out;
	}
	foreach ( WC()->cart->get_cart() as $item ) {
		$p = $item['data'] ?? null;
		if ( fx_nl_is_bulky( $p ) ) {
			$pid         = $p->get_parent_id() ? $p->get_parent_id() : $p->get_id();
			$out[ $pid ] = $p->get_parent_id() ? get_the_title( $pid ) : $p->get_name();
		}
	}
	return array_values( $out );
}

/* ---------- Metody dostawy ---------- */
add_filter( 'woocommerce_package_rates', function ( $rates, $package ) {
	if ( ! $rates || ! fx_nl_phrases() ) {
		return $rates;
	}
	$bulky = false;
	foreach ( (array) ( $package['contents'] ?? array() ) as $item ) {
		if ( fx_nl_is_bulky( $item['data'] ?? null ) ) {
			$bulky = true;
			break;
		}
	}
	if ( ! $bulky ) {
		return $rates;
	}
	$left = array_filter( $rates, function ( $r ) {
		return ! fx_nl_is_locker_rate( $r );
	} );
	return $left ? $left : $rates; // bezpiecznik: klient nie zostaje bez dostawy
}, 100, 2 );

// Znacznik listy fraz w paczce – po zmianie fraz stawki przeliczają się od razu (także w otwartych koszykach).
add_filter( 'woocommerce_cart_shipping_packages', function ( $packages ) {
	$ph = fx_nl_phrases();
	if ( $ph ) {
		foreach ( $packages as $k => $p ) {
			$packages[ $k ]['fx_nl'] = md5( implode( '|', $ph ) );
		}
	}
	return $packages;
} );

/* ---------- Komunikaty ---------- */
function fx_nl_notice_html() {
	$names = fx_nl_cart_bulky();
	if ( ! $names ) {
		return '';
	}
	$list = implode( ', ', array_map( function ( $n ) {
		return '„' . $n . '”';
	}, $names ) );
	$txt  = 1 === count( $names )
		? 'Wysyłka do Paczkomatu jest niedostępna dla produktu: ' . $list . ' (gabaryt). Wybierz kuriera lub odbiór osobisty.'
		: 'Wysyłka do Paczkomatu jest niedostępna dla produktów: ' . $list . ' (gabaryty). Wybierz kuriera lub odbiór osobisty.';
	return '<p class="fx-nl-note">' . esc_html( $txt ) . '</p>';
}

add_action( 'woocommerce_before_cart_totals', function () {
	echo fx_nl_notice_html(); // phpcs:ignore WordPress.Security.EscapeOutput
}, 8 );

add_action( 'woocommerce_review_order_before_payment', function () {
	echo '<div class="fx-nl-co">' . fx_nl_notice_html() . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
} );
add_filter( 'woocommerce_update_order_review_fragments', function ( $f ) {
	$f['.fx-nl-co'] = '<div class="fx-nl-co">' . fx_nl_notice_html() . '</div>';
	return $f;
} );

add_action( 'woocommerce_single_product_summary', function () {
	global $product;
	if ( fx_nl_is_bulky( $product ) ) {
		echo '<p class="fx-nl-pdp">Ten produkt wysyłamy kurierem – nie mieści się w Paczkomacie.</p>';
	}
}, 26 );
