<?php
/**
 * Pisanie opisów z faktów, kontrola, zapis z kopią, kolejka i automat.
 *
 * Opis główny powstaje RAZ na model (wspólny dla kolorów), a każdy kolor dostaje własny wstęp,
 * krótki opis i meta opis – mniej zapytań i brak zduplikowanych tekstów w Google.
 * Kontrola: technologia z listy (MIPS, D3O…) użyta w tekście, a nieobecna w faktach = tekst
 * poprawiany; gdy dalej jest – część nie jest publikowana, produkt trafia do „Do sprawdzenia”.
 *
 * @package fox-opisy-ai
 */

defined( 'ABSPATH' ) || exit;

/** Słowa, którymi klienci szukają w kategorii produktu (statystyki wyszukiwarki motywu, 90 dni). */
function fxd_keywords( $p ) {
	if ( ! function_exists( 'fx_srch_log_table' ) || ! function_exists( 'fx_main_category' ) ) {
		return array();
	}
	$main = fx_main_category( $p->get_id() );
	if ( ! $main instanceof WP_Term ) {
		return array();
	}
	$ck  = 'fxd_kw_' . $main->term_id;
	$hit = get_transient( $ck );
	if ( is_array( $hit ) ) {
		return $hit;
	}
	global $wpdb;
	$ids = get_posts( array( 'post_type' => 'product', 'fields' => 'ids', 'posts_per_page' => 400, 'no_found_rows' => true, 'tax_query' => array( array( 'taxonomy' => 'product_cat', 'terms' => $main->term_id ) ) ) ); // phpcs:ignore
	$out = array();
	if ( $ids ) {
		$in  = implode( ',', array_map( 'intval', $ids ) );
		$out = $wpdb->get_col( $wpdb->prepare( 'SELECT q FROM ' . fx_srch_log_table() . " WHERE pid IN ($in) AND ts > %d AND q <> '' GROUP BY q ORDER BY COUNT(*) DESC LIMIT 8", time() - 90 * DAY_IN_SECONDS ) ); // phpcs:ignore
	}
	set_transient( $ck, (array) $out, DAY_IN_SECONDS );
	return (array) $out;
}

/** Fakty pewne i średnie jako tekst dla AI. */
function fxd_facts_text( $facts, $with_low = false ) {
	$t = '';
	foreach ( $facts['facts'] as $f ) {
		if ( 'low' === $f['c'] && ! $with_low ) {
			continue;
		}
		$t .= '- ' . $f['f'] . "\n";
	}
	return $t;
}

/** Ile wiarygodnych faktów pochodzi z prawdziwych źródeł (nie tylko z kategorii / atrybutów). */
function fxd_real_facts( $facts ) {
	$n = 0;
	foreach ( $facts['facts'] as $f ) {
		if ( 'low' !== $f['c'] && array_diff( $f['src'], array( 'a' ) ) ) {
			$n++;
		}
	}
	return $n;
}

/** Dozwolony HTML opisu. */
function fxd_kses( $html ) {
	$ok = array( 'h2' => array(), 'h3' => array(), 'p' => array(), 'ul' => array(), 'ol' => array(), 'li' => array(), 'strong' => array(), 'em' => array(), 'br' => array() );
	return trim( wp_kses( (string) $html, $ok ) );
}

/** Technologie z listy użyte w tekście, których nie ma w faktach. */
function fxd_invented( $html, $facts ) {
	$text = ' ' . mb_strtolower( fxd_text( $html ) ) . ' ';
	// Tylko fakty pewne i średnie – niepewnych („może mieć D3O”) pisarz nie może użyć, więc kontrola też ich nie uznaje.
	$base = ' ' . mb_strtolower( fxd_facts_text( $facts ) . ' ' . $facts['model'] ) . ' ';
	$bad  = array();
	foreach ( fxd_lines( fxd_opt( 'tech' ) ) as $term ) {
		$t   = mb_strtolower( $term );
		$re  = '/(?<![\p{L}\d])' . preg_quote( $t, '/' ) . '(?![\p{L}\d])/u';
		if ( preg_match( $re, $text ) && ! preg_match( $re, $base ) ) {
			$bad[] = $term;
		}
	}
	return array_values( array_unique( $bad ) );
}

