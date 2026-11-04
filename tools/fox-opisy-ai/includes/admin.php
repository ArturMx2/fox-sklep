<?php
/**
 * Panel: WooCommerce → Opisy AI (stan, uruchamianie, historia z przywracaniem, do sprawdzenia, ustawienia)
 * oraz ramka „Opis AI” w edycji produktu.
 *
 * @package fox-opisy-ai
 */

defined( 'ABSPATH' ) || exit;

function fxd_cap() {
	return current_user_can( 'manage_woocommerce' );
}

/* Stary generator v18 (fragment kodu) ma klucz API w kodzie i nadpisuje opisy bez źródeł – ostrzeżenie. */
add_action( 'admin_notices', function () {
	if ( fxd_cap() && ( function_exists( 'fox_render_premium_ui' ) || defined( 'FOX_SERPER_KEY' ) ) ) {
		echo '<div class="notice notice-error"><p><strong>Stary „Generator FOX v18” jest nadal włączony</strong> (Fragmenty kodu). Ma klucze API wpisane w kodzie i pisze opisy bez źródeł. Wyłącz go – jego pracę przejmuje wtyczka „FOX – Opisy produktów AI”.</p></div>';
	}
} );

add_action( 'admin_menu', function () {
	add_submenu_page( 'woocommerce', 'Opisy produktów AI', 'Opisy AI', 'manage_woocommerce', 'fox-opisy-ai', 'fxd_page' );
}, 70 );

/* ---------- Zapis ustawień ---------- */
add_action( 'admin_post_fxd_save', function () {
	if ( ! fxd_cap() ) {
		wp_die( 'Brak uprawnień' );
	}
	check_admin_referer( 'fxd_save' );
	$in  = (array) wp_unslash( $_POST['fxd'] ?? array() ); // phpcs:ignore
	$old = fxd_settings();
	$out = array();
	foreach ( array( 'api_key', 'serper_key' ) as $k ) { // puste = bez zmian, „usun” = skasuj
		$v         = trim( sanitize_text_field( $in[ $k ] ?? '' ) );
		$out[ $k ] = 'usun' === $v ? '' : ( '' === $v ? $old[ $k ] : $v );
	}
	foreach ( array( 'model_write', 'model_facts' ) as $k ) {
		$out[ $k ] = preg_match( '/^[a-z0-9.\-]+$/i', $in[ $k ] ?? '' ) ? $in[ $k ] : $old[ $k ];
	}
	foreach ( array( 'content', 'excerpt', 'meta' ) as $k ) {
		$out[ $k ] = in_array( $in[ $k ] ?? '', array( 'weak', 'all', 'off' ), true ) ? $in[ $k ] : 'weak';
	}
	$out['scope']      = 'all' === ( $in['scope'] ?? '' ) ? 'all' : 'instock';
	$out['auto']       = empty( $in['auto'] ) ? 0 : 1;
	$out['vision']     = empty( $in['vision'] ) ? 0 : 1;
	$out['weak_chars'] = max( 100, min( 5000, (int) ( $in['weak_chars'] ?? 500 ) ) );
	$out['brand']      = sanitize_text_field( $in['brand'] ?? 'FOX' );
	foreach ( array( 'domains', 'tech', 'style' ) as $k ) {
		$out[ $k ] = sanitize_textarea_field( $in[ $k ] ?? '' );
	}
	update_option( 'fxd_settings', $out, false );
	// Zmiana zasad = ponowne sprawdzenie wszystkich produktów (napisane teksty są rozpoznawane i nie są pisane drugi raz).
	global $wpdb;
	$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_fxd_v'" ); // phpcs:ignore
	wp_safe_redirect( admin_url( 'admin.php?page=fox-opisy-ai&tab=ustawienia&zapisano=1' ) );
	exit;
} );

/* ---------- AJAX: partia, jeden produkt, przywrócenie ---------- */
add_action( 'wp_ajax_fxd_batch', function () {
	check_ajax_referer( 'fxd' );
	if ( ! fxd_cap() ) {
		wp_send_json_error( 'Brak uprawnień', 403 );
	}
	if ( get_transient( 'fxd_lock' ) ) {
		wp_send_json_success( array( 'log' => array( 'Automat w tle właśnie pracuje – czekam…' ), 'wait' => 10, 'counts' => fxd_counts() ) );
	}
	set_transient( 'fxd_lock', 1, 3 * MINUTE_IN_SECONDS );
	list( $log, $wait, $stop ) = fxd_run( 40 );
	delete_transient( 'fxd_lock' );
	wp_send_json_success( array( 'log' => $log, 'wait' => $wait, 'stop' => $stop, 'counts' => fxd_counts() ) );
} );

