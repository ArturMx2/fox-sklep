<?php
/**
 * Asystent AI – status zamówienia, zwrotu i przesyłki (BaseLinker, zapas: WooCommerce).
 * Weryfikacja: numer zamówienia + telefon (ostatnie 9 cyfr). Bez danych osobowych dla modelu.
 *
 * @package fox-asystent-ai
 */

defined( 'ABSPATH' ) || exit;

define( 'FX_AI_STATUS_OPT', 'fx_ai_status' ); // [ status_id => [ name, text, handoff ] ]

function fx_ai_bl( $method, $params ) {
	if ( ! function_exists( 'fx_ls_call' ) || ! function_exists( 'fx_ls_api' ) ) {
		return new WP_Error( 'fx_ai_bl', 'Brak połączenia z BaseLinkerem.' );
	}
	list( $token ) = fx_ls_api();
	if ( '' === (string) $token ) {
		return new WP_Error( 'fx_ai_bl', 'Brak tokenu BaseLinkera (Termin wysyłki FOX).' );
	}
	return fx_ls_call( $method, $params );
}

function fx_ai_phone9( $p ) {
	$d = preg_replace( '/\D/', '', (string) $p );
	return strlen( $d ) >= 9 ? substr( $d, -9 ) : '';
}

/** Statusy zamówień z BaseLinkera (cache 6 h): [ id => [ name, name_for_customer ] ]. */
function fx_ai_bl_statuses( $fresh = false ) {
	$c = get_transient( 'fx_ai_bl_st' );
	if ( ! $fresh && is_array( $c ) ) {
		return $c;
	}
	$r = fx_ai_bl( 'getOrderStatusList', array() );
	if ( is_wp_error( $r ) ) {
		return is_array( $c ) ? $c : array();
	}
	$out = array();
	foreach ( (array) ( $r['statuses'] ?? array() ) as $st ) {
		$out[ (int) $st['id'] ] = array( 'name' => (string) ( $st['name'] ?? '' ), 'customer' => (string) ( $st['name_for_customer'] ?? '' ) );
	}
	set_transient( 'fx_ai_bl_st', $out, 6 * HOUR_IN_SECONDS );
	return $out;
}

/** Podpowiedź wytycznej po nazwie statusu: [ tekst, przekazać do obsługi ]. */
function fx_ai_status_hint( $name ) {
	$n     = remove_accents( mb_strtolower( (string) $name, 'UTF-8' ) );
	$rules = array(
		'/anul/'                             => array( 'Zamówienie jest anulowane. Jeśli klient ma pytania albo czeka na zwrot pieniędzy – zaproponuj kontakt z obsługą.', 1 ),
		'/zwrot|reklam|wymian/'              => array( 'Sprawa zwrotu / reklamacji jest w toku. Szczegóły i terminy ustala obsługa – zaproponuj kontakt.', 1 ),
		'/oplac|platn|przelew|czeka.*wplat/' => array( 'Czekamy na płatność. Po zaksięgowaniu wpłaty zamówienie przejdzie do realizacji.', 0 ),
		'/odbior|osobist/'                   => array( 'Zamówienie czeka na odbiór w sklepie stacjonarnym w Mokronosie Dolnym (godziny otwarcia – zasady sklepu).', 0 ),
		'/dostaw|dystryb|zamowion|brak|oczek/' => array( 'Czekamy na dostawę części towaru do nas. Wyślemy zamówienie zaraz po dostawie – nie podawaj daty, jeśli jej nie ma.', 0 ),
		'/wysl|nadan|w drodze|kurier/'       => array( 'Paczka jest nadana – podaj numer przesyłki i link do śledzenia.', 0 ),
		'/dostarcz|odebran|zakoncz|zrealizow/' => array( 'Zamówienie zostało zrealizowane / dostarczone. Jeśli klient go nie ma – zaproponuj kontakt z obsługą.', 0 ),
		'/realiz|kompl|pakow|przygotow|magazyn/' => array( 'Zamówienie jest w realizacji – kompletujemy i pakujemy. Numer przesyłki klient dostanie e-mailem po nadaniu.', 0 ),
		'/now|przyjet|potwierdz/'            => array( 'Zamówienie przyjęte, przygotowujemy je do realizacji. Numer przesyłki klient dostanie e-mailem po nadaniu.', 0 ),
	);
	foreach ( $rules as $re => $v ) {
		if ( preg_match( $re, $n ) ) {
			return $v;
		}
	}
	return array( '', 0 );
}

