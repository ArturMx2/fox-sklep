<?php
/**
 * Asystent AI – panel: Statystyki, Rozmowy, Trening, Statusy zamówień, Ustawienia.
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', function () {
	add_submenu_page( 'woocommerce', 'Asystent AI', 'Asystent AI', 'manage_woocommerce', 'fx-ai', 'fx_ai_admin_page' );
}, 62 );

function fx_ai_url( $args = array() ) {
	return add_query_arg( array_merge( array( 'page' => 'fx-ai' ), $args ), admin_url( 'admin.php' ) );
}

function fx_ai_cost( $in, $out ) {
	$s = fx_ai_settings();
	return ( (float) $in * (float) $s['price_in'] + (float) $out * (float) $s['price_out'] ) / 1000000;
}

/* =====================================================================
 * Zapisy (admin-post)
 * ===================================================================== */

add_action( 'admin_post_fx_ai', function () {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( 'Brak uprawnień.' );
	}
	check_admin_referer( 'fx_ai' );
	global $wpdb;
	$in   = wp_unslash( $_POST ); // phpcs:ignore
	$do   = sanitize_key( $in['do'] ?? '' );
	$tab  = 'ustawienia';
	$msg  = 'Zapisano.';
	$args = array();
	switch ( $do ) {
		case 'settings':
			$old = get_option( FX_AI_OPT, array() );
			$old = is_array( $old ) ? $old : array();
			$new = array(
				'on'        => empty( $in['on'] ) ? 0 : 1,
				'test'      => empty( $in['test'] ) ? 0 : 1,
				'key'       => '' !== trim( (string) ( $in['key'] ?? '' ) ) ? sanitize_text_field( $in['key'] ) : (string) ( $old['key'] ?? '' ),
				'model'     => sanitize_text_field( $in['model'] ?? 'gpt-4.1-mini' ),
				'name'      => sanitize_text_field( $in['name'] ?? '' ),
				'greeting'  => sanitize_textarea_field( $in['greeting'] ?? '' ),
				'quick'     => sanitize_textarea_field( $in['quick'] ?? '' ),
				'rules'     => sanitize_textarea_field( $in['rules'] ?? '' ),
				'email'     => sanitize_email( $in['email'] ?? '' ),
				'phone'     => sanitize_text_field( $in['phone'] ?? '' ),
				'privacy'   => esc_url_raw( $in['privacy'] ?? '' ),
				'lim_ip'    => absint( $in['lim_ip'] ?? 40 ),
				'lim_day'   => absint( $in['lim_day'] ?? 3000 ),
				'lim_conv'  => absint( $in['lim_conv'] ?? 40 ),
				'keep_days' => absint( $in['keep_days'] ?? 0 ),
				'price_in'  => (float) str_replace( ',', '.', (string) ( $in['price_in'] ?? 0 ) ),
				'price_out' => (float) str_replace( ',', '.', (string) ( $in['price_out'] ?? 0 ) ),
			);
			if ( ! empty( $in['key_clear'] ) ) {
				$new['key'] = '';
			}
			update_option( FX_AI_OPT, $new, false );
			$GLOBALS['fx_ai_reload'] = true;
			do_action( 'litespeed_purge_all' );
			break;
		case 'test_api':
			$r   = fx_ai_openai( array( array( 'role' => 'user', 'content' => 'Odpowiedz jednym słowem: OK' ) ), false );
			$msg = is_wp_error( $r ) ? 'Błąd połączenia: ' . $r->get_error_message() : 'Połączenie działa (model ' . esc_html( $r['model'] ?? '' ) . '): ' . wp_strip_all_tags( (string) ( $r['choices'][0]['message']['content'] ?? '' ) );
			break;
		case 'kb_save':
			$tab  = 'trening';
			$type = 'case' === ( $in['type'] ?? '' ) ? 'case' : 'ins';
			$row  = array(
				'type'       => $type,
				'title'      => sanitize_text_field( $in['title'] ?? '' ),
				'keywords'   => sanitize_textarea_field( $in['keywords'] ?? '' ),
				'body'       => sanitize_textarea_field( $in['body'] ?? '' ),
				'question'   => sanitize_textarea_field( $in['question'] ?? '' ),
				'answer_bad' => sanitize_textarea_field( $in['answer_bad'] ?? '' ),
				'always'     => 'ins' === $type && ! empty( $in['always'] ) ? 1 : 0,
				'active'     => empty( $in['active'] ) ? 0 : 1,
				'chat_id'    => absint( $in['chat_id'] ?? 0 ),
			);
			if ( '' === $row['title'] ) {
				$row['title'] = 'case' === $type ? wp_trim_words( $row['question'], 8, '…' ) : 'Instrukcja';
			}
			$id = absint( $in['id'] ?? 0 );
			if ( $id ) {
				$wpdb->update( fx_ai_table( 'kb' ), $row, array( 'id' => $id ) );
			} else {
				$wpdb->insert( fx_ai_table( 'kb' ), $row + array( 'created' => current_time( 'mysql' ) ) );
			}
			if ( $row['chat_id'] && ! empty( $in['back'] ) ) {
				$tab  = 'rozmowy';
				$args = array( 'chat' => $row['chat_id'] );
			}
			$msg = 'case' === $type ? 'Case zapisany.' : 'Instrukcja zapisana.';
			break;
		case 'kb_del':
			$tab = 'trening';
			$wpdb->delete( fx_ai_table( 'kb' ), array( 'id' => absint( $in['id'] ?? 0 ) ) );
			$msg = 'Usunięto.';
			break;
		case 'status_fetch':
			$tab = 'statusy';
			$st  = fx_ai_bl_statuses( true );
			$msg = $st ? 'Pobrano statusy z BaseLinkera: ' . count( $st ) . '.' : 'Nie udało się pobrać statusów (sprawdź token w „Termin wysyłki FOX”).';
			break;
		case 'status_save':
			$tab   = 'statusy';
			$rules = array();
			foreach ( (array) ( $in['st'] ?? array() ) as $sid => $r ) {
				$rules[ (int) $sid ] = array(
					'name'    => sanitize_text_field( $r['name'] ?? '' ),
					'text'    => sanitize_textarea_field( $r['text'] ?? '' ),
					'handoff' => empty( $r['handoff'] ) ? 0 : 1,
				);
			}
			update_option( FX_AI_STATUS_OPT, $rules, false );
			break;
	}
	wp_safe_redirect( fx_ai_url( array_merge( array( 'tab' => $tab, 'msg' => rawurlencode( $msg ) ), $args ) ) );
	exit;
} );

