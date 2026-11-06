<?php
/**
 * Asystent AI – ustawienia, tabele, REST, dymek, pomiar zamówień.
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

define( 'FX_AI_OPT', 'fx_ai_settings' );
define( 'FX_AI_DB', 2 );

/* =====================================================================
 * Ustawienia
 * ===================================================================== */

function fx_ai_defaults() {
	return array(
		'on'         => 0,
		'test'       => 1,      // tylko administratorzy widzą dymek
		'key'        => '',
		'model'      => 'gpt-4.1-mini',
		'name'       => 'Asystent FOX',
		'greeting'   => 'Cześć! Pomogę dobrać sprzęt FOX na moto albo rower, sprawdzę rozmiary i status zamówienia. W czym pomóc?',
		'quick'      => "Pomóż dobrać komplet MX\nKask rowerowy – jaki rozmiar?\nGdzie jest moje zamówienie?\nDostawa i zwroty",
		'rules'      => '',
		'email'      => '',
		'phone'      => '+48 71 880 85 54',
		'privacy'    => '',
		'lim_ip'     => 40,     // wiadomości na 15 min z IP
		'lim_day'    => 3000,   // wiadomości dziennie w sklepie
		'lim_conv'   => 40,     // wiadomości klienta w jednej rozmowie
		'keep_days'  => 0,      // 0 = archiwum bez usuwania
		'price_in'   => 0.40,   // $ za 1 mln tokenów wejścia
		'price_out'  => 1.60,   // $ za 1 mln tokenów wyjścia
	);
}

function fx_ai_settings() {
	static $s = null;
	if ( null === $s || ! empty( $GLOBALS['fx_ai_reload'] ) ) {
		$GLOBALS['fx_ai_reload'] = false;
		$v = get_option( FX_AI_OPT, array() );
		$s = array_merge( fx_ai_defaults(), is_array( $v ) ? $v : array() );
	}
	return $s;
}

/** Klucz OpenAI: stała w wp-config.php albo ustawienie. */
function fx_ai_key() {
	if ( defined( 'FOX_AI_KEY' ) && FOX_AI_KEY ) {
		return (string) FOX_AI_KEY;
	}
	return trim( (string) fx_ai_settings()['key'] );
}

/** Czy dymek ma działać dla bieżącego odwiedzającego. */
function fx_ai_enabled_for_user() {
	$s = fx_ai_settings();
	if ( empty( $s['on'] ) || '' === fx_ai_key() ) {
		return false;
	}
	return empty( $s['test'] ) || current_user_can( 'manage_woocommerce' );
}

function fx_ai_table( $t ) {
	global $wpdb;
	return $wpdb->prefix . ( 'kb' === $t ? 'fox_ai_kb' : 'fox_ai_chat' );
}

/* =====================================================================
 * Tabele i instrukcje startowe
 * ===================================================================== */

add_action( 'init', function () {
	if ( (int) get_option( 'fx_ai_db' ) !== FX_AI_DB ) {
		fx_ai_install();
	}
} );

function fx_ai_install() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$c    = $wpdb->get_charset_collate();
	$chat = fx_ai_table( 'chat' );
	$kb   = fx_ai_table( 'kb' );
	dbDelta( "CREATE TABLE {$chat} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		sid VARCHAR(40) NOT NULL DEFAULT '',
		started DATETIME NOT NULL,
		updated DATETIME NOT NULL,
		page VARCHAR(255) NOT NULL DEFAULT '',
		first_q TEXT NULL,
		msgs INT NOT NULL DEFAULT 0,
		tok_in INT NOT NULL DEFAULT 0,
		tok_out INT NOT NULL DEFAULT 0,
		tools VARCHAR(255) NOT NULL DEFAULT '',
		shown TEXT NULL,
		notfound TEXT NULL,
		handoff TINYINT(1) NOT NULL DEFAULT 0,
		clicks INT NOT NULL DEFAULT 0,
		carts INT NOT NULL DEFAULT 0,
		order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
		order_val DECIMAL(12,2) NOT NULL DEFAULT 0,
		bought_rec TINYINT(1) NOT NULL DEFAULT 0,
		test TINYINT(1) NOT NULL DEFAULT 0,
		log LONGTEXT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY sid (sid),
		KEY started (started),
		KEY order_id (order_id)
	) {$c};" );
	dbDelta( "CREATE TABLE {$kb} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		type VARCHAR(10) NOT NULL DEFAULT 'ins',
		title VARCHAR(255) NOT NULL DEFAULT '',
		keywords TEXT NULL,
		body TEXT NULL,
		question TEXT NULL,
		answer_bad TEXT NULL,
		always TINYINT(1) NOT NULL DEFAULT 0,
		active TINYINT(1) NOT NULL DEFAULT 1,
		used INT NOT NULL DEFAULT 0,
		chat_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
		created DATETIME NOT NULL,
		PRIMARY KEY  (id),
		KEY type (type)
	) {$c};" );
	if ( ! get_option( 'fx_ai_seeded' ) ) {
		foreach ( fx_ai_starter_kb() as $row ) {
			$wpdb->insert( $kb, $row + array( 'type' => 'ins', 'active' => 1, 'created' => current_time( 'mysql' ) ) );
		}
		update_option( 'fx_ai_seeded', 1, false );
	}
	update_option( 'fx_ai_db', FX_AI_DB, false );
}

