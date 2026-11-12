<?php
/**
 * Plugin Name: FOX – Asystent AI
 * Description: Czat z asystentem AI dla klientów sklepu: dobór sprzętu i rozmiaru z tabel konkretnego modelu, karty produktów, status zamówienia (BaseLinker), przekazanie do obsługi, „Zostaw numer”. Fakty (produkty, ceny, rozmiary, stany) zawsze ze sklepu, model (OpenAI) tylko prowadzi rozmowę. Panel: WooCommerce → Asystent AI.
 * Version: 1.3.0
 * Author: FOX.SKLEP.PL
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 7.0
 * Text Domain: fox-asystent-ai
 *
 * Wydzielone z motywu FOX.SKLEP.PL (do wersji 3.44.0 asystent był modułem motywu). Te same ustawienia i tabele
 * w bazie – rozmowy, statystyki i instrukcje z zakładki „Trening” zostają.
 *
 * Z motywu FOX.SKLEP.PL korzysta, gdy jest (wyszukiwarka z synonimami, tabele rozmiarów, „Wysyłka dziś” / sklep,
 * Przeceny, „dla kogo”); bez niego działa prościej (wyszukiwanie WooCommerce, bez tabel i stanów sklepu).
 *
 * Bezpieczeństwo: klucz OpenAI tylko w ustawieniach albo w wp-config.php (FOX_AI_KEY) – nigdy w kodzie ani w przeglądarce.
 *
 * @package fox-asystent-ai
 */

defined( 'ABSPATH' ) || exit;

define( 'FXA_VER', '1.3.0' );
define( 'FXA_FILE', __FILE__ );
define( 'FXA_DIR', __DIR__ . '/includes/' );
define( 'FXA_URL', plugin_dir_url( __FILE__ ) );

add_action( 'before_woocommerce_init', function () {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

/*
 * Ładowanie po motywie: starszy motyw FOX.SKLEP.PL (do 3.44.0) ma asystenta w sobie – wtedy wtyczka się nie
 * ładuje (dwa asystenty = błąd krytyczny) i prosi o aktualizację motywu.
 */
add_action( 'after_setup_theme', function () {
	if ( function_exists( 'fx_ai_settings' ) || defined( 'FX_AI_DIR' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-warning"><p><strong>FOX – Asystent AI:</strong> asystent działa jeszcze z motywu. Zaktualizuj motyw FOX.SKLEP.PL do wersji 3.45.0 lub nowszej (bez asystenta) – wtedy przejmie go ta wtyczka, z tymi samymi ustawieniami i rozmowami.</p></div>';
		} );
		return;
	}
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p><strong>FOX – Asystent AI</strong> wymaga WooCommerce.</p></div>';
		} );
		return;
	}
	define( 'FX_AI_DIR', FXA_DIR );
	require FXA_DIR . 'core.php';
	require FXA_DIR . 'tools.php';
	require FXA_DIR . 'order.php';
	require FXA_DIR . 'web.php';
	require FXA_DIR . 'ai.php';
	if ( is_admin() ) {
		require FXA_DIR . 'admin.php';
	}
}, 20 );

/* Link „Ustawienia” na liście wtyczek. */
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
	array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=fx-ai' ) ) . '">Ustawienia</a>' );
	return $links;
} );