/* Eksport CSV rozmów. */
add_action( 'admin_post_fx_ai_csv', function () {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( 'Brak uprawnień.' );
	}
	check_admin_referer( 'fx_ai_csv' );
	list( $where, $args ) = fx_ai_chat_where( $_GET ); // phpcs:ignore
	global $wpdb;
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . fx_ai_table( 'chat' ) . " WHERE {$where} ORDER BY id DESC LIMIT %d", array_merge( $args, array( 20000 ) ) ), ARRAY_A ); // phpcs:ignore
	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=asystent-rozmowy-' . gmdate( 'Y-m-d' ) . '.csv' );
	$f = fopen( 'php://output', 'w' );
	fwrite( $f, "\xEF\xBB\xBF" );
	fputcsv( $f, array( 'id', 'start', 'strona', 'pierwsze pytanie', 'wiadomości', 'pokazane produkty', 'kliknięcia', 'do koszyka', 'zamówienie', 'wartość', 'kupił polecony', 'do obsługi', 'nie znaleziono', 'koszt $', 'test', 'rozmowa' ), ';' );
	foreach ( $rows as $r ) {
		$log = json_decode( (string) $r['log'], true );
		$txt = '';
		foreach ( (array) $log as $m ) {
			$txt .= ( 'user' === $m['r'] ? 'K: ' : 'A: ' ) . $m['t'] . ' | ';
		}
		fputcsv( $f, array( $r['id'], $r['started'], $r['page'], $r['first_q'], $r['msgs'], $r['shown'], $r['clicks'], $r['carts'], $r['order_id'] ? $r['order_id'] : '', $r['order_val'] > 0 ? str_replace( '.', ',', $r['order_val'] ) : '', $r['bought_rec'] ? 'tak' : '', $r['handoff'] ? 'tak' : '', str_replace( "\n", ' | ', (string) $r['notfound'] ), str_replace( '.', ',', round( fx_ai_cost( $r['tok_in'], $r['tok_out'] ), 4 ) ), $r['test'] ? 'tak' : '', $txt ), ';' );
	}
	fclose( $f );
	exit;
} );

/** Warunki listy rozmów z filtrów. */
function fx_ai_chat_where( $q ) {
	global $wpdb;
	$w    = array( '1=1' );
	$args = array();
	$s    = isset( $q['s'] ) ? trim( sanitize_text_field( wp_unslash( $q['s'] ) ) ) : '';
	if ( '' !== $s ) {
		$w[]    = 'log LIKE %s';
		$args[] = '%' . $wpdb->esc_like( $s ) . '%';
	}
	switch ( isset( $q['f'] ) ? sanitize_key( $q['f'] ) : '' ) {
		case 'order':
			$w[] = 'order_id > 0';
			break;
		case 'click':
			$w[] = 'clicks > 0';
			break;
		case 'handoff':
			$w[] = 'handoff = 1';
			break;
		case 'nf':
			$w[] = "notfound <> ''";
			break;
		case 'norec':
			$w[] = "(shown IS NULL OR shown = '')";
			break;
	}
	return array( implode( ' AND ', $w ), $args );
}

/* Zamówienie w panelu: link do rozmowy. */
add_action( 'woocommerce_admin_order_data_after_order_details', function ( $order ) {
	$sid = $order instanceof WC_Order ? (string) $order->get_meta( '_fx_ai_sid' ) : '';
	if ( ! $sid ) {
		return;
	}
	$row = fx_ai_chat_get( $sid );
	if ( $row ) {
		echo '<p class="form-field form-field-wide"><strong>Asystent AI:</strong> klient rozmawiał z asystentem → <a href="' . esc_url( fx_ai_url( array( 'tab' => 'rozmowy', 'chat' => (int) $row['id'] ) ) ) . '">zobacz rozmowę</a>' . ( $row['bought_rec'] ? ' (kupił polecony produkt)' : '' ) . '</p>';
	}
} );

/* =====================================================================
 * Strona
 * ===================================================================== */