/** Instrukcje startowe dla foxa (do edycji w zakładce „Trening”). */
function fx_ai_starter_kb() {
	return array(
		array(
			'title'    => 'Badanie potrzeb',
			'always'   => 1,
			'keywords' => '',
			'body'     => "Zanim polecisz sprzęt, ustal po kolei – pytaj tylko o to, czego klient jeszcze nie powiedział, maksymalnie 2 pytania naraz:\n1. Świat i dyscyplina: Moto (MX/cross, enduro, ADV/turystyka, quad) czy Rower (MTB enduro, trail, downhill, gravel/XC, rekreacja); rekreacyjnie czy zawody.\n2. Dla kogo: mężczyzna, kobieta, junior/dziecko (osobne rozmiarówki młodzieżowe).\n3. Rodzaj produktu: komplet (bluza + spodnie), kask, gogle, buty, rękawice, ochraniacze, casual.\n4. Rozmiar: spodnie moto w calach (28–38), bluzy i odzież S–XXL, kaski po obwodzie głowy, buty w rozmiarach US. Gdy klient nie zna rozmiaru – tabela rozmiarów na karcie produktu albo telefon do sklepu.\n5. Kolor / grafika (pod motocykl, rower albo w komplecie z resztą stroju).\n6. Budżet orientacyjny.\n7. Ważne cechy: wentylacja, wodoodporność, poziom ochrony (kask: MIPS, homologacja), waga.\nGdy znasz świat, rodzaj i rozmiar – pokaż 2–4 produkty i zaproponuj dobranie reszty kompletu w tej samej grafice. Klienta, który wie, czego chce, nie przesłuchuj.",
		),
		array(
			'title'    => 'Dobór kasku',
			'always'   => 0,
			'keywords' => 'kask, kaski, helmet, obwód, głowy, mips, v1, v3, rampage, proframe, speedframe, dropframe',
			'body'     => "Kask dobiera się po obwodzie głowy: zmierz centymetrem krawieckim nad brwiami i uszami, najszerszy obwód. Zapytaj o obwód i porównaj z tabelą rozmiarów produktu (szczegoly_produktu). Gdy wynik jest na granicy dwóch rozmiarów – zaproponuj kontakt ze sklepem albo przymiarkę w sklepie stacjonarnym.\nRozróżnij świat: kask moto (MX/enduro, homologacja ECE 22.06) i rowerowy (MTB: otwarty trail/enduro, full face do enduro/DH). Kask moto nie jest do roweru i odwrotnie.\nMIPS i podobne systemy ochrony – wspominaj tylko, jeśli są w danych produktu.",
		),
		array(
			'title'    => 'Rozmiary FOX',
			'always'   => 0,
			'keywords' => 'rozmiar, rozmiary, rozmiarówka, rozmiaru, size, jaki rozmiar, uciska, mały, duży, tabela',
			'body'     => "Rozmiary FOX: spodnie moto w calach w pasie (28, 30, 32, 34, 36, 38), bluzy i odzież S–XXL, rozmiary młodzieżowe z literą Y (YS, YM, YL, YXL), buty w rozmiarach US, rękawice S–XXL (młodzieżowe YS–YL). Rozmiar zawsze sprawdzaj w tabeli rozmiarów produktu (szczegoly_produktu) – nie zgaduj. Jeśli klient jest pomiędzy rozmiarami – podaj obie opcje i zaproponuj telefon do sklepu.",
		),
		array(
			'title'    => 'Komplety w jednej grafice',
			'always'   => 0,
			'keywords' => 'komplet, strój, zestaw, bluza, spodnie, grafika, pasuje, dobrać, do kompletu, jersey, pants',
			'body'     => "Komplety FOX (bluza + spodnie, często rękawice) są w tych samych grafikach – np. „180 Nitro”, „360 Horyzn”. Gdy klient wybrał bluzę albo spodnie, sprawdź w szczegoly_produktu pole „komplet” i zaproponuj brakujące części w tej samej grafice. Za cały komplet w koszyku jest rabat (jeśli szczegoly_produktu go podaje – powiedz o nim).",
		),
	);
}