add_action( 'wp_ajax_fxd_one', function () {
	check_ajax_referer( 'fxd' );
	$id = absint( $_POST['id'] ?? 0 ); // phpcs:ignore
	if ( ! fxd_cap() || ! $id ) {
		wp_send_json_error( 'Brak uprawnień', 403 );
	}
	@set_time_limit( 300 ); // phpcs:ignore
	$r = fxd_process( $id, true );
	fxd_log( strtoupper( $r['status'] ) . '  #' . $id . ' (ręcznie) ' . $r['msg'] );
	wp_send_json_success( $r );
} );

add_action( 'wp_ajax_fxd_restore', function () {
	check_ajax_referer( 'fxd' );
	$id = absint( $_POST['id'] ?? 0 ); // phpcs:ignore
	if ( ! fxd_cap() || ! $id ) {
		wp_send_json_error( 'Brak uprawnień', 403 );
	}
	wp_send_json_success( array( 'ok' => fxd_restore( $id ) ) );
} );

/* ---------- Ramka w edycji produktu ---------- */
add_action( 'add_meta_boxes', function () {
	add_meta_box( 'fxd-box', 'Opis AI', 'fxd_box', 'product', 'side', 'default' );
} );

function fxd_flag_label( $f ) {
	$l = array( 'ok' => 'napisany ze źródeł', 'nosrc' => 'mało źródeł – krótka wersja', 'check' => 'do sprawdzenia', 'err' => 'błąd', 'restored' => 'przywrócony ręcznie' );
	return $l[ $f ] ?? $f;
}

function fxd_box( $post ) {
	$i = (array) get_post_meta( $post->ID, '_fxd_info', true );
	echo '<p>Stan: <strong>' . esc_html( $i ? fxd_flag_label( $i['flag'] ?? '' ) : 'jeszcze nie sprawdzany' ) . '</strong>' . ( ! empty( $i['at'] ) ? ' <small>(' . esc_html( wp_date( 'd.m.Y H:i', $i['at'] ) ) . ')</small>' : '' ) . '</p>';
	if ( ! empty( $i['sources'] ) ) {
		echo '<p><small>Źródła:</small></p><ul style="margin:0 0 8px 16px;list-style:disc">';
		foreach ( $i['sources'] as $s ) {
			echo '<li><small>' . ( $s['url'] ? '<a href="' . esc_url( $s['url'] ) . '" target="_blank" rel="noopener">' . esc_html( $s['label'] ) . '</a>' : esc_html( $s['label'] ) ) . '</small></li>';
		}
		echo '</ul>';
	}
	if ( ! empty( $i['notes'] ) ) {
		echo '<p><small style="color:#b32d2e">' . esc_html( implode( ' · ', $i['notes'] ) ) . '</small></p>';
	}
	$facts = get_option( 'fxd_facts_' . ( $i['group'] ?? '' ) );
	if ( is_array( $facts ) && $facts['facts'] ) {
		echo '<details><summary><small>Fakty (' . count( $facts['facts'] ) . ')</small></summary><ul style="margin:6px 0 0 16px;list-style:disc">';
		foreach ( $facts['facts'] as $f ) {
			echo '<li><small>' . esc_html( $f['f'] ) . ' <em style="color:#646970">[' . esc_html( implode( ',', $f['src'] ) . ' · ' . $f['c'] ) . ']</em></small></li>';
		}
		echo '</ul></details>';
	}
	$has_prev = (bool) array_filter( (array) get_post_meta( $post->ID, '_fxd_prev', true ) );
	echo '<p style="margin-top:10px"><button type="button" class="button button-primary" data-fxd-one="' . (int) $post->ID . '">Napisz opisy teraz</button> ';
	if ( $has_prev ) {
		echo '<button type="button" class="button" data-fxd-restore="' . (int) $post->ID . '">Przywróć poprzednie</button>';
	}
	echo '</p><p data-fxd-msg><small>Zajmuje 20–60 s. Strona odświeży się po zapisie.</small></p>';
	fxd_js();
}