function fxd_system() {
	return 'Piszesz opisy produktów dla sklepu FOX.SKLEP.PL (oficjalne produkty FOX Racing: moto, MTB, odzież). Styl: ' . fxd_opt( 'style' )
		. ' TWARDE ZASADY: 1) Używasz WYŁĄCZNIE podanych faktów. Nie dodajesz żadnych technologii, materiałów, norm, wag ani parametrów spoza listy – nawet jeśli „wiesz”, że model je ma. '
		. '2) Nie kopiujesz zdań ze źródeł – piszesz własnymi słowami. 3) Bez cen, wysyłki, gwarancji, porównań z innymi markami i obietnic typu „100% ochrony”. '
		. '4) Nazwy technologii i kluczowe cechy pogrubiasz <strong>. 5) Słowa kluczowe wplatasz naturalnie, bez upychania. 6) Poprawna polszczyzna, bez wielkich liter w całych słowach (poza skrótami: MX, MTB, MIPS…). Zwracasz wyłącznie JSON.';
}

/**
 * Opis główny modelu (wspólny dla kolorów), pamiętany do zmiany faktów.
 *
 * @return string|WP_Error HTML
 */
function fxd_master( $p, $facts, $minimal = false ) {
	$key = 'fxd_master_' . $facts['group'];
	$sig = md5( wp_json_encode( $facts['facts'] ) . FXD_VER . ( $minimal ? 'm' : '' ) );
	$hit = get_option( $key );
	if ( is_array( $hit ) && $hit['sig'] === $sig ) {
		return $hit['html'];
	}
	$kw   = fxd_keywords( $p );
	$user = 'Model: ' . ( $facts['model'] ? $facts['model'] : fxd_context( $p )['name'] ) . "\nRodzaj: " . $facts['type'] . "\nPrzeznaczenie: " . $facts['use']
		. "\nFAKTY (jedyne dozwolone):\n" . fxd_facts_text( $facts )
		. ( $kw ? "\nTak klienci szukają w tej kategorii (użyj 1–3 naturalnie, w odmianie): " . implode( ', ', $kw ) : '' )
		. "\n\nNapisz opis główny modelu – BEZ koloru (opis jest wspólny dla wszystkich wersji kolorystycznych) i BEZ pełnej nazwy produktu w pierwszym zdaniu. ";
	if ( $minimal ) {
		$user .= 'Faktów jest mało: napisz krótko (60–120 słów): <p> czym jest produkt i do czego służy, <ul> z tym, co wynika z faktów. Nic więcej. ';
	} else {
		$user .= 'Struktura (pomiń sekcję, jeśli brak faktów): <p> 2–3 zdania: czym jest i dla kogo; <h2>Najważniejsze cechy</h2><ul> 4–8 punktów; <h2>Technologie i materiały</h2> akapit lub lista; <h2>Dopasowanie i komfort</h2> akapit; <h2>Do czego</h2> 1–2 zdania o zastosowaniu. Długość zależna od liczby faktów (150–400 słów) – nie rozwlekaj. ';
	}
	$user .= 'Zwróć JSON: {"body":"<p>…</p>…"}';
	$j     = fxd_ai( (string) fxd_opt( 'model_write' ), fxd_system(), $user, 2200 );
	if ( is_wp_error( $j ) ) {
		return $j;
	}
	$html = fxd_kses( $j['body'] ?? '' );
	$bad  = fxd_invented( $html, $facts );
	if ( $bad ) {
		$j = fxd_ai( (string) fxd_opt( 'model_write' ), fxd_system(), $user . "\nUWAGA: poprzednia wersja wymieniała technologie spoza faktów (" . implode( ', ', $bad ) . '). Usuń je całkowicie.', 2200 );
		if ( is_wp_error( $j ) ) {
			return $j;
		}
		$html = fxd_kses( $j['body'] ?? '' );
		$bad  = fxd_invented( $html, $facts );
		if ( $bad ) {
			return new WP_Error( 'invented', 'Opis wymienia technologie spoza źródeł: ' . implode( ', ', $bad ) );
		}
	}
	if ( mb_strlen( fxd_text( $html ) ) < 60 ) {
		return new WP_Error( 'short', 'AI zwróciło za krótki opis.' );
	}
	update_option( $key, array( 'sig' => $sig, 'html' => $html, 'at' => time() ), false );
	return $html;
}

