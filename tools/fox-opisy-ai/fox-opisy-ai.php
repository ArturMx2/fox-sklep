<?php
/**
 * Plugin Name: FOX – Opisy produktów AI
 * Description: Opisy produktów (główny, krótki, meta) pisane przez AI wyłącznie z faktów ze sprawdzonych źródeł: BaseLinker (opis i parametry dystrybutora), foxracing.com i zaufane sklepy, atrybuty, zdjęcie. Automat dla produktów dostępnych, kopia i przywracanie, poprawa opisów ze starego generatora v18.
 * Version: 1.0.0
 * Author: FOX.SKLEP.PL
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Text Domain: fox-opisy-ai
 *
 * Klucze API NIE są w kodzie: Ustawienia wtyczki albo stałe w wp-config.php
 * (FOX_OPISY_AI_KEY / FOX_IMG_AI_KEY – OpenAI, FOX_OPISY_SERPER_KEY – Serper).
 *
 * @package fox-opisy-ai
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'FXD_VER' ) || function_exists( 'fxd_defaults' ) ) {
	add_action( 'admin_notices', function () {
		echo '<div class="notice notice-error"><p><strong>FOX – Opisy produktów AI:</strong> wtyczka jest wgrana dwa razy. Zostaw jedną kopię.</p></div>';
	} );
	return;
}

define( 'FXD_VER', '1.0.0' );
define( 'FXD_FILE', __FILE__ );

require __DIR__ . '/includes/sources.php';
require __DIR__ . '/includes/writer.php';
require __DIR__ . '/includes/admin.php';
