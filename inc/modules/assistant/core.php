<?php
/**
 * Asystent AI – ustawienia, tabele, REST, dymek, pomiar zamówień.
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

define( 'FX_AI_OPT', 'fx_ai_settings' );
define( 'FX_AI_DB', 4 );

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
		'teaser'     => 1,      // zaczepka przy dymku
		'teaser_s'   => 8,      // po ilu sekundach (albo po przewinięciu 40% strony)
		't_home'     => 'Cześć! Pomogę dobrać sprzęt FOX na moto albo rower – w Twoim rozmiarze.',
		't_product'  => 'Pytanie o {produkt}? Rozmiar, dostępność, wysyłka – odpowiem od razu.',
		't_helmet'   => 'Nie wiesz, jaki rozmiar kasku wybrać? Podaj obwód głowy – dobiorę rozmiar tego modelu.',
		't_kit'      => 'Dobrać resztę stroju w tej samej grafice? Pokażę, co pasuje i jaki jest rabat za komplet.',
		't_cat'      => 'Szukasz czegoś w kategorii {kategoria}? Pomogę wybrać – z rozmiarem dostępnym od ręki.',
		't_cart'     => 'Pytanie przed zamówieniem? Dostawa, zwroty, rozmiar – pytaj śmiało.',
		't_search'   => 'Nie znalazłeś „{fraza}”? Napisz, czego szukasz – poszukam inaczej.',
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
	// Aktualizacja instrukcji startowych – tylko tych, których nikt nie zmienił w panelu.
	$old = array(
		'Dobór kasku'     => array( 'f398d727253c3895d58c2dd29dd1a416' ),
		'Rozmiary FOX'    => array( '09186e1aac6bb1b0b5905ecb7f585824', '1a5662d9fcc9bb2556d5ddf45b09ccbc' ),
		'Badanie potrzeb' => array( 'c0a16c6e5f98ecd1eb1f84051b29d0e5' ),
	);
	if ( get_option( 'fx_ai_seeded' ) ) {
		foreach ( fx_ai_starter_kb() as $row ) {
			if ( isset( $old[ $row['title'] ] ) ) {
				$cur = $wpdb->get_row( $wpdb->prepare( "SELECT id, body FROM {$kb} WHERE type = 'ins' AND title = %s", $row['title'] ), ARRAY_A ); // phpcs:ignore
				if ( $cur && in_array( md5( $cur['body'] ), $old[ $row['title'] ], true ) ) {
					$wpdb->update( $kb, array( 'body' => $row['body'], 'keywords' => $row['keywords'] ), array( 'id' => (int) $cur['id'] ) );
				}
			}
		}
	}
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
			'body'     => "Zanim polecisz sprzęt, ustal po kolei – pytaj tylko o to, czego klient jeszcze nie powiedział, maksymalnie 2 pytania naraz:\n1. Świat i dyscyplina: Moto (MX/cross, enduro, ADV, quad) czy Rower (MTB enduro, trail, downhill, gravel/XC, rekreacja).\n2. Dla kogo: mężczyzna, kobieta, junior/dziecko (u dzieci zapytaj o wiek).\n3. Rodzaj produktu. Gdy klient chce kilka rzeczy (np. komplet + kask + rękawice + gogle) – dobieraj KAŻDY PRODUKT OSOBNO, po kolei: najpierw jeden (np. bluza i spodnie), potem kask, potem rękawice, potem gogle. Dla każdego osobne wyszukiwanie i osobny rozmiar.\n4. Wymiary do rozmiaru: dorośli – wzrost (cm) i waga (kg), przy spodniach opcjonalnie obwód pasa; dzieci – wiek, wzrost i waga; kask – obwód głowy. Rozmiar dobierz z tabeli KONKRETNEGO modelu (dopasuj_rozmiar albo szukaj_produktow z wzrost_cm/waga_kg/wiek/obwod_glowy_cm). Komplet cross (bluza + spodnie) można dobrać po wzroście i wadze.\n5. Kolor / grafika, budżet orientacyjny, ważne cechy (wentylacja, wodoodporność, ochrona).\nGdy znasz rodzaj i wymiary – pokaż 2–4 produkty z pasującym rozmiarem i przejdź do następnego produktu. Klienta, który wie, czego chce, nie przesłuchuj.\nNigdy nie pisz „nie mamy”: gdy nie ma dokładnie tego, pokaż najbliższe propozycje i powiedz, czym się różnią; gdy nie ma nic – telefon sklepu i okienko „Zostaw numer”.",
		),
		array(
			'title'    => 'Dobór kasku',
			'always'   => 0,
			'keywords' => 'kask, kaski, helmet, full face, obwód, głowy, mips, v1, v3, rampage, proframe, speedframe, dropframe, mainframe',
			'body'     => "1. Najpierw ustal świat i typ: moto (MX/enduro) albo rower (otwarty trail/enduro, full face do enduro/DH). Kask moto nie jest do roweru i odwrotnie.\n2. Zapytaj o obwód głowy w cm (centymetr nad brwiami i uszami, najszerszy obwód).\n3. Szukaj modeli: szukaj_produktow z zapytaniem (np. „kask rowerowy full face”) i obwod_glowy_cm – NIGDY nie wpisuj centymetrów w pole rozmiar. Rozmiar każdego modelu jest dobierany z jego tabeli (pole pasujacy_rozmiar). Dla jednego wybranego modelu użyj dopasuj_rozmiar.\n4. Rozmiary różnią się między modelami (np. 58 cm to w jednym modelu M, w innym L) – zawsze podawaj rozmiar dla konkretnego modelu z jego tabeli.\n5. Klient nie zna obwodu i nie może zmierzyć: powiedz, że u dorosłych najczęściej pasuje M lub L, pokaż modele i odeślij do „Tabela rozmiarów” na karcie produktu (albo przymiarka w sklepie stacjonarnym).\n6. Obwód na granicy dwóch rozmiarów – podaj obie opcje i zaproponuj telefon do sklepu. MIPS i inne systemy – tylko jeśli są w danych produktu.",
		),
		array(
			'title'    => 'Rozmiary FOX',
			'always'   => 0,
			'keywords' => 'rozmiar, rozmiary, rozmiarówka, rozmiaru, size, jaki rozmiar, uciska, mały, duży, tabela, cm, pas, wzrost, waga, junior, dziecko, dziecka, syn, córka, lat',
			'body'     => "Rozmiar dobieraj zawsze dla KONKRETNEGO modelu z jego tabeli rozmiarów – dopasuj_rozmiar z wymiarami osoby (wzrost cm, waga kg, u dzieci wiek; spodnie – opcjonalnie pas; kask – obwód głowy). Centymetry to nie nazwa rozmiaru.\nWywiad: dorośli – zapytaj o wzrost i wagę; dzieci – o wiek, wzrost i wagę. Gdy wymiary wskazują dwa rozmiary – podaj większy (dzieci rosną) i zaproponuj telefon do sklepu.\nNazwy rozmiarów FOX: dorośli – bluzy i odzież S–XXL, spodnie moto w calach w pasie (28–38); junior/dzieci – rozmiary z literą Y: YS, YM, YL, YXL (bluzy, rękawice, kaski), spodnie junior w calach (np. 22–28), najmłodsze dzieci – K/Kids. Gdy klient pisze „M” dla dziecka – dopytaj, czy chodzi o rozmiar dziecięcy YM (narzędzie traktuje M i YM u dzieci tak samo).\nKlient nie zna wymiarów: poproś o pomiar; jeśli nie może – dorośli najczęściej M lub L; dzieci – telefon do sklepu albo okienko „Zostaw numer”, pomożemy dobrać.",
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
	register_rest_route( 'fox/v1', '/asystent-cb', array(
		'methods'             => 'POST',
		'callback'            => 'fx_ai_rest_callback',
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
	$res = new WP_REST_Response( array( 'text' => $out['text'], 'cards' => $out['cards'], 'groups' => $out['groups'], 'more' => $out['more'], 'wa' => $out['wa'], 'callback' => $out['callback'] ), 200 );
	$res->header( 'Cache-Control', 'no-store' );
	return $res;
}

/** „Zostaw numer – oddzwonimy”: e-mail do obsługi z rozmową, wpis w rozmowie. */
function fx_ai_rest_callback( WP_REST_Request $req ) {
	global $wpdb;
	$s     = fx_ai_settings();
	$sid   = (string) $req->get_param( 'sid' );
	$phone = preg_replace( '/[^\d+ ]/', '', (string) $req->get_param( 'phone' ) );
	$name  = sanitize_text_field( mb_substr( (string) $req->get_param( 'name' ), 0, 60 ) );
	if ( ! fx_ai_sid_ok( $sid ) || strlen( preg_replace( '/\D/', '', $phone ) ) < 9 ) {
		return new WP_REST_Response( array( 'ok' => false, 'msg' => 'Podaj poprawny numer telefonu (9 cyfr).' ), 200 );
	}
	if ( '' !== (string) $req->get_param( 'website' ) || ! fx_ai_hit( 'cb|' . fx_ai_ip(), 3, HOUR_IN_SECONDS ) ) {
		return new WP_REST_Response( array( 'ok' => false, 'msg' => 'Zadzwoń do nas: ' . $s['phone'] ), 200 );
	}
	$row = fx_ai_chat_get( $sid );
	$txt = '';
	if ( $row ) {
		foreach ( (array) json_decode( (string) $row['log'], true ) as $m ) {
			$txt .= ( 'user' === $m['r'] ? 'Klient: ' : ( 'assistant' === $m['r'] ? 'Asystent: ' : '' ) ) . $m['t'] . "\n\n";
		}
		$log   = json_decode( (string) $row['log'], true );
		$log   = is_array( $log ) ? $log : array();
		$log[] = array( 'r' => 'system', 't' => 'Klient zostawił numer do kontaktu: ' . $phone . ( $name ? ' (' . $name . ')' : '' ), 'at' => time() );
		$wpdb->update( fx_ai_table( 'chat' ), array( 'handoff' => 1, 'log' => wp_json_encode( $log ) ), array( 'id' => (int) $row['id'] ) );
	}
	$to   = is_email( $s['email'] ) ? $s['email'] : get_option( 'admin_email' );
	$body = "Prośba o telefon z czatu na fox.sklep.pl\n\nTelefon: {$phone}\nImię: " . ( $name ? $name : '—' ) . "\n\n--- Rozmowa ---\n" . ( $txt ? $txt : '(brak)' );
	if ( $row ) {
		$body .= "\nPodgląd w panelu: " . admin_url( 'admin.php?page=fx-ai&tab=rozmowy&chat=' . (int) $row['id'] );
	}
	wp_mail( $to, 'Czat: prośba o telefon ' . $phone, $body );
	return new WP_REST_Response( array( 'ok' => true, 'msg' => 'Dziękujemy! Oddzwonimy najszybciej, jak to możliwe (w godzinach pracy sklepu).' ), 200 );
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
	$ctx = fx_ai_page_ctx();
	wp_localize_script( 'fx-assistant', 'fxAI', array(
		'url'     => esc_url_raw( rest_url( 'fox/v1/asystent' ) ),
		'ev'      => esc_url_raw( rest_url( 'fox/v1/asystent-ev' ) ),
		'cb'      => esc_url_raw( rest_url( 'fox/v1/asystent-cb' ) ),
		'phone'   => $s['phone'],
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
		'ctx'     => $ctx,
		'teaserS' => ! empty( $s['teaser'] ) ? max( 3, (int) $s['teaser_s'] ) : 0,
	) );
} );