/**
 * Część dla konkretnego produktu (koloru): wstęp, krótki opis, meta.
 *
 * @return array|WP_Error [ intro, short, meta ]
 */
function fxd_variant( $p, $facts, $master ) {
	$ctx  = fxd_context( $p );
	$user = 'Produkt: ' . $ctx['name'] . ( $ctx['color'] ? "\nKolor: " . $ctx['color'] : '' ) . "\nKategoria: " . $ctx['category']
		. "\nFAKTY (jedyne dozwolone):\n" . fxd_facts_text( $facts )
		. ( $master ? "\nOpis główny modelu (już napisany – nie powtarzaj jego zdań):\n" . mb_substr( fxd_text( $master ), 0, 1200 ) : '' )
		. "\n\nNapisz:\n- intro: <p> 1–2 zdania otwierające opis TEGO produktu – z nazwą modelu i kolorem/wersją, naturalnie (np. „Kask … w wersji czarno-białej…”). Bez technologii spoza faktów.\n"
		. "- short: krótki opis – WYŁĄCZNIE <ul> z 3–5 <li>, bez nazwy produktu, od razu konkrety, nazwy technologii w <strong>.\n"
		. "- meta: meta opis do Google, 140–155 znaków, z nazwą modelu i najważniejszą cechą, bez cudzysłowów i wykrzykników.\n"
		. 'Zwróć JSON: {"intro":"<p>…</p>","short":"<ul><li>…</li></ul>","meta":"…"}';
	$j    = fxd_ai( (string) fxd_opt( 'model_write' ), fxd_system(), $user, 900 );
	if ( is_wp_error( $j ) ) {
		return $j;
	}
	$out = array(
		'intro' => fxd_kses( $j['intro'] ?? '' ),
		'short' => fxd_kses( $j['short'] ?? '' ),
		'meta'  => trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) ( $j['meta'] ?? '' ) ) ), " \"'„”" ),
	);
	if ( mb_strlen( $out['meta'] ) > 158 ) {
		$out['meta'] = rtrim( mb_substr( $out['meta'], 0, 155 ), ' ,;.–-' ) . '…';
	}
	$bad = fxd_invented( $out['intro'] . $out['short'] . ' ' . $out['meta'], $facts );
	if ( $bad ) {
		return new WP_Error( 'invented', 'Krótki opis wymienia technologie spoza źródeł: ' . implode( ', ', $bad ) );
	}
	return $out;
}

/* =====================================================================
 * Co trzeba napisać w produkcie
 * ===================================================================== */

function fxd_meta_key() {
	return defined( 'RANK_MATH_VERSION' ) && ! defined( 'WPSEO_VERSION' ) ? 'rank_math_description' : '_yoast_wpseo_metadesc';
}

/** Skrót treści – rozpoznanie, czy pole nadal zawiera nasz tekst. */
function fxd_h( $s ) {
	return md5( trim( (string) $s ) );
}

/**
 * Części do napisania: [ 'content' => bool, 'excerpt' => bool, 'meta' => bool ].
 * „Nasz” tekst (nieruszony od zapisu) nie jest pisany ponownie, ręczna zmiana też jest zostawiana,
 * chyba że ktoś (np. import z Base) wstawił pusty / słaby tekst.
 */