function fxd_js() {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;
	?>
	<script>
	(function(){var nonce=<?php echo wp_json_encode( wp_create_nonce( 'fxd' ) ); ?>;
	function post(d){var f=new FormData();f.append('_ajax_nonce',nonce);Object.keys(d).forEach(function(k){f.append(k,d[k])});return fetch(ajaxurl,{method:'POST',body:f,credentials:'same-origin'}).then(function(r){return r.json()})}
	document.addEventListener('click',function(e){var b=e.target.closest('[data-fxd-one],[data-fxd-restore]');if(!b)return;e.preventDefault();
		var one=b.hasAttribute('data-fxd-one'),id=b.getAttribute(one?'data-fxd-one':'data-fxd-restore'),msg=b.closest('td,.inside')&&b.closest('td,.inside').querySelector('[data-fxd-msg]');
		if(!one&&!confirm('Przywrócić poprzednie opisy tego produktu?'))return;
		b.disabled=true;if(msg)msg.textContent=one?'Zbieram źródła i piszę…':'Przywracam…';
		post({action:one?'fxd_one':'fxd_restore',id:id}).then(function(r){if(msg)msg.textContent=r&&r.success?(one?r.data.msg:'Przywrócono.'):'Błąd.';if(r&&r.success&&!document.body.classList.contains('woocommerce_page_fox-opisy-ai'))setTimeout(function(){location.reload()},1200);b.disabled=false})
		.catch(function(){if(msg)msg.textContent='Błąd połączenia.';b.disabled=false})});
	})();
	</script>
	<?php
}

