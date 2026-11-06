<?php
/**
 * Asystent AI dla klientów (czat w dymku) – doradztwo przy zakupie FOX, status zamówienia, przekazanie do obsługi.
 *
 * Zasada: model (OpenAI) tylko prowadzi rozmowę – fakty (produkty, ceny, rozmiary, stany, terminy, statusy)
 * pochodzą ze sklepu przez narzędzia (function calling). Panel: WooCommerce → Asystent AI.
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

define( 'FX_AI_DIR', __DIR__ . '/assistant/' );

require FX_AI_DIR . 'core.php';
require FX_AI_DIR . 'tools.php';
require FX_AI_DIR . 'order.php';
require FX_AI_DIR . 'ai.php';
if ( is_admin() ) {
	require FX_AI_DIR . 'admin.php';
}