function fxd_needs( $p, $base = null ) {
	$id   = $p->get_id();
	$v18  = (bool) get_post_meta( $id, '_fox_v18_full_done', true );
	$h    = (array) get_post_meta( $id, '_fxd_h', true );
	$c    = $p->get_description();
	$e    = $p->get_short_description();
	$m    = (string) get_post_meta( $id, fxd_meta_key(), true );
	$ours = function ( $k, $v ) use ( $h ) {
		return isset( $h[ $k ] ) && $h[ $k ] === fxd_h( $v );
	};
	$need = array( 'content' => false, 'excerpt' => false, 'meta' => false );
	$mode = fxd_opt( 'content' );
	if ( 'all' === $mode ) {
		$need['content'] = ! $ours( 'c', $c );
	} elseif ( 'weak' === $mode && ! $ours( 'c', $c ) ) {
		$need['content'] = mb_strlen( fxd_text( $c ) ) < (int) fxd_opt( 'weak_chars' ) || ( $v18 && ! fxd_shop_desc_trusted( $p, $base ) );
	}
	$mode = fxd_opt( 'excerpt' );
	if ( 'all' === $mode ) {
		$need['excerpt'] = ! $ours( 'e', $e );
	} elseif ( 'weak' === $mode && ! $ours( 'e', $e ) ) {
		$need['excerpt'] = mb_strlen( fxd_text( $e ) ) < 60 || $v18;
	}
	$mode = fxd_opt( 'meta' );
	if ( 'all' === $mode ) {
		$need['meta'] = ! $ours( 'm', $m );
	} elseif ( 'weak' === $mode && ! $ours( 'm', $m ) ) {
		$need['meta'] = mb_strlen( trim( $m ) ) < 50 || $v18;
	}
	return $need;
}

/* =====================================================================
 * Zapis (bez haków zapisu produktu – import z BaseLinkera i inne wtyczki nie są wywoływane)
 * ===================================================================== */

function fxd_save( $p, $parts, $info ) {
	global $wpdb;
	$id   = $p->get_id();
	$post = get_post( $id );
	$prev = array( 'at' => time(), 'content' => $post->post_content, 'excerpt' => $post->post_excerpt, 'meta' => (string) get_post_meta( $id, fxd_meta_key(), true ) );
	$hist = (array) get_post_meta( $id, '_fxd_prev', true );
	array_unshift( $hist, $prev );
	update_post_meta( $id, '_fxd_prev', array_slice( $hist, 0, 3 ) ); // 3 ostatnie wersje do przywrócenia
	$upd = array();
	$h   = (array) get_post_meta( $id, '_fxd_h', true );
	if ( isset( $parts['content'] ) ) {
		$upd['post_content'] = $parts['content'];
		$h['c']              = fxd_h( $parts['content'] );
		// Licznik napisań opisu głównego (bezpiecznik na import z Base, który nadpisuje go z powrotem).
		$n   = array_filter( (array) get_post_meta( $id, '_fxd_rewrites', true ), function ( $t ) {
			return $t > time() - 30 * DAY_IN_SECONDS;
		} );
		$n[] = time();
		update_post_meta( $id, '_fxd_rewrites', array_values( $n ) );
	}
	if ( isset( $parts['excerpt'] ) ) {
		$upd['post_excerpt'] = $parts['excerpt'];
		$h['e']              = fxd_h( $parts['excerpt'] );
	}
	if ( $upd ) {
		$wpdb->update( $wpdb->posts, $upd, array( 'ID' => $id ) ); // phpcs:ignore
		clean_post_cache( $id );
	}
	if ( isset( $parts['meta'] ) ) {
		update_post_meta( $id, fxd_meta_key(), $parts['meta'] );
		$h['m'] = fxd_h( $parts['meta'] );
	}
	update_post_meta( $id, '_fxd_h', $h );
	update_post_meta( $id, '_fxd_info', $info );
	if ( function_exists( 'wc_delete_product_transients' ) ) {
		wc_delete_product_transients( $id );
	}
	do_action( 'litespeed_purge_post', $id );
	// Historia zmian w panelu.
	$log = (array) get_option( 'fxd_history', array() );
	array_unshift( $log, array( 'id' => $id, 'at' => time(), 'parts' => array_keys( $parts ), 'flag' => $info['flag'] ) );
	update_option( 'fxd_history', array_slice( $log, 0, 500 ), false );
}

/** Przywrócenie poprzedniej wersji (pierwszej z kopii). */
function fxd_restore( $id ) {
	global $wpdb;
	$hist = (array) get_post_meta( $id, '_fxd_prev', true );
	if ( empty( $hist[0] ) ) {
		return false;
	}
	$prev = array_shift( $hist );
	$wpdb->update( $wpdb->posts, array( 'post_content' => $prev['content'], 'post_excerpt' => $prev['excerpt'] ), array( 'ID' => (int) $id ) ); // phpcs:ignore
	clean_post_cache( $id );
	update_post_meta( $id, fxd_meta_key(), $prev['meta'] );
	update_post_meta( $id, '_fxd_prev', $hist );
	// Przywrócony tekst to decyzja człowieka – automat go nie nadpisze.
	update_post_meta( $id, '_fxd_h', array( 'c' => fxd_h( $prev['content'] ), 'e' => fxd_h( $prev['excerpt'] ), 'm' => fxd_h( $prev['meta'] ) ) );
	update_post_meta( $id, '_fxd_v', FXD_VER );
	update_post_meta( $id, '_fxd_info', array( 'flag' => 'restored', 'at' => time() ) );
	do_action( 'litespeed_purge_post', $id );
	return true;
}