/**
 * Kontekst strony dla dymka: typ, zaczepka, powitanie i szybkie pytania dopasowane do miejsca w sklepie.
 */
function fx_ai_page_ctx() {
	$s     = fx_ai_settings();
	$quick = array_slice( array_values( array_filter( array_map( 'trim', preg_split( '/\r?\n/', (string) $s['quick'] ) ) ) ), 0, 6 );
	$ctx   = array( 'type' => 'other', 'teaser' => '', 'chips' => $quick );
	$short = function ( $name ) {
		$name = trim( preg_replace( '/\s+/u', ' ', (string) $name ) );
		return mb_strlen( $name ) > 42 ? rtrim( mb_substr( $name, 0, 40 ) ) . '…' : $name;
	};
	if ( function_exists( 'is_product' ) && is_product() ) {
		$p = wc_get_product( get_queried_object_id() );
		if ( $p ) {
			$helmet = function_exists( 'fox_hc_v3_product_is_helmet' ) && fox_hc_v3_product_is_helmet( $p );
			$kit    = false;
			if ( ! $helmet && function_exists( 'fx_kit_resolve' ) ) {
				$k   = fx_kit_resolve( $p->get_id() );
				$kit = ! empty( $k['parts'] ) && count( $k['parts'] ) > 1;
			}
			if ( $helmet ) {
				$ctx = array( 'type' => 'helmet', 'teaser' => $s['t_helmet'], 'chips' => array( 'Jaki rozmiar tego kasku dla mnie?', 'Kiedy wyślecie?', 'Pokaż gogle do tego kasku' ) );
			} elseif ( $kit ) {
				$ctx = array( 'type' => 'kit', 'teaser' => $s['t_kit'], 'chips' => array( 'Dobierz resztę kompletu', 'Jaki rozmiar wybrać?', 'Kiedy wyślecie?' ) );
			} else {
				$ctx = array( 'type' => 'product', 'teaser' => $s['t_product'], 'chips' => array( 'Jaki rozmiar wybrać?', 'Kiedy wyślecie?', 'Pokaż podobne' ) );
			}
			$ctx['teaser'] = str_replace( '{produkt}', $short( $p->get_name() ), $ctx['teaser'] );
		}
	} elseif ( function_exists( 'fx_srch_is_results' ) && fx_srch_is_results() && ! fx_srch_current()['ids'] ) {
		$ctx = array( 'type' => 'search', 'teaser' => str_replace( '{fraza}', $short( fx_srch_current_q() ), $s['t_search'] ), 'chips' => array() );
	} elseif ( function_exists( 'is_product_category' ) && is_product_category() ) {
		$t   = get_queried_object();
		$ctx = array( 'type' => 'cat', 'teaser' => str_replace( '{kategoria}', $t ? $t->name : '', $s['t_cat'] ), 'chips' => array_merge( array( 'Pomóż mi wybrać', 'Co jest dostępne od ręki?' ), array_slice( $quick, 0, 2 ) ) );
	} elseif ( function_exists( 'is_cart' ) && is_cart() ) {
		$ctx = array( 'type' => 'cart', 'teaser' => $s['t_cart'], 'chips' => array( 'Kiedy dostanę paczkę?', 'Jak wygląda zwrot?', 'Gdzie jest moje zamówienie?' ) );
	} elseif ( is_front_page() ) {
		$ctx = array( 'type' => 'home', 'teaser' => $s['t_home'], 'chips' => $quick );
	}
	$ctx['teaser'] = trim( (string) $ctx['teaser'] );
	return $ctx;
}

/* Gdy asystent działa, zachęta WhatsApp się nie pokazuje (jedna zaczepka naraz). */
add_filter( 'fx_wa_teaser', function ( $t ) {
	return fx_ai_enabled_for_user() && ! empty( fx_ai_settings()['teaser'] ) ? '' : $t;
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
