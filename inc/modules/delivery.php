<?php
/**
 * Termin wysyłki i dostawy na podstawie magazynów BaseLinkera.
 *
 * Przeniesione z wtyczki/fragmentu „FOX – Termin wysyłki i dostawy” 1.2.1 – te same ustawienia
 * (Ustawienia → Termin wysyłki FOX, opcja fox_bl_delivery_settings_v1, token zostaje),
 * te same pola zamówienia dla BaseLinkera (szacowana_wysylka, szacowana_dostawa,
 * _fox_dispatch_date, _fox_delivery_date).
 *
 * Zmiany względem fragmentu: wygląd w stylu motywu (style.css), polskie święta liczone
 * automatycznie, termin także w oknie „Produkt został dodany do koszyka”.
 * Komunikat na karcie produktu: inc/modules/ship-cta.php (korzysta z tych ustawień).
 *
 * Gdy fragment/wtyczka z tym samym kodem jest nadal włączona, motyw nic nie dubluje
 * (a fragment sam się wyłącza, bo sprawdza FOX_BL_DELIVERY_VERSION).
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

// Funkcje są w osobnym pliku: PHP deklaruje funkcje z pliku jeszcze przed jego wykonaniem,
// więc samo „return” nie uchroniłoby przed błędem „Cannot redeclare”.
if ( ! defined( 'FOX_BL_DELIVERY_VERSION' ) && ! function_exists( 'fox_bl_delivery_get_settings' ) ) {
	require_once __DIR__ . '/delivery-core.php';
}