/* =====================================================================
 * Jeden produkt
 * ===================================================================== */

/**
 * @return array [ 'status' => done|skip|check|nosrc|err|retry|nokey, 'msg' ]
 */
function fxd_process( $id, $force = false ) {
	$p = wc_get_product( $id );
	if ( ! $p || $p->is_type( 'variation' ) ) {
		return array( 'status' => 'skip', 'msg' => 'nie produkt' );
	}
	$name = $p->get_name();
	// Bony podarunkowe i produkty wirtualne – bez opisów AI.
	if ( $p->is_virtual() || $p->is_downloadable() || ( function_exists( 'fx_ship_is_voucher' ) && fx_ship_is_voucher( $id ) ) ) {
		update_post_meta( $id, '_fxd_v', FXD_VER );
		return array( 'status' => 'skip', 'msg' => $name . ': bon / produkt wirtualny – pomijam' );
	}
	$need = $force ? array( 'content' => 'off' !== fxd_opt( 'content' ), 'excerpt' => 'off' !== fxd_opt( 'excerpt' ), 'meta' => 'off' !== fxd_opt( 'meta' ) ) : fxd_needs( $p );
	if ( ! array_filter( $need ) ) {
		update_post_meta( $id, '_fxd_v', FXD_VER );
		return array( 'status' => 'skip', 'msg' => $name . ': opisy w porządku' );
	}
	$facts = fxd_facts( $p, $force );
	if ( is_wp_error( $facts ) ) {
		return fxd_fail( $id, $name, $facts );
	}
	// Ponowna ocena opisu głównego z danymi z Base (czy obecny opis z v18 jest opisem dystrybutora).
	if ( ! $force && $need['content'] ) {
		$need = fxd_needs( $p, fxd_base_product( $p->get_sku() ) );
	}
	// Opis główny nadpisywany z powrotem (np. import z BaseLinkera) – po 3 razach w 30 dni przestajemy, żeby nie płacić w kółko.
	if ( ! $force && $need['content'] && count( array_filter( (array) get_post_meta( $id, '_fxd_rewrites', true ), function ( $t ) {
		return $t > time() - 30 * DAY_IN_SECONDS;
	} ) ) >= 3 ) {
		$need['content'] = false;
		$base_loop       = true;
	}
	$real    = fxd_real_facts( $facts );
	$minimal = $real < 3;
	$flag    = 'ok';
	$parts   = array();
	$notes   = empty( $base_loop ) ? array() : array( 'opis główny wraca do starej wersji przy imporcie (BaseLinker?) – pominięty; wyłącz aktualizację opisów w integracji albo popraw opis w Base' );
	// Opis główny: przy braku źródeł tylko, gdy obecny jest pusty / bardzo krótki (wtedy krótka, bezpieczna wersja z danych produktu).
	if ( $need['content'] && ( ! $minimal || mb_strlen( fxd_text( $p->get_description() ) ) < 80 ) ) {
		$master = fxd_master( $p, $facts, $minimal );
		if ( is_wp_error( $master ) ) {
			if ( in_array( $master->get_error_code(), array( 'retry', 'nokey' ), true ) ) {
				return fxd_fail( $id, $name, $master );
			}
			$notes[] = $master->get_error_message();
			$flag    = 'check';
			$master  = '';
		}
	} else {
		$master = '';
		if ( $need['content'] ) {
			$notes[] = 'opis główny zostawiony – za mało źródeł';
		}
	}
	$var = fxd_variant( $p, $facts, $master );
	if ( is_wp_error( $var ) ) {
		if ( in_array( $var->get_error_code(), array( 'retry', 'nokey' ), true ) ) {
			return fxd_fail( $id, $name, $var );
		}
		$notes[] = $var->get_error_message();
		$flag    = 'check';
		$var     = array( 'intro' => '', 'short' => '', 'meta' => '' );
	}
	if ( $need['content'] && $master ) {
		$parts['content'] = trim( $var['intro'] . "\n" . $master );
	}
	if ( $need['excerpt'] && $var['short'] ) {
		$parts['excerpt'] = $var['short'];
	}
	if ( $need['meta'] && $var['meta'] ) {
		$parts['meta'] = $var['meta'];
	}
	if ( $minimal && 'ok' === $flag ) {
		$flag = 'nosrc';
	}
	$info = array(
		'flag'    => $flag,
		'at'      => time(),
		'real'    => $real,
		'sources' => $facts['sources'],
		'notes'   => $notes,
		'group'   => $facts['group'],
	);
	if ( $parts ) {
		fxd_save( $p, $parts, $info );
	} else {
		update_post_meta( $id, '_fxd_info', $info );
	}
	update_post_meta( $id, '_fxd_v', FXD_VER );
	delete_post_meta( $id, '_fxd_err' );
	$what = $parts ? implode( ', ', array_map( function ( $k ) {
		return array( 'content' => 'opis główny', 'excerpt' => 'krótki opis', 'meta' => 'meta opis' )[ $k ];
	}, array_keys( $parts ) ) ) : 'bez zmian';
	$src  = $facts['web'] . ' stron www, ' . ( in_array( 'B', array_column( $facts['sources'], 'id' ), true ) ? 'Base ✓' : 'Base –' ) . ', faktów: ' . $real;
	return array( 'status' => $parts ? ( 'ok' === $flag ? 'done' : $flag ) : $flag, 'msg' => $name . ': ' . $what . ' (' . $src . ')' . ( $notes ? ' – ' . implode( '; ', $notes ) : '' ) );
}

