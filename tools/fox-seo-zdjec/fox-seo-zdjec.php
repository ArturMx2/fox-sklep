<?php
/**
 * Plugin Name: FOX – SEO zdjęć produktów
 * Description: Opisy zdjęć produktów przez AI (alt, podpis, opis), bezpieczna naprawa miniatur, audyt zdjęć, zdjęcia z galerii w danych strukturalnych produktu. Zastępuje fragment kodu „FOX SEO + Miniatury”.
 * Version: 1.3.0
 * Author: FOX.SKLEP.PL
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 7.0
 * Text Domain: fox-seo-zdjec
 *
 * Bezpieczeństwo:
 * - klucz API NIE jest w kodzie: Ustawienia wtyczki albo stała FOX_IMG_AI_KEY w wp-config.php,
 * - każde wywołanie AJAX: nonce + uprawnienie manage_woocommerce, blokada przed równoległym uruchomieniem,
 * - miniatury: nic nie jest kasowane ani zmieniana nazwa pliku; dorabiane są tylko brakujące rozmiary,
 *   a metadane są zapisywane z kopią zapasową i przywracane, gdy generowanie się nie uda.
 *
 * @package fox-seo-zdjec
 */

defined( 'ABSPATH' ) || exit;

/*
 * Druga kopia wtyczki (np. stary folder) albo stary fragment kodu „FOX SEO + Miniatury” z tymi samymi
 * funkcjami = błąd krytyczny całej strony. Wtedy ta kopia się nie ładuje i pokazuje ostrzeżenie w panelu.
 */
if ( defined( 'FXI_VER' ) || function_exists( 'fxi_defaults' ) ) {
	add_action( 'admin_notices', function () {
		echo '<div class="notice notice-error"><p><strong>FOX – SEO zdjęć:</strong> wtyczka jest wgrana dwa razy albo stary fragment kodu „FOX SEO + Miniatury” jest nadal włączony. Zostaw tylko jedną kopię (Wtyczki / Code Snippets).</p></div>';
	} );
	return;
}

define( 'FXI_FILE', __FILE__ );
require __DIR__ . '/includes/core.php';