/** Link do śledzenia po kurierze. */
function fx_ai_tracking( $courier, $nr ) {
	$c  = strtolower( (string) $courier );
	$nr = rawurlencode( trim( (string) $nr ) );
	if ( false !== strpos( $c, 'inpost' ) || false !== strpos( $c, 'paczkomat' ) ) {
		return 'https://inpost.pl/sledzenie-przesylek?number=' . $nr;
	}
	if ( false !== strpos( $c, 'dpd' ) ) {
		return 'https://tracktrace.dpd.com.pl/parcelDetails?typ=1&p1=' . $nr;
	}
	if ( false !== strpos( $c, 'dhl' ) ) {
		return 'https://www.dhl.com/pl-pl/home/sledzenie.html?tracking-id=' . $nr;
	}
	if ( false !== strpos( $c, 'gls' ) ) {
		return 'https://gls-group.com/PL/pl/sledzenie-paczek?match=' . $nr;
	}
	if ( false !== strpos( $c, 'poczta' ) || false !== strpos( $c, 'pocztex' ) ) {
		return 'https://emonitoring.poczta-polska.pl/?numer=' . $nr;
	}
	return '';
}

/** Numery przesyłek z wtyczki Olek PackingList (bez kodów odbioru). */
function fx_ai_opa_parcels( $order_id ) {
	global $wpdb;
	$out  = array();
	$meta = $order_id ? get_post_meta( $order_id, '_opa_parcel', true ) : '';
	if ( ! $meta && $order_id && function_exists( 'wc_get_order' ) ) {
		$o    = wc_get_order( $order_id );
		$meta = $o ? $o->get_meta( '_opa_parcel' ) : '';
	}
	foreach ( (array) $meta as $k => $v ) {
		if ( is_string( $v ) && preg_match( '/^[A-Z0-9]{8,40}$/i', trim( $v ) ) && ! preg_match( '/code|kod|pin/i', (string) $k ) ) {
			$out[] = trim( $v );
		}
	}
	$t = $wpdb->prefix . 'opa_parcels';
	if ( $order_id && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t ) {
		$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$t}" ); // phpcs:ignore
		$nc   = array_values( array_filter( $cols, function ( $c ) {
			return preg_match( '/(parcel|tracking|package)_?(nr|no|number)?$|^number$/i', $c ) && ! preg_match( '/code|kod|pin/i', $c );
		} ) );
		$oc   = array_values( array_filter( $cols, function ( $c ) {
			return preg_match( '/^(wc_)?order_?id$/i', $c );
		} ) );
		if ( $nc && $oc ) {
			foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT `{$nc[0]}` FROM {$t} WHERE `{$oc[0]}` = %d LIMIT 5", $order_id ) ) as $v ) { // phpcs:ignore
				if ( $v ) {
					$out[] = (string) $v;
				}
			}
		}
	}
	return array_values( array_unique( $out ) );
}