function fx_ai_admin_page() {
	$tab  = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'statystyki'; // phpcs:ignore
	$msg  = isset( $_GET['msg'] ) ? sanitize_text_field( wp_unslash( $_GET['msg'] ) ) : ''; // phpcs:ignore
	$tabs = array( 'statystyki' => 'Statystyki', 'rozmowy' => 'Rozmowy', 'trening' => 'Trening', 'statusy' => 'Statusy zamówień i zwrotów', 'ustawienia' => 'Ustawienia' );
	$s    = fx_ai_settings();
	echo '<div class="wrap fx-ai-admin"><h1>Asystent AI</h1>';
	echo '<style>.fx-ai-admin .card{max-width:none}.fx-ai-kpi{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:10px;margin:14px 0}.fx-ai-kpi div{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:12px}.fx-ai-kpi b{display:block;font-size:22px;margin-top:4px}.fx-ai-kpi small{color:#646970}.fx-ai-log{max-width:900px}.fx-ai-log .m{padding:10px 12px;border-radius:8px;margin:8px 0;white-space:pre-line}.fx-ai-log .u{background:#1d2327;color:#fff;margin-left:80px}.fx-ai-log .a{background:#fff;border:1px solid #dcdcde;margin-right:80px}.fx-ai-log .a.fixed{border-color:#00a32a;box-shadow:inset 3px 0 0 #00a32a}.fx-ai-log .meta{font-size:12px;color:#646970;margin-top:6px}.fx-ai-funnel td,.fx-ai-funnel th{padding:6px 10px}.fx-ai-bar{display:inline-block;height:10px;background:#2271b1;border-radius:2px;vertical-align:middle;margin-right:6px}</style>';
	if ( ! $s['on'] ) {
		echo '<div class="notice notice-warning inline"><p>Asystent jest wyłączony – włącz go w zakładce „Ustawienia” (najpierw w trybie testowym).</p></div>';
	} elseif ( $s['test'] ) {
		echo '<div class="notice notice-info inline"><p><strong>Tryb testowy:</strong> dymek widzą tylko administratorzy.</p></div>';
	}
	if ( $msg ) {
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
	}
	echo '<nav class="nav-tab-wrapper">';
	foreach ( $tabs as $k => $l ) {
		echo '<a class="nav-tab' . ( $k === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( fx_ai_url( array( 'tab' => $k ) ) ) . '">' . esc_html( $l ) . '</a>';
	}
	echo '</nav>';
	$fn = 'fx_ai_tab_' . $tab;
	if ( function_exists( $fn ) ) {
		$fn();
	}
	echo '</div>';
}

function fx_ai_form_open( $do, $extra = '' ) {
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . $extra . '>'; // phpcs:ignore
	wp_nonce_field( 'fx_ai' );
	echo '<input type="hidden" name="action" value="fx_ai"><input type="hidden" name="do" value="' . esc_attr( $do ) . '">';
}

/* ---------- Statystyki ---------- */

function fx_ai_tab_statystyki() {
	global $wpdb;
	$days  = isset( $_GET['d'] ) ? absint( $_GET['d'] ) : 30; // phpcs:ignore
	$days  = in_array( $days, array( 7, 30, 90, 365 ), true ) ? $days : 30;
	$since = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $days * DAY_IN_SECONDS );
	$t     = fx_ai_table( 'chat' );
	$k     = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) n, SUM(shown <> '' AND shown IS NOT NULL) rec, SUM(clicks > 0) clk, SUM(carts > 0) cart, SUM(order_id > 0) ord, SUM(bought_rec) brec, SUM(order_val) val, SUM(handoff) hand, SUM(tok_in) tin, SUM(tok_out) tout, SUM(msgs) msgs, SUM(test) test FROM {$t} WHERE started >= %s", $since ), ARRAY_A ); // phpcs:ignore
	$n     = max( 0, (int) $k['n'] );
	$cost  = fx_ai_cost( $k['tin'], $k['tout'] );
	echo '<p>';
	foreach ( array( 7 => '7 dni', 30 => '30 dni', 90 => '90 dni', 365 => 'rok' ) as $d => $l ) {
		echo $d === $days ? '<strong>' . esc_html( $l ) . '</strong> · ' : '<a href="' . esc_url( fx_ai_url( array( 'tab' => 'statystyki', 'd' => $d ) ) ) . '">' . esc_html( $l ) . '</a> · ';
	}
	echo 'od ' . esc_html( wp_date( 'j.m.Y', strtotime( $since ) ) ) . ( (int) $k['test'] ? ' · w tym rozmów testowych: ' . (int) $k['test'] : '' ) . '</p>';
	$pct = function ( $a ) use ( $n ) {
		return $n ? round( 100 * (int) $a / $n, 1 ) . '%' : '–';
	};
	echo '<div class="fx-ai-kpi">';
	echo '<div><small>Rozmowy</small><b>' . (int) $n . '</b><small>wiadomości: ' . (int) $k['msgs'] . '</small></div>';
	echo '<div><small>Zamówienia (7 dni po rozmowie)</small><b>' . (int) $k['ord'] . '</b><small>konwersja ' . esc_html( $pct( $k['ord'] ) ) . '</small></div>';
	echo '<div><small>Wartość zamówień</small><b>' . wp_kses_post( wc_price( (float) $k['val'] ) ) . '</b><small>średnio ' . wp_kses_post( wc_price( $k['ord'] ? (float) $k['val'] / (int) $k['ord'] : 0 ) ) . '</small></div>';
	echo '<div><small>Koszt OpenAI</small><b>$' . esc_html( number_format( $cost, 2, ',', ' ' ) ) . '</b><small>na zamówienie: $' . esc_html( number_format( $k['ord'] ? $cost / (int) $k['ord'] : 0, 2, ',', ' ' ) ) . '</small></div>';
	echo '<div><small>Przekazane do obsługi</small><b>' . (int) $k['hand'] . '</b><small>' . esc_html( $pct( $k['hand'] ) ) . ' rozmów</small></div>';
	echo '</div>';
	// Lejek.
	$steps = array( 'Rozmowy' => $n, 'Z polecanymi produktami' => (int) $k['rec'], 'Klik w kartę produktu' => (int) $k['clk'], 'Dodanie do koszyka z czatu' => (int) $k['cart'], 'Zamówienie do 7 dni' => (int) $k['ord'], 'w tym z poleconym produktem' => (int) $k['brec'] );
	echo '<h2>Lejek</h2><table class="widefat striped fx-ai-funnel" style="max-width:700px"><tbody>';
	foreach ( $steps as $l => $v ) {
		$w = $n ? max( 1, round( 300 * $v / $n ) ) : 0;
		echo '<tr><th>' . esc_html( $l ) . '</th><td style="width:340px"><span class="fx-ai-bar" style="width:' . (int) $w . 'px"></span>' . (int) $v . '</td><td>' . esc_html( $pct( $v ) ) . '</td></tr>';
	}
	echo '</tbody></table>';
	// Dzień po dniu.
	$daily = $wpdb->get_results( $wpdb->prepare( "SELECT DATE(started) d, COUNT(*) n, SUM(shown <> '' AND shown IS NOT NULL) rec, SUM(clicks > 0) clk, SUM(order_id > 0) ord, SUM(order_val) val, SUM(handoff) hand, SUM(tok_in) tin, SUM(tok_out) tout FROM {$t} WHERE started >= %s GROUP BY DATE(started) ORDER BY d DESC", $since ), ARRAY_A ); // phpcs:ignore
	echo '<h2>Dzień po dniu</h2><table class="widefat striped" style="max-width:900px"><thead><tr><th>Dzień</th><th>Rozmowy</th><th>Z poleceniami</th><th>Kliknięcia</th><th>Zamówienia</th><th>Wartość</th><th>Do obsługi</th><th>Koszt $</th></tr></thead><tbody>';
	foreach ( (array) $daily as $r ) {
		echo '<tr><td>' . esc_html( wp_date( 'D j.m', strtotime( $r['d'] ) ) ) . '</td><td>' . (int) $r['n'] . '</td><td>' . (int) $r['rec'] . '</td><td>' . (int) $r['clk'] . '</td><td>' . (int) $r['ord'] . '</td><td>' . wp_kses_post( wc_price( (float) $r['val'] ) ) . '</td><td>' . (int) $r['hand'] . '</td><td>' . esc_html( number_format( fx_ai_cost( $r['tin'], $r['tout'] ), 3, ',', ' ' ) ) . '</td></tr>';
	}
	if ( ! $daily ) {
		echo '<tr><td colspan="8">Brak rozmów w tym okresie.</td></tr>';
	}
	echo '</tbody></table>';
	// Czego asystent nie znalazł.
	$nf = array();
	foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT notfound FROM {$t} WHERE started >= %s AND notfound <> ''", $since ) ) as $x ) { // phpcs:ignore
		foreach ( array_filter( explode( "\n", (string) $x ) ) as $q ) {
			$q        = mb_strtolower( trim( $q ), 'UTF-8' );
			$nf[ $q ] = ( $nf[ $q ] ?? 0 ) + 1;
		}
	}
	arsort( $nf );
	echo '<h2>Czego asystent nie znalazł</h2><p class="description">Sygnał o brakującym towarze albo brakującym synonimie w wyszukiwarce (WooCommerce → Wyszukiwarka).</p><table class="widefat striped" style="max-width:600px"><tbody>';
	foreach ( array_slice( $nf, 0, 50, true ) as $q => $c ) {
		echo '<tr><td>' . esc_html( $q ) . '</td><td style="width:60px">' . (int) $c . '</td></tr>';
	}
	if ( ! $nf ) {
		echo '<tr><td>Brak.</td></tr>';
	}
	echo '</tbody></table>';
	$err = get_option( 'fx_ai_errors', array() );
	if ( $err ) {
		echo '<h2>Ostatnie błędy połączenia</h2><table class="widefat striped" style="max-width:900px"><tbody>';
		foreach ( array_slice( (array) $err, 0, 10 ) as $e ) {
			echo '<tr><td style="width:140px">' . esc_html( wp_date( 'j.m H:i', (int) $e[0] ) ) . '</td><td>' . esc_html( $e[1] ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}
}

/* ---------- Rozmowy ---------- */

function fx_ai_tab_rozmowy() {
	global $wpdb;
	$t  = fx_ai_table( 'chat' );
	$id = isset( $_GET['chat'] ) ? absint( $_GET['chat'] ) : 0; // phpcs:ignore
	if ( $id ) {
		fx_ai_chat_view( $id );
		return;
	}
	list( $where, $args ) = fx_ai_chat_where( $_GET ); // phpcs:ignore
	$page  = max( 1, isset( $_GET['p'] ) ? absint( $_GET['p'] ) : 1 ); // phpcs:ignore
	$total = (int) $wpdb->get_var( $args ? $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE {$where}", $args ) : "SELECT COUNT(*) FROM {$t} WHERE {$where}" ); // phpcs:ignore
	$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, sid, started, page, first_q, msgs, shown, clicks, carts, order_id, order_val, bought_rec, handoff, notfound, tok_in, tok_out, test FROM {$t} WHERE {$where} ORDER BY id DESC LIMIT 50 OFFSET %d", array_merge( $args, array( ( $page - 1 ) * 50 ) ) ), ARRAY_A ); // phpcs:ignore
	$f     = isset( $_GET['f'] ) ? sanitize_key( $_GET['f'] ) : ''; // phpcs:ignore
	$q     = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore
	echo '<form method="get" style="margin:14px 0;display:flex;gap:8px;flex-wrap:wrap"><input type="hidden" name="page" value="fx-ai"><input type="hidden" name="tab" value="rozmowy"><input type="search" name="s" value="' . esc_attr( $q ) . '" placeholder="Szukaj w treści rozmów"><select name="f">';
	foreach ( array( '' => 'Wszystkie', 'order' => 'Z zamówieniem', 'click' => 'Z kliknięciem', 'handoff' => 'Do obsługi', 'nf' => '„Nie znaleziono”', 'norec' => 'Bez poleceń' ) as $k => $l ) {
		echo '<option value="' . esc_attr( $k ) . '"' . selected( $f, $k, false ) . '>' . esc_html( $l ) . '</option>';
	}
	echo '</select><button class="button">Filtruj</button> <a class="button" href="' . esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'fx_ai_csv', 's' => $q, 'f' => $f ), admin_url( 'admin-post.php' ) ), 'fx_ai_csv' ) ) . '">Eksport CSV</a> <span style="align-self:center">Rozmów: ' . (int) $total . '</span></form>';
	echo '<table class="widefat striped"><thead><tr><th>Start</th><th>Pierwsze pytanie</th><th>Wiad.</th><th>Produkty</th><th>Klik</th><th>Koszyk</th><th>Zamówienie</th><th>Obsługa</th><th>Koszt $</th></tr></thead><tbody>';
	foreach ( (array) $rows as $r ) {
		$shown = count( array_filter( explode( ',', (string) $r['shown'] ) ) );
		echo '<tr><td><a href="' . esc_url( fx_ai_url( array( 'tab' => 'rozmowy', 'chat' => (int) $r['id'] ) ) ) . '">' . esc_html( wp_date( 'j.m.Y H:i', strtotime( $r['started'] ) ) ) . '</a>' . ( $r['test'] ? ' <small>(test)</small>' : '' ) . '</td><td>' . esc_html( wp_trim_words( (string) $r['first_q'], 14, '…' ) ) . ( $r['notfound'] ? ' <span title="' . esc_attr( $r['notfound'] ) . '">⚠</span>' : '' ) . '</td><td>' . (int) $r['msgs'] . '</td><td>' . (int) $shown . '</td><td>' . (int) $r['clicks'] . '</td><td>' . (int) $r['carts'] . '</td><td>' . ( $r['order_id'] ? '<a href="' . esc_url( admin_url( 'post.php?post=' . (int) $r['order_id'] . '&action=edit' ) ) . '">#' . (int) $r['order_id'] . '</a> ' . wp_kses_post( wc_price( (float) $r['order_val'] ) ) . ( $r['bought_rec'] ? ' ✓' : '' ) : '' ) . '</td><td>' . ( $r['handoff'] ? 'tak' : '' ) . '</td><td>' . esc_html( number_format( fx_ai_cost( $r['tok_in'], $r['tok_out'] ), 4, ',', ' ' ) ) . '</td></tr>';
	}
	if ( ! $rows ) {
		echo '<tr><td colspan="9">Brak rozmów.</td></tr>';
	}
	echo '</tbody></table>';
	if ( $total > 50 ) {
		echo '<p>';
		for ( $i = 1; $i <= ceil( $total / 50 ) && $i <= 40; $i++ ) {
			echo $i === $page ? '<strong>' . (int) $i . '</strong> ' : '<a href="' . esc_url( fx_ai_url( array( 'tab' => 'rozmowy', 'p' => $i, 's' => $q, 'f' => $f ) ) ) . '">' . (int) $i . '</a> ';
		}
		echo '</p>';
	}
}