/* =====================================================================
 * Rozmowy
 * ===================================================================== */

function fx_ai_chat_get( $sid ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . fx_ai_table( 'chat' ) . ' WHERE sid = %s', $sid ), ARRAY_A ); // phpcs:ignore
}

/** Zapis tury rozmowy. */
function fx_ai_chat_save( $sid, array $turn, array $meta ) {
	global $wpdb;
	$t   = fx_ai_table( 'chat' );
	$row = fx_ai_chat_get( $sid );
	$now = current_time( 'mysql' );
	if ( ! $row ) {
		$wpdb->insert( $t, array(
			'sid'     => $sid,
			'started' => $now,
			'updated' => $now,
			'page'    => mb_substr( (string) ( $meta['page'] ?? '' ), 0, 250 ),
			'first_q' => mb_substr( (string) ( $turn['q'] ?? '' ), 0, 500 ),
			'test'    => ! empty( $meta['test'] ) ? 1 : 0,
			'log'     => '[]',
		) );
		$row = fx_ai_chat_get( $sid );
	}
	$log   = json_decode( (string) $row['log'], true );
	$log   = is_array( $log ) ? $log : array();
	$log[] = array( 'r' => 'user', 't' => (string) $turn['q'], 'at' => time() );
	$log[] = array( 'r' => 'assistant', 't' => (string) $turn['a'], 'cards' => $turn['cards'], 'tools' => $turn['tools'], 'kb' => $turn['kb'], 'at' => time() );
	$shown = array_filter( array_map( 'intval', explode( ',', (string) $row['shown'] ) ) );
	$shown = array_values( array_unique( array_merge( $shown, array_map( 'intval', $turn['cards'] ) ) ) );
	$tools = array_filter( explode( ',', (string) $row['tools'] ) );
	$tools = array_values( array_unique( array_merge( $tools, $turn['tools'] ) ) );
	$nf    = array_filter( explode( "\n", (string) $row['notfound'] ) );
	$nf    = array_values( array_unique( array_merge( $nf, $turn['notfound'] ) ) );
	$wpdb->update( $t, array(
		'updated'  => $now,
		'msgs'     => (int) $row['msgs'] + 1,
		'tok_in'   => (int) $row['tok_in'] + (int) $turn['tok_in'],
		'tok_out'  => (int) $row['tok_out'] + (int) $turn['tok_out'],
		'tools'    => mb_substr( implode( ',', $tools ), 0, 250 ),
		'shown'    => implode( ',', array_slice( $shown, -200 ) ),
		'notfound' => implode( "\n", array_slice( $nf, -50 ) ),
		'handoff'  => ( (int) $row['handoff'] || ! empty( $turn['handoff'] ) ) ? 1 : 0,
		'log'      => wp_json_encode( $log ),
	), array( 'id' => (int) $row['id'] ) );
}

/** Ostatnie błędy połączenia (panel → Statystyki). */
function fx_ai_log_error( $msg ) {
	$log = get_option( 'fx_ai_errors', array() );
	$log = is_array( $log ) ? $log : array();
	array_unshift( $log, array( time(), (string) $msg ) );
	update_option( 'fx_ai_errors', array_slice( $log, 0, 20 ), false );
}

/* =====================================================================
 * Limity
 * ===================================================================== */

function fx_ai_ip() {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	return md5( $ip . wp_salt( 'nonce' ) );
}

/** Licznik w oknie czasu; false = limit przekroczony. */
function fx_ai_hit( $bucket, $max, $window ) {
	if ( $max <= 0 ) {
		return true;
	}
	$k = 'fx_ai_l_' . md5( $bucket );
	$v = get_transient( $k );
	$v = is_array( $v ) ? $v : array( 0, time() );
	if ( $v[1] + $window < time() ) {
		$v = array( 0, time() );
	}
	if ( $v[0] >= $max ) {
		return false;
	}
	$v[0]++;
	set_transient( $k, $v, $window );
	return true;
}

/* =====================================================================
 * REST
 * ===================================================================== */