function fxd_fail( $id, $name, $err ) {
	$code = $err->get_error_code();
	if ( ! in_array( $code, array( 'retry', 'nokey' ), true ) ) {
		$n = (int) get_post_meta( $id, '_fxd_err', true ) + 1;
		update_post_meta( $id, '_fxd_err', $n );
		if ( $n >= 3 ) { // po 3 nieudanych próbach – pomijamy do ręcznego „Wygeneruj teraz”
			update_post_meta( $id, '_fxd_v', FXD_VER );
			update_post_meta( $id, '_fxd_info', array( 'flag' => 'err', 'at' => time(), 'notes' => array( $err->get_error_message() ) ) );
		}
	}
	return array( 'status' => in_array( $code, array( 'retry', 'nokey' ), true ) ? $code : 'err', 'msg' => $name . ': ' . $err->get_error_message() );
}

/* =====================================================================
 * Kolejka i automat
 * ===================================================================== */

/** Produkty do sprawdzenia (bez bieżącej wersji), najpierw te ze starym generatorem v18 i najlepiej sprzedawane. */
function fxd_queue( $limit = 20 ) {
	global $wpdb;
	$stock = 'instock' === fxd_opt( 'scope' ) ? "AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} s WHERE s.post_id = p.ID AND s.meta_key = '_stock_status' AND s.meta_value = 'instock')" : '';
	return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( // phpcs:ignore
		"SELECT p.ID FROM {$wpdb->posts} p
		LEFT JOIN {$wpdb->postmeta} v ON v.post_id = p.ID AND v.meta_key = '_fxd_v'
		LEFT JOIN {$wpdb->postmeta} o ON o.post_id = p.ID AND o.meta_key = '_fox_v18_full_done'
		LEFT JOIN {$wpdb->postmeta} t ON t.post_id = p.ID AND t.meta_key = 'total_sales'
		WHERE p.post_type = 'product' AND p.post_status = 'publish' $stock AND ( v.meta_value IS NULL OR v.meta_value <> %s )
		ORDER BY ( o.meta_id IS NOT NULL ) DESC, CAST( t.meta_value AS UNSIGNED ) DESC, p.ID DESC LIMIT %d",
		FXD_VER,
		$limit
	) ) );
}