function fx_ai_chat_view( $id ) {
	global $wpdb;
	$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . fx_ai_table( 'chat' ) . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore
	if ( ! $r ) {
		echo '<p>Nie ma takiej rozmowy.</p>';
		return;
	}
	$kb    = array();
	$fixed = array();
	foreach ( (array) $wpdb->get_results( 'SELECT id, type, title, answer_bad, chat_id FROM ' . fx_ai_table( 'kb' ), ARRAY_A ) as $k ) { // phpcs:ignore
		$kb[ (int) $k['id'] ] = $k['title'];
		if ( (int) $k['chat_id'] === $id && $k['answer_bad'] ) {
			$fixed[ md5( trim( $k['answer_bad'] ) ) ] = (int) $k['id'];
		}
	}
	echo '<p><a href="' . esc_url( fx_ai_url( array( 'tab' => 'rozmowy' ) ) ) . '">← Wszystkie rozmowy</a></p>';
	echo '<p>Start: <strong>' . esc_html( wp_date( 'j.m.Y H:i', strtotime( $r['started'] ) ) ) . '</strong> · strona: ' . ( $r['page'] ? '<a href="' . esc_url( $r['page'] ) . '" target="_blank">' . esc_html( wp_parse_url( $r['page'], PHP_URL_PATH ) ) . '</a>' : '–' ) . ' · kliknięcia: ' . (int) $r['clicks'] . ' · do koszyka: ' . (int) $r['carts'] . ( $r['order_id'] ? ' · zamówienie <a href="' . esc_url( admin_url( 'post.php?post=' . (int) $r['order_id'] . '&action=edit' ) ) . '">#' . (int) $r['order_id'] . '</a> ' . wp_kses_post( wc_price( (float) $r['order_val'] ) ) . ( $r['bought_rec'] ? ' (kupił polecony produkt)' : '' ) : '' ) . ( $r['handoff'] ? ' · <strong>przekazano do obsługi</strong>' : '' ) . ' · koszt $' . esc_html( number_format( fx_ai_cost( $r['tok_in'], $r['tok_out'] ), 4, ',', ' ' ) ) . '</p>';
	$log  = json_decode( (string) $r['log'], true );
	$last = '';
	echo '<div class="fx-ai-log">';
	foreach ( (array) $log as $i => $m ) {
		if ( 'user' === $m['r'] ) {
			$last = $m['t'];
			echo '<div class="m u">' . esc_html( $m['t'] ) . '</div>';
			continue;
		}
		$is_fixed = isset( $fixed[ md5( trim( $m['t'] ) ) ] );
		echo '<div class="m a' . ( $is_fixed ? ' fixed' : '' ) . '">' . esc_html( $m['t'] );
		$meta = array();
		if ( ! empty( $m['tools'] ) ) {
			$meta[] = 'narzędzia: ' . implode( ', ', $m['tools'] );
		}
		if ( ! empty( $m['cards'] ) ) {
			$meta[] = 'pokazane: ' . implode( ', ', array_map( function ( $pid ) {
				return '<a href="' . esc_url( get_permalink( $pid ) ) . '" target="_blank">' . esc_html( get_the_title( $pid ) ) . '</a>';
			}, $m['cards'] ) );
		}
		if ( ! empty( $m['kb'] ) ) {
			$meta[] = 'instrukcje: ' . esc_html( implode( ', ', array_filter( array_map( function ( $k ) use ( $kb ) {
				return $kb[ (int) $k ] ?? '';
			}, $m['kb'] ) ) ) );
		}
		$fix    = $is_fixed
			? '<span style="color:#00a32a">✓ poprawiona (case #' . (int) $fixed[ md5( trim( $m['t'] ) ) ] . ')</span>'
			: '<a href="' . esc_url( fx_ai_url( array( 'tab' => 'trening', 'case_chat' => $id, 'case_i' => $i ) ) ) . '">Popraw tę odpowiedź (zrób case)</a>';
		$meta[] = $fix;
		echo '<div class="meta">' . wp_kses_post( implode( ' · ', $meta ) ) . '</div></div>';
	}
	echo '</div>';
}