add_action( 'rest_api_init', function () {
	register_rest_route( 'fox/v1', '/asystent', array(
		'methods'             => 'POST',
		'callback'            => 'fx_ai_rest_chat',
		'permission_callback' => '__return_true',
	) );
	register_rest_route( 'fox/v1', '/asystent-ev', array(
		'methods'             => 'POST',
		'callback'            => 'fx_ai_rest_event',
		'permission_callback' => '__return_true',
	) );
} );

function fx_ai_sid_ok( $sid ) {
	return (bool) preg_match( '/^[a-z0-9]{12,40}$/i', (string) $sid );
}

function fx_ai_rest_chat( WP_REST_Request $req ) {
	$s = fx_ai_settings();
	if ( ! fx_ai_enabled_for_user() ) {
		return new WP_REST_Response( array( 'text' => 'Asystent jest chwilowo niedostępny. Zadzwoń: ' . $s['phone'] . '.', 'cards' => array() ), 200 );
	}
	$sid = (string) $req->get_param( 'sid' );
	if ( ! fx_ai_sid_ok( $sid ) ) {
		return new WP_REST_Response( array( 'error' => 'sid' ), 400 );
	}
	// Historia: tylko user/assistant, sam tekst, ostatnie 24, maks. 800 znaków.
	$msgs = array();
	foreach ( array_slice( (array) $req->get_param( 'messages' ), -24 ) as $m ) {
		if ( ! is_array( $m ) || ! in_array( $m['role'] ?? '', array( 'user', 'assistant' ), true ) ) {
			continue;
		}
		$text = trim( mb_substr( wp_strip_all_tags( (string) ( $m['content'] ?? '' ) ), 0, 800 ) );
		if ( '' !== $text ) {
			$msgs[] = array( 'role' => $m['role'], 'content' => $text );
		}
	}
	if ( ! $msgs || 'user' !== end( $msgs )['role'] ) {
		return new WP_REST_Response( array( 'error' => 'messages' ), 400 );
	}
	$busy = 'Dużo pytań naraz – spróbuj za chwilę albo zadzwoń: ' . $s['phone'] . '.';
	if ( ! fx_ai_hit( 'ip|' . fx_ai_ip(), (int) $s['lim_ip'], 15 * MINUTE_IN_SECONDS ) || ! fx_ai_hit( 'day|' . gmdate( 'Ymd' ), (int) $s['lim_day'], DAY_IN_SECONDS ) ) {
		return new WP_REST_Response( array( 'text' => $busy, 'cards' => array() ), 200 );
	}
	$row = fx_ai_chat_get( $sid );
	if ( $row && (int) $s['lim_conv'] > 0 && (int) $row['msgs'] >= (int) $s['lim_conv'] ) {
		return new WP_REST_Response( array( 'text' => 'Ta rozmowa jest już bardzo długa. Napisz do nas albo zadzwoń: ' . $s['phone'] . ' – chętnie pomożemy.', 'cards' => array() ), 200 );
	}
	$ctx = array(
		'sid'     => $sid,
		'product' => absint( $req->get_param( 'product' ) ),
		'page'    => esc_url_raw( (string) $req->get_param( 'page' ) ),
	);
	$out = fx_ai_answer( $msgs, $ctx );
	fx_ai_chat_save( $sid, array(
		'q'        => end( $msgs )['content'],
		'a'        => $out['text'],
		'cards'    => wp_list_pluck( $out['cards'], 'id' ),
		'tools'    => $out['tools'],
		'kb'       => $out['kb'],
		'notfound' => $out['notfound'],
		'handoff'  => $out['handoff'],
		'tok_in'   => $out['tok_in'],
		'tok_out'  => $out['tok_out'],
	), array( 'page' => $ctx['page'], 'test' => ! empty( $s['test'] ) ) );
	$res = new WP_REST_Response( array( 'text' => $out['text'], 'cards' => $out['cards'], 'wa' => $out['wa'] ), 200 );
	$res->header( 'Cache-Control', 'no-store' );
	return $res;
}

/** Zdarzenia z dymku: klik w kartę, dodanie do koszyka. */
function fx_ai_rest_event( WP_REST_Request $req ) {
	global $wpdb;
	$sid  = (string) $req->get_param( 'sid' );
	$type = (string) $req->get_param( 'type' );
	if ( ! fx_ai_sid_ok( $sid ) || ! in_array( $type, array( 'click', 'cart' ), true ) ) {
		return new WP_REST_Response( null, 204 );
	}
	if ( ! fx_ai_hit( 'ev|' . fx_ai_ip(), 120, HOUR_IN_SECONDS ) ) {
		return new WP_REST_Response( null, 204 );
	}
	$col = 'click' === $type ? 'clicks' : 'carts';
	$wpdb->query( $wpdb->prepare( 'UPDATE ' . fx_ai_table( 'chat' ) . " SET {$col} = {$col} + 1 WHERE sid = %s", $sid ) ); // phpcs:ignore
	return new WP_REST_Response( null, 204 );
}

