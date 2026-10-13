<?php
/**
 * Narzędzia → Porównanie SEO (stary motyw ↔ nowy motyw).
 *
 * Uruchamiane na stronie testowej z nowym motywem (kopia sklepu): dla każdego adresu pobiera
 * tę samą podstronę z działającego sklepu (stary motyw, np. https://fox.sklep.pl) i z tej strony
 * (nowy motyw), a potem porównuje to, co widzi Google:
 * tytuł, opis, canonical, robots, H1, H2/H3, ile tekstu ze starej strony jest na nowej,
 * dane strukturalne, okruszki, liczba linków wewnętrznych, obrazki bez alt.
 * Nic nie zmienia w sklepie – tylko czyta strony.
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', function () {
	add_management_page( 'Porównanie SEO', 'Porównanie SEO', 'manage_options', 'fx-seo-compare', 'fx_seo_cmp_page' );
} );

/** Przykładowe adresy: strona główna, kategorie z największą liczbą produktów, produkty, wpisy. */
function fx_seo_cmp_sample() {
	$urls = array( '/' );
	$cats = get_terms( array( 'taxonomy' => 'product_cat', 'orderby' => 'count', 'order' => 'DESC', 'number' => 12, 'hide_empty' => true ) );
	foreach ( is_wp_error( $cats ) ? array() : $cats as $t ) {
		$urls[] = wp_make_link_relative( get_term_link( $t ) );
	}
	$prods = get_posts( array( 'post_type' => 'product', 'post_status' => 'publish', 'numberposts' => 12, 'orderby' => 'rand', 'fields' => 'ids' ) );
	foreach ( $prods as $id ) {
		$urls[] = wp_make_link_relative( get_permalink( $id ) );
	}
	$kits = get_posts( array( 'post_type' => 'product', 'post_status' => 'publish', 'numberposts' => 2, 's' => 'Strój', 'fields' => 'ids' ) );
	foreach ( $kits as $id ) {
		$urls[] = wp_make_link_relative( get_permalink( $id ) );
	}
	$posts = get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'numberposts' => 3, 'fields' => 'ids' ) );
	foreach ( $posts as $id ) {
		$urls[] = wp_make_link_relative( get_permalink( $id ) );
	}
	return array_values( array_unique( $urls ) );
}