/* ---------- Trening ---------- */

function fx_ai_tab_trening() {
	global $wpdb;
	$t    = fx_ai_table( 'kb' );
	$edit = array( 'id' => 0, 'type' => 'ins', 'title' => '', 'keywords' => '', 'body' => '', 'question' => '', 'answer_bad' => '', 'always' => 0, 'active' => 1, 'chat_id' => 0 );
	$back = 0;
	if ( isset( $_GET['edit'] ) ) { // phpcs:ignore
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", absint( $_GET['edit'] ) ), ARRAY_A ); // phpcs:ignore
		if ( $row ) {
			$edit = $row;
		}
	} elseif ( isset( $_GET['new'] ) ) { // phpcs:ignore
		$edit['type'] = 'case' === $_GET['new'] ? 'case' : 'ins'; // phpcs:ignore
	} elseif ( isset( $_GET['case_chat'] ) ) { // phpcs:ignore
		$chat = $wpdb->get_row( $wpdb->prepare( 'SELECT id, log FROM ' . fx_ai_table( 'chat' ) . ' WHERE id = %d', absint( $_GET['case_chat'] ) ), ARRAY_A ); // phpcs:ignore
		$i    = absint( $_GET['case_i'] ?? 0 ); // phpcs:ignore
		$log  = $chat ? json_decode( (string) $chat['log'], true ) : array();
		if ( isset( $log[ $i ] ) ) {
			$q = '';
			for ( $j = $i - 1; $j >= 0; $j-- ) {
				if ( 'user' === $log[ $j ]['r'] ) {
					$q = $log[ $j ]['t'];
					break;
				}
			}
			$edit = array_merge( $edit, array( 'type' => 'case', 'question' => $q, 'answer_bad' => $log[ $i ]['t'], 'chat_id' => (int) $chat['id'], 'title' => wp_trim_words( $q, 8, '…' ) ) );
			$back = 1;
		}
	}
	$show_form = $edit['id'] || isset( $_GET['new'] ) || isset( $_GET['case_chat'] ); // phpcs:ignore
	if ( $show_form ) {
		$case = 'case' === $edit['type'];
		echo '<h2>' . ( $edit['id'] ? 'Edycja' : 'Nowy' ) . ' – ' . ( $case ? 'case z rozmowy' : 'instrukcja do tematu' ) . '</h2>';
		fx_ai_form_open( 'kb_save' );
		echo '<input type="hidden" name="id" value="' . (int) $edit['id'] . '"><input type="hidden" name="type" value="' . esc_attr( $edit['type'] ) . '"><input type="hidden" name="chat_id" value="' . (int) $edit['chat_id'] . '"><input type="hidden" name="back" value="' . (int) $back . '">';
		echo '<table class="form-table">';
		echo '<tr><th>Tytuł / temat</th><td><input class="large-text" name="title" value="' . esc_attr( $edit['title'] ) . '"></td></tr>';
		if ( $case ) {
			echo '<tr><th>Pytanie klienta</th><td><textarea class="large-text" rows="3" name="question">' . esc_textarea( $edit['question'] ) . '</textarea></td></tr>';
			echo '<tr><th>Odpowiedź asystenta (zła)</th><td><textarea class="large-text" rows="4" name="answer_bad" readonly style="background:#f6f7f7">' . esc_textarea( $edit['answer_bad'] ) . '</textarea></td></tr>';
			echo '<tr><th>Jak powinien odpowiedzieć</th><td><textarea class="large-text" rows="5" name="body" required>' . esc_textarea( $edit['body'] ) . '</textarea><p class="description">Pisz tak, jak odpowiedziałby dobry sprzedawca. Konkretnych cen i stanów nie wpisuj – asystent bierze je z narzędzi.</p></td></tr>';
		} else {
			echo '<tr><th>Treść instrukcji</th><td><textarea class="large-text" rows="10" name="body" required>' . esc_textarea( $edit['body'] ) . '</textarea><p class="description">Jak dla nowego sprzedawcy: o co pytać, co polecać, czego unikać.</p></td></tr>';
			echo '<tr><th>Zawsze</th><td><label><input type="checkbox" name="always" value="1"' . checked( $edit['always'], 1, false ) . '> dołączaj w każdej rozmowie</label></td></tr>';
		}
		echo '<tr><th>Słowa-klucze</th><td><textarea class="large-text" rows="2" name="keywords">' . esc_textarea( $edit['keywords'] ) . '</textarea><p class="description">Po przecinku – po nich asystent dobiera ' . ( $case ? 'ten case' : 'tę instrukcję' ) . ' do rozmowy (liczą się też słowa z tytułu' . ( $case ? ' i pytania' : '' ) . ').</p></td></tr>';
		echo '<tr><th>Aktywne</th><td><label><input type="checkbox" name="active" value="1"' . checked( $edit['active'], 1, false ) . '> tak</label></td></tr>';
		echo '</table>';
		submit_button( 'Zapisz' );
		echo '</form>';
		echo '<p><a href="' . esc_url( fx_ai_url( array( 'tab' => 'trening' ) ) ) . '">← Wróć do listy</a></p>';
		return;
	}
	echo '<p style="margin-top:14px"><a class="button button-primary" href="' . esc_url( fx_ai_url( array( 'tab' => 'trening', 'new' => 'ins' ) ) ) . '">Dodaj instrukcję</a> <a class="button" href="' . esc_url( fx_ai_url( array( 'tab' => 'trening', 'new' => 'case' ) ) ) . '">Dodaj case</a></p>';
	$rows = $wpdb->get_results( "SELECT * FROM {$t} ORDER BY type DESC, always DESC, used DESC, id", ARRAY_A ); // phpcs:ignore
	echo '<table class="widefat striped"><thead><tr><th>Rodzaj</th><th>Tytuł</th><th>Słowa-klucze</th><th>Zawsze</th><th>Aktywne</th><th>Użyto</th><th></th></tr></thead><tbody>';
	foreach ( (array) $rows as $r ) {
		echo '<tr><td>' . ( 'case' === $r['type'] ? 'Case' : 'Instrukcja' ) . '</td><td><a href="' . esc_url( fx_ai_url( array( 'tab' => 'trening', 'edit' => (int) $r['id'] ) ) ) . '"><strong>' . esc_html( $r['title'] ) . '</strong></a>' . ( $r['chat_id'] ? ' <a href="' . esc_url( fx_ai_url( array( 'tab' => 'rozmowy', 'chat' => (int) $r['chat_id'] ) ) ) . '">(rozmowa)</a>' : '' ) . '</td><td>' . esc_html( wp_trim_words( (string) $r['keywords'], 10, '…' ) ) . '</td><td>' . ( $r['always'] ? 'tak' : '' ) . '</td><td>' . ( $r['active'] ? 'tak' : 'nie' ) . '</td><td>' . (int) $r['used'] . '</td><td>';
		fx_ai_form_open( 'kb_del', ' style="display:inline" onsubmit="return confirm(\'Usunąć?\')"' );
		echo '<input type="hidden" name="id" value="' . (int) $r['id'] . '"><button class="button-link-delete" style="background:none;border:0;cursor:pointer">Usuń</button></form></td></tr>';
	}
	echo '</tbody></table>';

	// Tester.
	$msg  = isset( $_POST['fx_ai_test_msg'] ) && check_admin_referer( 'fx_ai_tester' ) ? sanitize_textarea_field( wp_unslash( $_POST['fx_ai_test_msg'] ) ) : ''; // phpcs:ignore
	$real = ! empty( $_POST['fx_ai_test_real'] ); // phpcs:ignore
	echo '<h2 style="margin-top:30px">Tester</h2><p class="description">Wpisz wiadomość jak klient – zobaczysz, które instrukcje i case’y dostanie asystent. Opcjonalnie prawdziwa odpowiedź (nie trafia do statystyk; zgłoszenia do obsługi nie są wysyłane).</p>';
	echo '<form method="post">';
	wp_nonce_field( 'fx_ai_tester' );
	echo '<textarea name="fx_ai_test_msg" class="large-text" rows="3">' . esc_textarea( $msg ) . '</textarea><p><label><input type="checkbox" name="fx_ai_test_real" value="1"' . checked( $real, true, false ) . '> pokaż też prawdziwą odpowiedź (zapytanie do OpenAI)</label></p>';
	submit_button( 'Sprawdź', 'secondary', 'submit', false );
	echo '</form>';
	if ( '' !== $msg ) {
		$msgs = array( array( 'role' => 'user', 'content' => $msg ) );
		$kb   = fx_ai_pick_kb( $msgs );
		echo '<div class="card"><h3>Dobrane</h3><ul>';
		foreach ( $kb['ins'] as $r ) {
			echo '<li>Instrukcja: <strong>' . esc_html( $r['title'] ) . '</strong> (' . ( $r['always'] ? 'zawsze' : 'punkty ' . (int) $r['score'] ) . ')</li>';
		}
		foreach ( $kb['cases'] as $r ) {
			echo '<li>Case: <strong>' . esc_html( $r['title'] ) . '</strong> (punkty ' . (int) $r['score'] . ')</li>';
		}
		if ( ! $kb['ins'] && ! $kb['cases'] ) {
			echo '<li>Nic – asystent działa na samych zasadach podstawowych.</li>';
		}
		echo '</ul><p class="description">Instrukcja systemowa: ' . (int) mb_strlen( fx_ai_system( array( 'product' => 0 ), $kb ) ) . ' znaków.</p>';
		if ( $real ) {
			if ( '' === fx_ai_key() ) {
				echo '<p><strong>Brak klucza OpenAI.</strong></p>';
			} else {
				$out = fx_ai_answer( $msgs, array( 'sid' => '', 'product' => 0, 'page' => '' ), true );
				echo '<h3>Odpowiedź</h3><div style="white-space:pre-line;background:#fff;border:1px solid #dcdcde;padding:10px;border-radius:6px">' . esc_html( $out['text'] ) . '</div>';
				echo '<p class="description">Narzędzia: ' . esc_html( implode( ', ', $out['tools'] ) ? implode( ', ', $out['tools'] ) : '–' ) . ' · produkty: ' . esc_html( implode( ', ', wp_list_pluck( $out['cards'], 'name' ) ) ? implode( ', ', wp_list_pluck( $out['cards'], 'name' ) ) : '–' ) . ' · koszt $' . esc_html( number_format( fx_ai_cost( $out['tok_in'], $out['tok_out'] ), 4, ',', ' ' ) ) . '</p>';
			}
		}
		echo '</div>';
	}
}