function fx_ai_tool_order( $numer, $telefon ) {
	$s     = fx_ai_settings();
	$phone = $s['phone'];
	$num   = preg_replace( '/\D/', '', (string) $numer );
	if ( '' === $num ) {
		return array( 'blad' => 'Bez numeru zamówienia nie sprawdzam statusu. Poproś o numer z e-maila potwierdzającego albo o telefon do sklepu: ' . $phone );
	}
	$tel = fx_ai_phone9( $telefon );
	if ( '' === $tel ) {
		return array( 'blad' => 'Poproś o numer telefonu podany przy zamówieniu.' );
	}
	if ( ! fx_ai_hit( 'order|' . fx_ai_ip(), 6, HOUR_IN_SECONDS ) ) {
		return array( 'blad' => 'Za dużo prób sprawdzenia zamówienia. Poproś klienta o telefon do sklepu: ' . $phone );
	}
	$wc     = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $num ) : null;
	$wc     = $wc instanceof WC_Order ? $wc : null;
	$bl     = null;
	$bl_err = '';
	foreach ( array( array( 'filter_shop_order_id' => (int) $num ), array( 'order_id' => (int) $num ), array( 'filter_external_order_id' => (string) $num ) ) as $f ) {
		$r = fx_ai_bl( 'getOrders', $f + array( 'get_unconfirmed_orders' => true ) );
		if ( is_wp_error( $r ) ) {
			$bl_err = $r->get_error_message();
			break;
		}
		if ( ! empty( $r['orders'][0] ) ) {
			$bl = $r['orders'][0];
			break;
		}
	}
	// Weryfikacja telefonu.
	$phones = array();
	if ( $bl ) {
		$phones[] = fx_ai_phone9( $bl['phone'] ?? '' );
	}
	if ( $wc ) {
		$phones[] = fx_ai_phone9( $wc->get_billing_phone() );
		$phones[] = fx_ai_phone9( method_exists( $wc, 'get_shipping_phone' ) ? $wc->get_shipping_phone() : '' );
	}
	if ( ! $bl && ! $wc ) {
		return array( 'blad' => 'Nie znalazłem zamówienia o tym numerze. Poproś o sprawdzenie numeru (z e-maila potwierdzającego) albo o telefon do sklepu: ' . $phone );
	}
	if ( ! in_array( $tel, array_filter( $phones ), true ) ) {
		return array( 'blad' => 'Numer telefonu nie zgadza się z zamówieniem. Nie podawaj żadnych informacji o zamówieniu. Poproś o telefon podany przy zamówieniu albo kontakt telefoniczny: ' . $phone );
	}
	$out = array( 'zamowienie' => $num );
	if ( $bl ) {
		$out['zrodlo']  = 'BaseLinker';
		$sid            = (int) ( $bl['order_status_id'] ?? 0 );
		$names          = fx_ai_bl_statuses();
		$rules          = get_option( FX_AI_STATUS_OPT, array() );
		$name           = $names[ $sid ]['customer'] ?? '';
		$name           = '' !== $name ? $name : ( $names[ $sid ]['name'] ?? '' );
		$out['status']  = '' !== $name ? $name : 'w realizacji';
		$rule           = is_array( $rules ) && isset( $rules[ $sid ] ) ? $rules[ $sid ] : array();
		if ( ! empty( $rule['text'] ) ) {
			$out['wytyczna'] = $rule['text'];
		}
		if ( ! empty( $rule['handoff'] ) ) {
			$out['zaproponuj_obsluge'] = true;
		}
		$out['data_zamowienia'] = ! empty( $bl['date_add'] ) ? wp_date( 'j.m.Y', (int) $bl['date_add'] ) : '';
		// Przesyłki.
		$pk = array();
		if ( ! empty( $bl['delivery_package_nr'] ) ) {
			$pk[ $bl['delivery_package_nr'] ] = (string) ( $bl['delivery_package_module'] ?? $bl['delivery_method'] ?? '' );
		}
		$r = fx_ai_bl( 'getOrderPackages', array( 'order_id' => (int) $bl['order_id'] ) );
		if ( ! is_wp_error( $r ) ) {
			foreach ( (array) ( $r['packages'] ?? array() ) as $p ) {
				if ( ! empty( $p['courier_package_nr'] ) ) {
					$pk[ $p['courier_package_nr'] ] = (string) ( $p['courier_code'] ?? '' );
				}
			}
		}
		// Zwroty.
		$r = fx_ai_bl( 'getOrderReturns', array( 'order_id' => (int) $bl['order_id'] ) );
		if ( ! is_wp_error( $r ) && ! empty( $r['returns'] ) ) {
			$rs = get_transient( 'fx_ai_bl_rst' );
			if ( ! is_array( $rs ) ) {
				$rs = array();
				$rl = fx_ai_bl( 'getOrderReturnStatusList', array() );
				foreach ( is_wp_error( $rl ) ? array() : (array) ( $rl['statuses'] ?? array() ) as $st ) {
					$rs[ (int) $st['id'] ] = (string) ( $st['name'] ?? '' );
				}
				set_transient( 'fx_ai_bl_rst', $rs, 6 * HOUR_IN_SECONDS );
			}
			$out['zwroty'] = array();
			foreach ( (array) $r['returns'] as $ret ) {
				$out['zwroty'][] = array(
					'status' => $rs[ (int) ( $ret['status_id'] ?? 0 ) ] ?? 'w realizacji',
					'data'   => ! empty( $ret['date_add'] ) ? wp_date( 'j.m.Y', (int) $ret['date_add'] ) : '',
				);
			}
		}
	} else {
		$out['zrodlo'] = 'WooCommerce';
		$out['status'] = wc_get_order_status_name( $wc->get_status() );
		$out['uwaga']  = 'Status ze sklepu – może być mniej dokładny niż w systemie magazynowym' . ( $bl_err ? ' (BaseLinker chwilowo nie odpowiada)' : '' ) . '.';
		$pk            = array();
	}
	if ( ! $pk ) {
		foreach ( fx_ai_opa_parcels( $wc ? $wc->get_id() : 0 ) as $nr ) {
			$pk[ $nr ] = 'inpost';
		}
	}
	if ( $pk ) {
		$out['przesylki'] = array();
		foreach ( $pk as $nr => $courier ) {
			$out['przesylki'][] = array_filter( array( 'numer' => (string) $nr, 'kurier' => $courier, 'sledzenie' => fx_ai_tracking( $courier, $nr ) ) );
		}
	} else {
		$out['przesylki_info'] = 'Paczka nie jest jeszcze nadana – numer przesyłki klient dostanie e-mailem wkrótce. Nie podawaj daty.';
	}
	$out['zasada'] = 'Odpowiadaj zgodnie z wytyczną i statusem; nie obiecuj niczego spoza tych danych. Nie podawaj adresu ani danych osobowych.';
	return $out;
}