/* =====================================================================
 * Dymek (bez kasy, defer, tylko gdy włączony)
 * ===================================================================== */

add_action( 'wp_enqueue_scripts', function () {
	if ( ! fx_ai_enabled_for_user() || ( function_exists( 'is_checkout' ) && is_checkout() ) || is_admin() ) {
		return;
	}
	$s   = fx_ai_settings();
	$ver = defined( 'FX_VERSION' ) ? FX_VERSION : '1';
	wp_enqueue_script( 'fx-assistant', get_template_directory_uri() . '/assets/js/assistant.js', array(), $ver, array( 'in_footer' => true, 'strategy' => 'defer' ) );
	$quick = array_values( array_filter( array_map( 'trim', preg_split( '/\r?\n/', (string) $s['quick'] ) ) ) );
	wp_localize_script( 'fx-assistant', 'fxAI', array(
		'url'     => esc_url_raw( rest_url( 'fox/v1/asystent' ) ),
		'ev'      => esc_url_raw( rest_url( 'fox/v1/asystent-ev' ) ),
		'nonce'   => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
		'css'     => get_template_directory_uri() . '/assets/css/assistant.css?ver=' . rawurlencode( $ver ),
		'name'    => $s['name'],
		'hello'   => $s['greeting'],
		'quick'   => array_slice( $quick, 0, 6 ),
		'product' => function_exists( 'is_product' ) && is_product() ? (int) get_queried_object_id() : 0,
		'privacy' => $s['privacy'] ? esc_url_raw( $s['privacy'] ) : ( get_privacy_policy_url() ? esc_url_raw( get_privacy_policy_url() ) : '' ),
		'cartUrl' => function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : '',
		'addUrl'  => class_exists( 'WC_AJAX' ) ? WC_AJAX::get_endpoint( 'add_to_cart' ) : '',
		'test'    => ! empty( $s['test'] ),
	) );
} );

/* =====================================================================
 * Zamówienie po rozmowie (ciasteczko fx_ai_sid, 7 dni)
 * ===================================================================== */

function fx_ai_attach_order( $order ) {
	global $wpdb;
	$order = $order instanceof WC_Order ? $order : wc_get_order( $order );
	$sid   = isset( $_COOKIE['fx_ai_sid'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['fx_ai_sid'] ) ) : '';
	if ( ! $order || ! fx_ai_sid_ok( $sid ) || $order->get_meta( '_fx_ai_sid' ) ) {
		return;
	}
	$row = fx_ai_chat_get( $sid );
	if ( ! $row || (int) $row['order_id'] || strtotime( $row['updated'] ) < current_time( 'timestamp' ) - 7 * DAY_IN_SECONDS ) {
		return;
	}
	$shown = array_flip( array_filter( array_map( 'intval', explode( ',', (string) $row['shown'] ) ) ) );
	$rec   = 0;
	foreach ( $order->get_items() as $it ) {
		if ( isset( $shown[ (int) $it->get_product_id() ] ) || isset( $shown[ (int) $it->get_variation_id() ] ) ) {
			$rec = 1;
			break;
		}
	}
	$wpdb->update( fx_ai_table( 'chat' ), array( 'order_id' => $order->get_id(), 'order_val' => (float) $order->get_total(), 'bought_rec' => $rec ), array( 'id' => (int) $row['id'] ) );
	$order->update_meta_data( '_fx_ai_sid', $sid );
	$order->save_meta_data();
}
add_action( 'woocommerce_checkout_order_processed', function ( $order_id ) {
	fx_ai_attach_order( $order_id );
}, 20 );
add_action( 'woocommerce_store_api_checkout_order_processed', 'fx_ai_attach_order', 20 );

/* Archiwum: usuwanie starszych rozmów (gdy ustawiono okres). */
add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'fx_ai_daily' ) ) {
		wp_schedule_event( time() + 900, 'daily', 'fx_ai_daily' );
	}
} );
add_action( 'fx_ai_daily', function () {
	global $wpdb;
	$days = (int) fx_ai_settings()['keep_days'];
	if ( $days > 0 ) {
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . fx_ai_table( 'chat' ) . ' WHERE updated < %s', gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $days * DAY_IN_SECONDS ) ) ); // phpcs:ignore
	}
} );