/* ---------- Statusy zamówień ---------- */

function fx_ai_tab_statusy() {
	$st    = fx_ai_bl_statuses();
	$rules = get_option( FX_AI_STATUS_OPT, array() );
	$rules = is_array( $rules ) ? $rules : array();
	echo '<p style="margin-top:14px">Przy każdym statusie z BaseLinkera wpisz, co asystent ma powiedzieć klientowi. Asystent odpowiada zgodnie z wytyczną i nie obiecuje niczego spoza niej. Status sprawdzany jest tylko po numerze zamówienia i telefonie.</p>';
	fx_ai_form_open( 'status_fetch', ' style="display:inline"' );
	submit_button( 'Pobierz statusy z BaseLinkera', 'secondary', 'submit', false );
	echo '</form> <button type="button" class="button" id="fx-ai-hints">Wstaw podpowiedzi do pustych pól</button>';
	if ( ! $st ) {
		echo '<p><em>Brak statusów – kliknij „Pobierz statusy z BaseLinkera” (token z „Termin wysyłki FOX”).</em></p>';
		return;
	}
	fx_ai_form_open( 'status_save' );
	echo '<table class="widefat striped" style="margin-top:12px"><thead><tr><th style="width:220px">Status w BaseLinkerze</th><th>Co asystent ma powiedzieć klientowi</th><th style="width:150px">Zaproponuj kontakt z obsługą</th></tr></thead><tbody>';
	foreach ( $st as $id => $s ) {
		$r    = $rules[ $id ] ?? array();
		$hint = fx_ai_status_hint( $s['name'] . ' ' . $s['customer'] );
		echo '<tr><td><strong>' . esc_html( $s['name'] ) . '</strong>' . ( $s['customer'] ? '<br><small>dla klienta: ' . esc_html( $s['customer'] ) . '</small>' : '' ) . '<input type="hidden" name="st[' . (int) $id . '][name]" value="' . esc_attr( $s['name'] ) . '"></td>';
		echo '<td><textarea class="large-text fx-ai-st" rows="2" name="st[' . (int) $id . '][text]" data-hint="' . esc_attr( $hint[0] ) . '" data-hand="' . (int) $hint[1] . '">' . esc_textarea( $r['text'] ?? '' ) . '</textarea></td>';
		echo '<td><label><input type="checkbox" name="st[' . (int) $id . '][handoff]" value="1"' . checked( ! empty( $r['handoff'] ), true, false ) . '> tak</label></td></tr>';
	}
	echo '</tbody></table>';
	submit_button( 'Zapisz wytyczne' );
	echo '</form>';
	echo "<script>document.getElementById('fx-ai-hints').addEventListener('click',function(){document.querySelectorAll('.fx-ai-st').forEach(function(t){if(!t.value.trim()&&t.dataset.hint){t.value=t.dataset.hint;if(t.dataset.hand==='1'){var c=t.closest('tr').querySelector('input[type=checkbox]');if(c){c.checked=true;}}}});});</script>";
}