function fx_seo_cmp_page() {
	$ref = get_option( 'fx_seo_cmp_ref', 'https://fox.sklep.pl' );
	?>
	<div class="wrap">
		<h1>Porównanie SEO: stary motyw ↔ nowy motyw</h1>
		<p>Uruchom na <strong>stronie testowej z nowym motywem</strong> (kopia sklepu). Dla każdego adresu narzędzie pobiera tę samą podstronę z działającego sklepu (stary motyw) i z tej strony (nowy motyw), i porównuje to, co widzi Google. Nic nie zmienia – tylko czyta strony.</p>
		<table class="form-table" role="presentation">
			<tr><th><label for="fx-ref">Działający sklep (stary motyw)</label></th><td><input type="url" id="fx-ref" class="regular-text" value="<?php echo esc_attr( $ref ); ?>"></td></tr>
			<tr><th><label for="fx-urls">Adresy do sprawdzenia</label></th><td><textarea id="fx-urls" rows="10" class="large-text code"><?php echo esc_textarea( implode( "\n", fx_seo_cmp_sample() ) ); ?></textarea><p class="description">Jeden adres w linii (sama ścieżka, np. /kategoria-produktu/off-road/kaski/). Na start: strona główna, 12 największych kategorii, 12 losowych produktów, komplety, 3 wpisy.</p></td></tr>
		</table>
		<p><button class="button button-primary" id="fx-run">Porównaj</button> <span id="fx-prog"></span></p>
		<div id="fx-sum"></div>
		<table class="widefat striped" id="fx-res" style="display:none">
			<thead><tr><th style="width:22%">Adres</th><th>H1</th><th>Tytuł / opis / canonical / robots</th><th>Nagłówki H2–H3</th><th>Tekst ze starej strony</th><th>Dane strukturalne, okruszki, linki, alt</th></tr></thead>
			<tbody></tbody>
		</table>
		<p style="margin-top:16px"><button class="button" id="fx-csv" style="display:none">Pobierz raport CSV</button></p>
		<style>
			#fx-res td{vertical-align:top;font-size:12.5px}
			.fx-ok{color:#1a7f37;font-weight:600}.fx-warn{color:#b26a00;font-weight:600}.fx-bad{color:#c62828;font-weight:700}
			#fx-res details summary{cursor:pointer;color:#2271b1}
			#fx-res code{font-size:11.5px;white-space:pre-wrap}
		</style>
	</div>
	<script>
	(function ($) {
		var rows = [];
		function esc(s) { return $('<div>').text(s == null ? '' : String(s)).html(); }
		function cls(st) { return st === 'ok' ? 'fx-ok' : (st === 'warn' ? 'fx-warn' : 'fx-bad'); }
		function list(a) { return a && a.length ? '<code>' + a.map(esc).join('\n') + '</code>' : '<em>brak</em>'; }
		$('#fx-run').on('click', function () {
			var urls = $('#fx-urls').val().split(/\n+/).map(function (u) { return u.trim(); }).filter(Boolean);
			var ref = $('#fx-ref').val().trim();
			rows = []; $('#fx-res').show().find('tbody').empty(); $('#fx-sum').empty(); $('#fx-csv').hide();
			var i = 0, stats = { ok: 0, warn: 0, bad: 0 };
			(function next() {
				if (i >= urls.length) {
					$('#fx-prog').text('Gotowe: ' + urls.length + ' adresów.');
					$('#fx-sum').html('<p><span class="fx-ok">Bez zmian: ' + stats.ok + '</span> · <span class="fx-warn">Do sprawdzenia: ' + stats.warn + '</span> · <span class="fx-bad">Problem: ' + stats.bad + '</span></p>');
					$('#fx-csv').show();
					return;
				}
				var u = urls[i++];
				$('#fx-prog').text('Sprawdzam ' + i + ' / ' + urls.length + ': ' + u);
				$.post(ajaxurl, { action: 'fx_seo_cmp', nonce: '<?php echo esc_js( wp_create_nonce( 'fx_seo_cmp' ) ); ?>', ref: ref, path: u }).done(function (r) {
					if (!r || !r.success) {
						$('#fx-res tbody').append('<tr><td>' + esc(u) + '</td><td colspan="5" class="fx-bad">' + esc(r && r.data ? r.data : 'błąd') + '</td></tr>');
						stats.bad++; next(); return;
					}
					var d = r.data; rows.push(d); stats[d.status]++;
					var h1 = '<span class="' + cls(d.h1.status) + '">' + (d.h1.status === 'ok' ? 'bez zmian' : 'ZMIANA') + '</span><br>stary: ' + list(d.h1.old) + '<br>nowy: ' + list(d.h1.new);
					var meta = d.meta.map(function (m) { return '<div><span class="' + cls(m.status) + '">' + esc(m.name) + '</span>' + (m.status === 'ok' ? '' : '<br>stary: <code>' + esc(m.old) + '</code><br>nowy: <code>' + esc(m.new) + '</code>') + '</div>'; }).join('');
					var hd = '<span class="' + cls(d.heads.status) + '">' + esc(d.heads.note) + '</span>' + (d.heads.lost.length ? '<details><summary>Brakujące (' + d.heads.lost.length + ')</summary>' + list(d.heads.lost) + '</details>' : '') + (d.heads.added.length ? '<details><summary>Nowe (' + d.heads.added.length + ')</summary>' + list(d.heads.added) + '</details>' : '');
					var tx = '<span class="' + cls(d.text.status) + '">' + d.text.pct + '% zdań</span> (' + d.text.found + ' z ' + d.text.total + ')<br>słów: ' + d.text.words_old + ' → ' + d.text.words_new + (d.text.missing.length ? '<details><summary>Brakujące fragmenty</summary>' + list(d.text.missing) + '</details>' : '');
					var other = d.other.map(function (m) { return '<div><span class="' + cls(m.status) + '">' + esc(m.name) + '</span>: ' + esc(m.old) + ' → ' + esc(m.new) + '</div>'; }).join('');
					$('#fx-res tbody').append('<tr><td><a href="' + esc(d.url_new) + '" target="_blank">' + esc(u) + '</a><br><span class="' + cls(d.status) + '">' + (d.status === 'ok' ? 'OK' : (d.status === 'warn' ? 'do sprawdzenia' : 'PROBLEM')) + '</span></td><td>' + h1 + '</td><td>' + meta + '</td><td>' + hd + '</td><td>' + tx + '</td><td>' + other + '</td></tr>');
					next();
				}).fail(function () { $('#fx-res tbody').append('<tr><td>' + esc(u) + '</td><td colspan="5" class="fx-bad">Błąd połączenia</td></tr>'); stats.bad++; next(); });
			})();
		});
		$('#fx-csv').on('click', function () {
			var out = [['adres', 'status', 'H1 stary', 'H1 nowy', 'tytuł stary', 'tytuł nowy', 'zdania %', 'słowa stare', 'słowa nowe', 'brakujące H2/H3', 'schema stare', 'schema nowe']];
			rows.forEach(function (d) {
				var t = d.meta.filter(function (m) { return m.name === 'Tytuł'; })[0] || {};
				var s = d.other.filter(function (m) { return m.name === 'Dane strukturalne'; })[0] || {};
				out.push([d.path, d.status, d.h1.old.join(' | '), d.h1.new.join(' | '), t.old || '', t.new || '', d.text.pct, d.text.words_old, d.text.words_new, d.heads.lost.join(' | '), s.old || '', s.new || '']);
			});
			var csv = out.map(function (r) { return r.map(function (c) { return '"' + String(c).replace(/"/g, '""') + '"'; }).join(';'); }).join('\n');
			var a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['﻿' + csv], { type: 'text/csv' })); a.download = 'porownanie-seo.csv'; a.click();
		});
	})(jQuery);
	</script>
	<?php
}

/** Pobranie strony. Dla tej strony – z ciasteczkami zalogowanego admina (omija tryb „wkrótce”/podgląd). */
function fx_seo_cmp_fetch( $url, $own = false ) {
	$args = array( 'timeout' => 25, 'redirection' => 3, 'user-agent' => 'Mozilla/5.0 (compatible; FoxSeoCompare/1.0)', 'sslverify' => false );
	if ( $own ) {
		$cookies = array();
		foreach ( $_COOKIE as $k => $v ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( 0 === strpos( $k, 'wordpress_' ) || 0 === strpos( $k, 'wp_woocommerce_session' ) ) {
				$cookies[] = new WP_Http_Cookie( array( 'name' => $k, 'value' => is_string( $v ) ? $v : '' ) );
			}
		}
		$args['cookies'] = $cookies;
	}
	$r = wp_remote_get( $url, $args );
	if ( is_wp_error( $r ) ) {
		return $r;
	}
	return array( 'code' => (int) wp_remote_retrieve_response_code( $r ), 'html' => (string) wp_remote_retrieve_body( $r ) );
}

/** Wyciągnięcie elementów ważnych dla SEO. */
function fx_seo_cmp_parse( $html ) {
	$d = new DOMDocument();
	libxml_use_internal_errors( true );
	$d->loadHTML( '<?xml encoding="utf-8"?>' . $html );
	libxml_clear_errors();
	$x   = new DOMXPath( $d );
	$txt = function ( $n ) {
		return trim( preg_replace( '/\s+/u', ' ', $n->textContent ) );
	};
	// Pomijamy pasek administratora, skrypty i style.
	foreach ( $x->query( '//*[@id="wpadminbar"]|//script[not(@type="application/ld+json")]|//style|//noscript' ) as $n ) {
		$n->parentNode->removeChild( $n );
	}
	$out = array( 'title' => '', 'desc' => '', 'canonical' => '', 'robots' => '', 'h1' => array(), 'heads' => array(), 'sentences' => array(), 'words' => 0, 'schema' => array(), 'breadcrumb' => false, 'links' => 0, 'listed' => array(), 'noalt' => 0, 'text' => '' );
	$t = $x->query( '//title' );
	if ( $t->length ) {
		$out['title'] = $txt( $t->item( 0 ) );
	}
	foreach ( $x->query( '//meta[@name="description"]' ) as $m ) {
		$out['desc'] = trim( $m->getAttribute( 'content' ) );
	}
	foreach ( $x->query( '//meta[@name="robots"]' ) as $m ) {
		$out['robots'] = trim( $m->getAttribute( 'content' ) );
	}
	foreach ( $x->query( '//link[@rel="canonical"]' ) as $m ) {
		$out['canonical'] = trim( $m->getAttribute( 'href' ) );
	}
	foreach ( $x->query( '//h1' ) as $h ) {
		$out['h1'][] = $txt( $h );
	}
	// Linki wewnętrzne w treści (bez menu, nagłówka i stopki) – unikalne ścieżki.
	$links = array();
	foreach ( $x->query( '//body//a[@href][not(ancestor::header) and not(ancestor::footer) and not(ancestor::nav)]' ) as $a ) {
		$h = (string) $a->getAttribute( 'href' );
		if ( '' !== $h && '#' !== $h[0] && ! preg_match( '#^(mailto|tel|javascript):#i', $h ) ) {
			$links[ untrailingslashit( (string) wp_parse_url( $h, PHP_URL_PATH ) ) ] = 1;
		}
	}
	$out['links'] = count( $links );
	// Karty produktów (listy w kategorii, podobne, polecane): zapisujemy same linki, a karty usuwamy,
	// żeby nie mieszały się z treścią strony (tytuły kart to nie są „zgubione nagłówki”).
	$out['listed'] = array();
	// Tylko główna lista (kategoria/sklep) – bez sekcji „podobne”, „polecane” itp., które bywają losowe.
	$main_list = '//ul[contains(concat(" ",normalize-space(@class)," ")," products ")][not(ancestor::*[contains(@class,"related") or contains(@class,"up-sells") or contains(@class,"upsells") or contains(@class,"cross-sells") or contains(@class,"fx-series") or contains(@class,"fx-kit") or contains(@class,"fx-home")])]/li';
	foreach ( $x->query( $main_list ) as $li ) {
		// Wszystkie linki w karcie – także kropki kolorów, które prowadzą do pozostałych kolorów modelu.
		foreach ( $x->query( './/a[@href]', $li ) as $a ) {
			$h = (string) $a->getAttribute( 'href' );
			if ( false === strpos( $h, 'add-to-cart' ) && '#' !== substr( $h, 0, 1 ) ) {
				$out['listed'][] = untrailingslashit( (string) wp_parse_url( $h, PHP_URL_PATH ) );
			}
		}
	}
	$out['listed'] = array_values( array_unique( $out['listed'] ) );
	$noise = '//ul[contains(concat(" ",normalize-space(@class)," ")," products ")]|//aside|//*[contains(concat(" ",normalize-space(@class)," ")," widget ")]|//*[@id="comments"]|//*[@id="respond"]|//*[contains(@class,"comment-respond")]';
	foreach ( iterator_to_array( $x->query( $noise ) ) as $n ) {
		if ( $n->parentNode ) {
			$n->parentNode->removeChild( $n );
		}
	}
	foreach ( $x->query( '//h2[not(ancestor::footer)]|//h3[not(ancestor::footer)]' ) as $h ) {
		$v = $txt( $h );
		if ( '' !== $v ) {
			$out['heads'][] = strtoupper( $h->nodeName ) . ': ' . $v;
		}
	}
	// Tekst treści: akapity, elementy list, komórki tabel (bez nagłówka i stopki strony).
	$body = '';
	foreach ( $x->query( '//p[not(ancestor::header) and not(ancestor::footer) and not(ancestor::nav)]|//li[not(ancestor::header) and not(ancestor::footer) and not(ancestor::nav)]|//td' ) as $p ) {
		$v = $txt( $p );
		if ( '' !== $v ) {
			$body .= ' ' . $v;
			foreach ( preg_split( '/(?<=[.!?])\s+/u', $v ) as $s ) {
				if ( mb_strlen( $s ) >= 40 ) {
					$out['sentences'][] = $s;
				}
			}
		}
	}
	$out['words']     = count( preg_split( '/\s+/u', trim( $body ), -1, PREG_SPLIT_NO_EMPTY ) );
	$all              = $d->getElementsByTagName( 'body' )->length ? $txt( $d->getElementsByTagName( 'body' )->item( 0 ) ) : '';
	$out['text']      = mb_strtolower( $all );
	$out['sentences'] = array_values( array_unique( $out['sentences'] ) );
	foreach ( $x->query( '//script[@type="application/ld+json"]' ) as $s ) {
		$j = json_decode( $s->textContent, true );
		$walk = function ( $j ) use ( &$walk, &$out ) {
			if ( is_array( $j ) ) {
				if ( isset( $j['@type'] ) ) {
					foreach ( (array) $j['@type'] as $ty ) {
						$out['schema'][] = $ty;
					}
				}
				foreach ( $j as $v ) {
					if ( is_array( $v ) ) {
						$walk( $v );
					}
				}
			}
		};
		$walk( $j );
	}
	foreach ( $x->query( '//*[@itemtype]' ) as $m ) {
		$out['schema'][] = basename( $m->getAttribute( 'itemtype' ) );
	}
	$out['schema']     = array_values( array_unique( $out['schema'] ) );
	sort( $out['schema'] );
	$out['breadcrumb'] = in_array( 'BreadcrumbList', $out['schema'], true ) || $x->query( '//nav[contains(@class,"breadcrumb")]|//*[contains(@class,"breadcrumb")]' )->length > 0;
	foreach ( $x->query( '//img' ) as $img ) {
		if ( '' === trim( $img->getAttribute( 'alt' ) ) && false === strpos( $img->getAttribute( 'class' ), 'fx-ph' ) ) {
			$out['noalt']++;
		}
	}
	return $out;
}

add_action( 'wp_ajax_fx_seo_cmp', function () {
	if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'fx_seo_cmp', 'nonce', false ) ) {
		wp_send_json_error( 'Brak uprawnień.' );
	}
	$ref  = untrailingslashit( esc_url_raw( wp_unslash( isset( $_POST['ref'] ) ? $_POST['ref'] : '' ) ) ); // phpcs:ignore
	$path = '/' . ltrim( (string) wp_parse_url( sanitize_text_field( wp_unslash( isset( $_POST['path'] ) ? $_POST['path'] : '/' ) ), PHP_URL_PATH ), '/' ); // phpcs:ignore
	$q    = (string) wp_parse_url( sanitize_text_field( wp_unslash( isset( $_POST['path'] ) ? $_POST['path'] : '' ) ), PHP_URL_QUERY ); // phpcs:ignore
	if ( ! $ref ) {
		wp_send_json_error( 'Podaj adres działającego sklepu.' );
	}
	update_option( 'fx_seo_cmp_ref', $ref, false );
	$url_old = $ref . $path . ( $q ? '?' . $q : '' );
	$url_new = home_url( $path ) . ( $q ? '?' . $q : '' );
	$old     = fx_seo_cmp_fetch( $url_old );
	$new     = fx_seo_cmp_fetch( $url_new, true );
	if ( is_wp_error( $old ) ) {
		wp_send_json_error( 'Nie udało się pobrać starej strony: ' . $old->get_error_message() );
	}
	if ( is_wp_error( $new ) ) {
		wp_send_json_error( 'Nie udało się pobrać nowej strony: ' . $new->get_error_message() );
	}
	$a = fx_seo_cmp_parse( $old['html'] );
	$b = fx_seo_cmp_parse( $new['html'] );

	$norm = function ( $s ) {
		return mb_strtolower( trim( preg_replace( '/\s+/u', ' ', html_entity_decode( (string) $s, ENT_QUOTES, 'UTF-8' ) ) ) );
	};
	$same = function ( $x, $y ) use ( $norm ) {
		return $norm( $x ) === $norm( $y );
	};
	$host = function ( $u ) {
		return preg_replace( '#^https?://[^/]+#', '', (string) $u );
	};

	$h1_ok = count( $a['h1'] ) === count( $b['h1'] ) && $same( implode( '|', $a['h1'] ), implode( '|', $b['h1'] ) );
	$h1_st = 'bad';
	$h1_nt = '';
	if ( $h1_ok ) {
		$h1_st = 'ok';
	} elseif ( 1 === count( $b['h1'] ) && $a['h1'] && $same( $a['h1'][0], $b['h1'][0] ) ) {
		$h1_st = 'warn';
		$h1_nt = 'Główny H1 bez zmian. Dodatkowe H1 ze starej strony (zwykle z opisu) są teraz H2 – strona ma jeden H1, tak jak powinna.';
	} elseif ( 1 === count( $b['h1'] ) && $a['h1'] && false !== mb_strpos( $norm( $b['h1'][0] ), $norm( $a['h1'][0] ) ) ) {
		$h1_st = 'warn';
	}
	$h1 = array( 'status' => $h1_st, 'old' => $a['h1'], 'new' => $b['h1'], 'note' => $h1_nt );

	$meta = array(
		array( 'name' => 'Kod HTTP', 'status' => $old['code'] === $new['code'] ? 'ok' : 'bad', 'old' => $old['code'], 'new' => $new['code'] ),
		array( 'name' => 'Tytuł', 'status' => $same( $a['title'], $b['title'] ) ? 'ok' : 'warn', 'old' => $a['title'], 'new' => $b['title'] ),
		array( 'name' => 'Opis', 'status' => $same( $a['desc'], $b['desc'] ) ? 'ok' : 'warn', 'old' => $a['desc'], 'new' => $b['desc'] ),
		array( 'name' => 'Canonical', 'status' => $same( $host( $a['canonical'] ), $host( $b['canonical'] ) ) ? 'ok' : 'bad', 'old' => $a['canonical'], 'new' => $b['canonical'] ),
		array( 'name' => 'Robots', 'status' => ( false !== stripos( $b['robots'], 'noindex' ) ) === ( false !== stripos( $a['robots'], 'noindex' ) ) ? 'ok' : 'bad', 'old' => $a['robots'], 'new' => $b['robots'] ),
	);

	// Nagłówki: które teksty nagłówków zniknęły (porównujemy treść, nie poziom).
	$strip = function ( $h ) {
		return preg_replace( '/^H[23]: /', '', $h );
	};
	$hb    = array_map( $norm, array_map( $strip, $b['heads'] ) );
	$ha    = array_map( $norm, array_map( $strip, $a['heads'] ) );
	$lost  = array();
	foreach ( $a['heads'] as $h ) {
		$v = $norm( $strip( $h ) );
		if ( ! in_array( $v, $hb, true ) && false === mb_strpos( $b['text'], $v ) ) {
			$lost[] = $h;
		}
	}
	$added = array();
	foreach ( $b['heads'] as $h ) {
		if ( ! in_array( $norm( $strip( $h ) ), $ha, true ) ) {
			$added[] = $h;
		}
	}
	$heads = array( 'status' => count( $lost ) > 3 ? 'bad' : ( $lost ? 'warn' : 'ok' ), 'note' => count( $a['heads'] ) . ' → ' . count( $b['heads'] ) . ' nagłówków' . ( $lost ? ', brakuje ' . count( $lost ) : '' ), 'lost' => array_slice( $lost, 0, 25 ), 'added' => array_slice( $added, 0, 25 ) );

	// Tekst: ile zdań ze starej strony występuje na nowej.
	$found   = 0;
	$missing = array();
	foreach ( $a['sentences'] as $s ) {
		if ( false !== mb_strpos( $b['text'], $norm( $s ) ) ) {
			$found++;
		} else {
			$missing[] = $s;
		}
	}
	$total = count( $a['sentences'] );
	$pct   = $total ? (int) round( $found / $total * 100 ) : 100;
	$text  = array( 'status' => $pct >= 95 ? 'ok' : ( $pct >= 80 ? 'warn' : 'bad' ), 'pct' => $pct, 'found' => $found, 'total' => $total, 'words_old' => $a['words'], 'words_new' => $b['words'], 'missing' => array_slice( $missing, 0, 15 ) );

	$lost_schema = array_diff( $a['schema'], $b['schema'] );
	$other       = array(
		array( 'name' => 'Dane strukturalne', 'status' => $lost_schema ? 'warn' : 'ok', 'old' => implode( ', ', $a['schema'] ), 'new' => implode( ', ', $b['schema'] ) . ( $lost_schema ? ' (brak: ' . implode( ', ', $lost_schema ) . ')' : '' ) ),
		array( 'name' => 'Okruszki', 'status' => ( ! $a['breadcrumb'] || $b['breadcrumb'] ) ? 'ok' : 'bad', 'old' => $a['breadcrumb'] ? 'tak' : 'nie', 'new' => $b['breadcrumb'] ? 'tak' : 'nie' ),
		array( 'name' => 'Produkty linkowane z listy', 'status' => count( array_diff( $a['listed'], $b['listed'] ) ) ? 'warn' : 'ok', 'old' => count( $a['listed'] ), 'new' => count( $b['listed'] ) . ( array_diff( $a['listed'], $b['listed'] ) ? ' (brak: ' . implode( ', ', array_map( 'basename', array_slice( array_diff( $a['listed'], $b['listed'] ), 0, 8 ) ) ) . ')' : '' ) ),
		array( 'name' => 'Linki w treści', 'status' => $b['links'] >= $a['links'] * 0.6 ? 'ok' : 'warn', 'old' => $a['links'], 'new' => $b['links'] ),
		array( 'name' => 'Obrazki bez alt', 'status' => $b['noalt'] <= $a['noalt'] ? 'ok' : 'warn', 'old' => $a['noalt'], 'new' => $b['noalt'] ),
	);

	$all = array_merge( array( $h1['status'], $heads['status'], $text['status'] ), wp_list_pluck( $meta, 'status' ), wp_list_pluck( $other, 'status' ) );
	wp_send_json_success(
		array(
			'path'    => $path,
			'url_new' => $url_new,
			'status'  => in_array( 'bad', $all, true ) ? 'bad' : ( in_array( 'warn', $all, true ) ? 'warn' : 'ok' ),
			'h1'      => $h1,
			'meta'    => $meta,
			'heads'   => $heads,
			'text'    => $text,
			'other'   => $other,
		)
	);
} );