function fxd_counts() {
	global $wpdb;
	$stock = 'instock' === fxd_opt( 'scope' ) ? "AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} s WHERE s.post_id = p.ID AND s.meta_key = '_stock_status' AND s.meta_value = 'instock')" : '';
	$all   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE p.post_type = 'product' AND p.post_status = 'publish' $stock" ); // phpcs:ignore
	$left  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} v ON v.post_id = p.ID AND v.meta_key = '_fxd_v' WHERE p.post_type = 'product' AND p.post_status = 'publish' $stock AND ( v.meta_value IS NULL OR v.meta_value <> %s )", FXD_VER ) ); // phpcs:ignore
	$flags = array();
	foreach ( (array) $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_fxd_info'" ) as $v ) { // phpcs:ignore
		$i = maybe_unserialize( $v );
		$f = is_array( $i ) ? ( $i['flag'] ?? '' ) : '';
		if ( $f ) {
			$flags[ $f ] = ( $flags[ $f ] ?? 0 ) + 1;
		}
	}
	return array( 'all' => $all, 'left' => $left, 'flags' => $flags, 'v18' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_fox_v18_full_done'" ) ); // phpcs:ignore
}

/**
 * Partia: przetwarza produkty z kolejki przez najwyżej $budget sekund.
 *
 * @return array [ log, wait (s), stop ]
 */
function fxd_run( $budget = 45 ) {
	$start = microtime( true );
	$log   = array();
	@set_time_limit( $budget + 120 ); // phpcs:ignore
	foreach ( fxd_queue( 30 ) as $id ) {
		if ( microtime( true ) - $start > $budget ) {
			break;
		}
		$r     = fxd_process( $id );
		$log[] = strtoupper( $r['status'] ) . '  ' . $r['msg'];
		if ( 'skip' !== $r['status'] ) {
			fxd_log( strtoupper( $r['status'] ) . '  #' . $id . ' ' . $r['msg'] );
		}
		if ( 'retry' === $r['status'] ) {
			return array( $log, 60, false );
		}
		if ( 'nokey' === $r['status'] ) {
			return array( $log, 0, true );
		}
	}
	return array( $log, 0, false );
}

/* Automat w tle: co 5 minut partia (gdy włączony), z przerwą w trakcie importu z BaseLinkera. */
add_filter( 'cron_schedules', function ( $s ) {
	$s['fxd_5min'] = array( 'interval' => 300, 'display' => 'Co 5 minut' );
	return $s;
} );
add_action( 'init', function () {
	if ( fxd_opt( 'auto' ) && ! wp_next_scheduled( 'fxd_cron' ) ) {
		wp_schedule_event( time() + 120, 'fxd_5min', 'fxd_cron' );
	} elseif ( ! fxd_opt( 'auto' ) && wp_next_scheduled( 'fxd_cron' ) ) {
		wp_clear_scheduled_hook( 'fxd_cron' );
	}
} );
register_deactivation_hook( FXD_FILE, function () {
	wp_clear_scheduled_hook( 'fxd_cron' );
} );
add_action( 'fxd_cron', function () {
	if ( ! fxd_opt( 'auto' ) || ! fxd_key() || get_transient( 'fxd_lock' ) || get_transient( 'fxd_pause' ) ) {
		return;
	}
	if ( function_exists( 'fxi_import_busy' ) && fxi_import_busy() ) {
		return; // trwa import z BaseLinkera (wykrywany przez wtyczkę opisów zdjęć)
	}
	set_transient( 'fxd_lock', 1, 4 * MINUTE_IN_SECONDS );
	list( , $wait, $stop ) = fxd_run( 50 );
	if ( $wait ) {
		set_transient( 'fxd_pause', 1, $wait * 5 ); // limit API – kilka minut przerwy
	}
	if ( $stop ) {
		fxd_log( 'STOP – sprawdź klucz OpenAI w ustawieniach.' );
	}
	delete_transient( 'fxd_lock' );
} );

/* Produkt zmieniony (np. import z Base nadpisał opis) – automat sprawdzi go ponownie. */
add_action( 'woocommerce_update_product', function ( $id ) {
	if ( get_post_meta( $id, '_fxd_v', true ) ) {
		delete_post_meta( $id, '_fxd_v' );
	}
} );