/* ---------- Ustawienia ---------- */

function fx_ai_tab_ustawienia() {
	$s   = fx_ai_settings();
	$def = defined( 'FOX_AI_KEY' ) && FOX_AI_KEY;
	fx_ai_form_open( 'settings' );
	echo '<table class="form-table">';
	echo '<tr><th>Włączony</th><td><label><input type="checkbox" name="on" value="1"' . checked( $s['on'], 1, false ) . '> dymek czatu na stronach sklepu (poza kasą)</label><br><label><input type="checkbox" name="test" value="1"' . checked( $s['test'], 1, false ) . '> tryb testowy – dymek widzą tylko administratorzy</label></td></tr>';
	echo '<tr><th>Klucz OpenAI</th><td>' . ( $def ? '<p>Klucz ustawiony w wp-config.php (FOX_AI_KEY).</p>' : '<input type="password" class="regular-text" name="key" value="" autocomplete="off" placeholder="' . ( $s['key'] ? '•••••• (zapisany – wpisz nowy, by zmienić)' : 'sk-…' ) . '">' . ( $s['key'] ? ' <label><input type="checkbox" name="key_clear" value="1"> usuń</label>' : '' ) . '<p class="description">Osobny projekt w OpenAI z miesięcznym budżetem (na start 20–30 $, alert przy 80%). Klucz nie trafia do przeglądarki.</p>' ) . '</td></tr>';
	echo '<tr><th>Model</th><td><input class="regular-text" name="model" value="' . esc_attr( $s['model'] ) . '"><p class="description">Domyślnie gpt-4.1-mini.</p></td></tr>';
	echo '<tr><th>Nazwa</th><td><input class="regular-text" name="name" value="' . esc_attr( $s['name'] ) . '"></td></tr>';
	echo '<tr><th>Powitanie</th><td><textarea class="large-text" rows="2" name="greeting">' . esc_textarea( $s['greeting'] ) . '</textarea></td></tr>';
	echo '<tr><th>Szybkie pytania</th><td><textarea class="large-text" rows="4" name="quick">' . esc_textarea( $s['quick'] ) . '</textarea><p class="description">Jedno w linii (maks. 6).</p></td></tr>';
	echo '<tr><th>Zasady sklepu</th><td><textarea class="large-text" rows="12" name="rules" placeholder="Dostawa: …&#10;Zwroty: 14 dni …&#10;Płatności: …&#10;Sklep stacjonarny: Mokronos Dolny, godziny …">' . esc_textarea( $s['rules'] ) . '</textarea><p class="description">Jedyne źródło odpowiedzi o dostawie, zwrotach, płatnościach i sklepie stacjonarnym. Czego tu nie ma – asystent nie wymyśli.</p></td></tr>';
	echo '<tr><th>E-mail obsługi</th><td><input type="email" class="regular-text" name="email" value="' . esc_attr( $s['email'] ) . '" placeholder="' . esc_attr( get_option( 'admin_email' ) ) . '"><p class="description">Tu przychodzą sprawy przekazane przez asystenta (z całą rozmową, odpowiedź trafia do klienta – Reply-To).</p></td></tr>';
	echo '<tr><th>Telefon sklepu</th><td><input class="regular-text" name="phone" value="' . esc_attr( $s['phone'] ) . '"></td></tr>';
	echo '<tr><th>Polityka prywatności</th><td><input class="large-text" name="privacy" value="' . esc_attr( $s['privacy'] ) . '" placeholder="' . esc_attr( get_privacy_policy_url() ) . '"><p class="description">Link w dymku. W polityce dopisz czat AI (OpenAI) i okres przechowywania rozmów.</p></td></tr>';
	echo '<tr><th>Limity</th><td><input type="number" name="lim_ip" value="' . (int) $s['lim_ip'] . '" style="width:80px"> wiadomości na 15 min z jednego IP · <input type="number" name="lim_day" value="' . (int) $s['lim_day'] . '" style="width:90px"> dziennie w sklepie · <input type="number" name="lim_conv" value="' . (int) $s['lim_conv'] . '" style="width:70px"> w jednej rozmowie<p class="description">Status zamówienia: 6 prób na godzinę z IP; przekazanie do obsługi: 3 na godzinę.</p></td></tr>';
	echo '<tr><th>Archiwum</th><td>usuwaj rozmowy starsze niż <input type="number" name="keep_days" value="' . (int) $s['keep_days'] . '" style="width:80px"> dni (0 = bez usuwania)</td></tr>';
	echo '<tr><th>Cena modelu</th><td>$ <input name="price_in" value="' . esc_attr( $s['price_in'] ) . '" style="width:70px"> za 1 mln tokenów wejścia · $ <input name="price_out" value="' . esc_attr( $s['price_out'] ) . '" style="width:70px"> wyjścia <span class="description">(do szacowania kosztów)</span></td></tr>';
	echo '</table>';
	submit_button( 'Zapisz' );
	echo '</form>';
	fx_ai_form_open( 'test_api' );
	submit_button( 'Testuj połączenie z OpenAI', 'secondary', 'submit', false );
	echo '</form>';
}
