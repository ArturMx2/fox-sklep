<?php
/**
 * Reguły metod dostawy w koszyku i zamówieniu.
 *
 * 1. Darmowa dostawa spełniona → płatne metody znikają (np. „Kurier InPost: 18,45 zł”, gdy jest
 *    „Kurier InPost darmowa dostawa”). Zostają metody bez darmowego odpowiednika, np. pobranie
 *    (lista słów w Dostosuj → Narzędzia sklepu).
 * 2. „E-Bon na email” tylko wtedy, gdy w koszyku są wyłącznie bony podarunkowe – kasku nie da się
 *    zamówić „na maila”. Bon razem z innym produktem = zwykła wysyłka.
 *
 * Bon podarunkowy = produkt o ID z ustawień (domyślnie 25473) albo z „voucher” / „bon podarunkowy”
 * / „karta podarunkowa” w nazwie.
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

/** Czy pozycja koszyka to bon podarunkowy. */
function fx_ship_is_voucher( $product_id ) {
	$ids = array_filter( array_map( 'absint', explode( ',', (string) fx_tool( 'voucher_ids' ) ) ) );
	if ( in_array( (int) $product_id, $ids, true ) ) {
		return true;
	}
	return (bool) preg_match( '/\bvoucher\b|bon\s+podarunkow|karta\s+podarunkow|e-?bon\b/iu', (string) get_the_title( $product_id ) );
}

/** Czy metoda to wysyłka bonu e-mailem. */
function fx_ship_is_ebon_rate( $rate ) {
	return (bool) preg_match( '/\be-?bon\b|na\s+e-?mail/iu', wp_strip_all_tags( $rate->get_label() ) );
}

add_filter( 'woocommerce_package_rates', function ( $rates, $package ) {
	if ( ! $rates ) {
		return $rates;
	}

	// 2. E-Bon tylko dla koszyka z samymi bonami.
	$only_vouchers = ! empty( $package['contents'] );
	foreach ( (array) $package['contents'] as $item ) {
		if ( ! fx_ship_is_voucher( (int) $item['product_id'] ) ) {
			$only_vouchers = false;
			break;
		}
	}
	if ( ! $only_vouchers ) {
		foreach ( $rates as $id => $rate ) {
			if ( fx_ship_is_ebon_rate( $rate ) ) {
				unset( $rates[ $id ] );
			}
		}
	}

	// 1. Spełniona darmowa dostawa: bez płatnych odpowiedników.
	if ( fx_tool( 'ship_hide_paid' ) ) {
		$free = false;
		foreach ( $rates as $rate ) {
			if ( 'free_shipping' === $rate->get_method_id() || ( (float) $rate->get_cost() <= 0 && preg_match( '/darmow/iu', $rate->get_label() ) ) ) {
				$free = true;
				break;
			}
		}
		if ( $free ) {
			$keep = array_filter( array_map( 'trim', explode( ',', mb_strtolower( (string) fx_tool( 'ship_keep_paid' ) ) ) ) );
			foreach ( $rates as $id => $rate ) {
				if ( (float) $rate->get_cost() <= 0 ) {
					continue;
				}
				$label = mb_strtolower( wp_strip_all_tags( $rate->get_label() ) );
				foreach ( $keep as $word ) {
					if ( false !== strpos( $label, $word ) ) {
						continue 2;
					}
				}
				unset( $rates[ $id ] );
			}
		}
	}
	return $rates;
}, 100, 2 );

/* Odśwież zapamiętane metody dostawy po wgraniu tej wersji (WooCommerce trzyma je w sesji klienta). */
add_action( 'init', function () {
	if ( '1' !== get_option( 'fx_ship_rules_v1' ) && class_exists( 'WC_Cache_Helper' ) ) {
		WC_Cache_Helper::get_transient_version( 'shipping', true );
		update_option( 'fx_ship_rules_v1', '1', false );
	}
} );