/* ---------- Strona panelu ---------- */
function fxd_page() {
	if ( ! fxd_cap() ) {
		return;
	}
	$tab  = sanitize_key( $_GET['tab'] ?? 'panel' ); // phpcs:ignore
	$base = admin_url( 'admin.php?page=fox-opisy-ai' );
	$tabs = array( 'panel' => 'Panel', 'historia' => 'Historia zmian', 'sprawdz' => 'Do sprawdzenia', 'ustawienia' => 'Ustawienia' );
	echo '<div class="wrap fxd"><h1>Opisy produktów AI</h1><nav class="nav-tab-wrapper">';
	foreach ( $tabs as $k => $l ) {
		echo '<a class="nav-tab' . ( $k === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( add_query_arg( 'tab', $k, $base ) ) . '">' . esc_html( $l ) . '</a>';
	}
	echo '</nav>';
	if ( ! fxd_key() ) {
		echo '<div class="notice notice-warning"><p>Brak klucza OpenAI – wpisz go w Ustawieniach (albo w wp-config.php: <code>define( \'FOX_OPISY_AI_KEY\', \'sk-…\' );</code>).</p></div>';
	}
	if ( ! fxd_serper_key() ) {
		echo '<div class="notice notice-info"><p>Bez klucza Serper opisy powstają tylko z danych BaseLinkera i sklepu (bez foxracing.com i innych stron). Klucz: serper.dev → Ustawienia tutaj.</p></div>';
	}
	if ( 'ustawienia' === $tab ) {
		fxd_settings_page();
	} elseif ( 'historia' === $tab ) {
		fxd_history_page( false );
	} elseif ( 'sprawdz' === $tab ) {
		fxd_history_page( true );
	} else {
		fxd_panel_page();
	}
	echo '</div><style>.fxd-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin:16px 0}.fxd-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px 14px}.fxd-card b{display:block;font-size:24px;margin:4px 0}.fxd-card small{color:#50575e}#fxd-log{background:#111;color:#ddd;padding:12px;height:420px;overflow:auto;font:12px/1.55 monospace;border-left:4px solid #f26522;margin-top:10px}#fxd-log .e{color:#ff7070}#fxd-log .o{color:#7dffa6}#fxd-log .w{color:#ffd166}.fxd textarea.code{font-size:12px}</style>';
	fxd_js();
}

function fxd_panel_page() {
	$c   = fxd_counts();
	$f   = $c['flags'];
	$tok = (int) get_option( 'fxd_tokens', 0 );
	?>
	<div class="fxd-cards">
		<div class="fxd-card"><small>Produkty w zakresie</small><b><?php echo esc_html( number_format_i18n( $c['all'] ) ); ?></b><small><?php echo 'instock' === fxd_opt( 'scope' ) ? 'dostępne' : 'wszystkie opublikowane'; ?></small></div>
		<div class="fxd-card"><small>Czeka na sprawdzenie</small><b data-fxd-left><?php echo esc_html( number_format_i18n( $c['left'] ) ); ?></b><small>automat: <?php echo fxd_opt( 'auto' ) ? 'włączony (co 5 min)' : 'wyłączony'; ?></small></div>
		<div class="fxd-card"><small>Napisane ze źródeł</small><b><?php echo esc_html( number_format_i18n( $f['ok'] ?? 0 ) ); ?></b><small>mało źródeł: <?php echo (int) ( $f['nosrc'] ?? 0 ); ?></small></div>
		<div class="fxd-card"><small>Do sprawdzenia / błędy</small><b><?php echo (int) ( $f['check'] ?? 0 ) + (int) ( $f['err'] ?? 0 ); ?></b><small><a href="<?php echo esc_url( admin_url( 'admin.php?page=fox-opisy-ai&tab=sprawdz' ) ); ?>">lista</a></small></div>
		<div class="fxd-card"><small>Po starym generatorze v18</small><b><?php echo esc_html( number_format_i18n( $c['v18'] ) ); ?></b><small>poprawiane w pierwszej kolejności</small></div>
		<div class="fxd-card"><small>Zużyte tokeny OpenAI</small><b><?php echo esc_html( number_format_i18n( $tok ) ); ?></b><small>od instalacji</small></div>
	</div>
	<p>Automat sam przechodzi przez produkty w tle. Przycisk poniżej przyspiesza pracę, dopóki strona jest otwarta.</p>
	<p><button class="button button-primary" data-fxd-run>Przetwarzaj teraz</button> <button class="button" data-fxd-stop>Stop</button> <span data-fxd-status></span></p>
	<div id="fxd-log"><?php foreach ( array_reverse( array_slice( (array) get_option( 'fxd_log', array() ), -80 ) ) as $l ) { echo '<div>' . esc_html( $l ) . '</div>'; } ?></div>
	<script>
	(function(){var nonce=<?php echo wp_json_encode( wp_create_nonce( 'fxd' ) ); ?>,run=false,t=null,log=document.getElementById('fxd-log'),st=document.querySelector('[data-fxd-status]');
	function add(l){var d=document.createElement('div');d.textContent=l;d.className=/^(ERR|RETRY|NOKEY)/.test(l)?'e':(/^DONE/.test(l)?'o':(/^(CHECK|NOSRC)/.test(l)?'w':''));log.insertBefore(d,log.firstChild)}
	function step(){if(!run)return;st.textContent='Pracuję…';var f=new FormData();f.append('action','fxd_batch');f.append('_ajax_nonce',nonce);
		fetch(ajaxurl,{method:'POST',body:f,credentials:'same-origin'}).then(function(r){return r.json()}).then(function(r){if(!r||!r.success){add('ERR odpowiedź serwera');run=false;return}
			(r.data.log||[]).forEach(add);document.querySelector('[data-fxd-left]').textContent=r.data.counts.left;
			if(r.data.stop){add('STOP – sprawdź klucz OpenAI');run=false;st.textContent='Zatrzymano.';return}
			if(!r.data.counts.left){add('ZAKOŃCZONO');run=false;st.textContent='Gotowe.';return}
			t=setTimeout(step,r.data.wait?r.data.wait*1000:500);if(r.data.wait)add('Czekam '+r.data.wait+' s (limit API)…')})
		.catch(function(){add('Błąd połączenia – ponawiam za 15 s');t=setTimeout(step,15000)})}
	document.querySelector('[data-fxd-run]').addEventListener('click',function(){if(run)return;run=true;step()});
	document.querySelector('[data-fxd-stop]').addEventListener('click',function(){run=false;clearTimeout(t);st.textContent='Zatrzymano.'});
	})();
	</script>
	<?php
}

function fxd_history_page( $only_check ) {
	$rows = (array) get_option( 'fxd_history', array() );
	if ( $only_check ) {
		global $wpdb;
		$rows = array();
		foreach ( (array) $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_fxd_info'" ) as $r ) { // phpcs:ignore
			$i = maybe_unserialize( $r->meta_value );
			if ( is_array( $i ) && in_array( $i['flag'] ?? '', array( 'check', 'err', 'nosrc' ), true ) ) {
				$rows[] = array( 'id' => (int) $r->post_id, 'at' => (int) ( $i['at'] ?? 0 ), 'flag' => $i['flag'], 'parts' => array(), 'notes' => $i['notes'] ?? array() );
			}
		}
		usort( $rows, function ( $a, $b ) {
			return array( 'err' => 0, 'check' => 1, 'nosrc' => 2 )[ $a['flag'] ] <=> array( 'err' => 0, 'check' => 1, 'nosrc' => 2 )[ $b['flag'] ] ?: $b['at'] <=> $a['at'];
		} );
		echo '<p class="description">„Do sprawdzenia” – AI chciało użyć technologii, której nie ma w źródłach (ten fragment nie został opublikowany), albo wystąpił błąd. „Mało źródeł” – opis napisany tylko z kategorii i atrybutów; warto uzupełnić opis w BaseLinkerze.</p>';
	} else {
		echo '<p class="description">Ostatnie 500 zmian. „Przywróć” wstawia poprzednią wersję (przechowujemy 3 ostatnie) i automat już jej nie zmieni.</p>';
	}
	$parts = array( 'content' => 'opis główny', 'excerpt' => 'krótki', 'meta' => 'meta' );
	echo '<table class="widefat striped"><thead><tr><th>Produkt</th><th>Kiedy</th><th>Co</th><th>Stan</th><th></th></tr></thead><tbody>';
	foreach ( array_slice( $rows, 0, 500 ) as $r ) {
		$t = get_the_title( $r['id'] );
		if ( ! $t ) {
			continue;
		}
		echo '<tr><td><a href="' . esc_url( get_permalink( $r['id'] ) ) . '" target="_blank">' . esc_html( $t ) . '</a> <small>(<a href="' . esc_url( get_edit_post_link( $r['id'] ) ) . '">edytuj</a>)</small>' . ( ! empty( $r['notes'] ) ? '<br><small style="color:#b32d2e">' . esc_html( implode( ' · ', (array) $r['notes'] ) ) . '</small>' : '' ) . '</td>';
		echo '<td>' . esc_html( $r['at'] ? wp_date( 'd.m H:i', $r['at'] ) : '' ) . '</td><td>' . esc_html( implode( ', ', array_map( function ( $k ) use ( $parts ) {
			return $parts[ $k ] ?? $k;
		}, (array) $r['parts'] ) ) ) . '</td><td>' . esc_html( fxd_flag_label( $r['flag'] ) ) . '</td>';
		echo '<td style="white-space:nowrap"><button class="button button-small" data-fxd-one="' . (int) $r['id'] . '">Napisz ponownie</button> ';
		if ( ! $only_check ) {
			echo '<button class="button button-small" data-fxd-restore="' . (int) $r['id'] . '">Przywróć</button>';
		}
		echo ' <span data-fxd-msg></span></td></tr>';
	}
	if ( ! $rows ) {
		echo '<tr><td colspan="5">Brak.</td></tr>';
	}
	echo '</tbody></table>';
}

function fxd_settings_page() {
	$s = fxd_settings();
	if ( isset( $_GET['zapisano'] ) ) { // phpcs:ignore
		echo '<div class="notice notice-success"><p>Zapisano. Produkty zostaną sprawdzone ponownie według nowych zasad (napisane już teksty nie są pisane drugi raz).</p></div>';
	}
	$sel = function ( $name, $opts ) use ( $s ) {
		$h = '<select name="fxd[' . esc_attr( $name ) . ']">';
		foreach ( $opts as $v => $l ) {
			$h .= '<option value="' . esc_attr( $v ) . '"' . selected( $s[ $name ], $v, false ) . '>' . esc_html( $l ) . '</option>';
		}
		return $h . '</select>';
	};
	$key = fxd_key();
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'fxd_save' ); ?><input type="hidden" name="action" value="fxd_save">
		<table class="form-table" role="presentation">
			<tr><th>Klucz OpenAI</th><td><input type="password" class="regular-text" name="fxd[api_key]" autocomplete="new-password" placeholder="<?php echo $key ? esc_attr( 'zapisany …' . substr( $key, -4 ) . ' – zostaw puste' ) : 'sk-…'; ?>"><p class="description">Puste = klucz z wp-config.php (<code>FOX_OPISY_AI_KEY</code>) albo z wtyczki opisów zdjęć. „usun” = skasuj.</p></td></tr>
			<tr><th>Klucz Serper (wyszukiwanie Google)</th><td><input type="password" class="regular-text" name="fxd[serper_key]" autocomplete="new-password" placeholder="<?php echo fxd_serper_key() ? esc_attr( 'zapisany …' . substr( fxd_serper_key(), -4 ) ) : 'z serper.dev'; ?>"><p class="description">Do znajdowania stron produktu na foxracing.com i w zaufanych sklepach.</p></td></tr>
			<tr><th>Model – pisanie</th><td><?php echo $sel( 'model_write', array( 'gpt-4.1' => 'gpt-4.1 (zalecany)', 'gpt-4o' => 'gpt-4o', 'gpt-5' => 'gpt-5', 'gpt-5-mini' => 'gpt-5-mini', 'gpt-4.1-mini' => 'gpt-4.1-mini (tańszy)' ) ); // phpcs:ignore ?></td></tr>
			<tr><th>Model – fakty ze źródeł</th><td><?php echo $sel( 'model_facts', array( 'gpt-4.1-mini' => 'gpt-4.1-mini (zalecany)', 'gpt-4.1' => 'gpt-4.1', 'gpt-4o' => 'gpt-4o', 'gpt-5-mini' => 'gpt-5-mini' ) ); // phpcs:ignore ?></td></tr>
			<tr><th>Zakres</th><td><?php echo $sel( 'scope', array( 'instock' => 'Tylko produkty dostępne (na stanie)', 'all' => 'Wszystkie opublikowane' ) ); // phpcs:ignore ?></td></tr>
			<tr><th>Opis główny</th><td><?php echo $sel( 'content', array( 'weak' => 'Tylko pusty, słaby albo napisany przez v18 bez źródeł', 'all' => 'Każdy (oprócz już napisanych)', 'off' => 'Nie ruszaj' ) ); // phpcs:ignore ?> słaby = krótszy niż <input type="number" name="fxd[weak_chars]" value="<?php echo (int) $s['weak_chars']; ?>" style="width:80px"> znaków</td></tr>
			<tr><th>Krótki opis</th><td><?php echo $sel( 'excerpt', array( 'weak' => 'Pusty, słaby albo z v18', 'all' => 'Każdy (oprócz już napisanych)', 'off' => 'Nie ruszaj' ) ); // phpcs:ignore ?></td></tr>
			<tr><th>Meta opis (Google)</th><td><?php echo $sel( 'meta', array( 'weak' => 'Pusty albo z v18', 'all' => 'Każdy (oprócz już napisanych)', 'off' => 'Nie ruszaj' ) ); // phpcs:ignore ?></td></tr>
			<tr><th>Automat</th><td><label><input type="checkbox" name="fxd[auto]" value="1" <?php checked( $s['auto'], 1 ); ?>> Pracuj w tle (co 5 minut), także dla nowych i zmienionych produktów</label></td></tr>
			<tr><th>Zdjęcie jako źródło</th><td><label><input type="checkbox" name="fxd[vision]" value="1" <?php checked( $s['vision'], 1 ); ?>> AI może opisać cechy jednoznacznie widoczne na zdjęciu (daszek, zapięcie, wzór)</label></td></tr>
			<tr><th>Marka</th><td><input type="text" name="fxd[brand]" value="<?php echo esc_attr( $s['brand'] ); ?>"></td></tr>
			<tr><th>Zaufane strony</th><td><textarea class="large-text code" rows="8" name="fxd[domains]"><?php echo esc_textarea( $s['domains'] ); ?></textarea><p class="description">Jedna domena na linię. Strony foxracing.* liczą się jako oficjalne (najwyższa wiarygodność).</p></td></tr>
			<tr><th>Technologie pod kontrolą</th><td><textarea class="large-text code" rows="8" name="fxd[tech]"><?php echo esc_textarea( $s['tech'] ); ?></textarea><p class="description">Jeśli AI użyje którejś z tych nazw, a nie ma jej w źródłach – tekst jest poprawiany, a gdy dalej jest, nie zostanie opublikowany.</p></td></tr>
			<tr><th>Styl</th><td><textarea class="large-text" rows="3" name="fxd[style]"><?php echo esc_textarea( $s['style'] ); ?></textarea></td></tr>
		</table>
		<?php submit_button( 'Zapisz' ); ?>
	</form>
	<?php
}
