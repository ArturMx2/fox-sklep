<?php
/**
 * Plugin Name: FOX – SEO zdjęć produktów
 * Description: Opisy zdjęć produktów przez AI (alt, podpis, opis), bezpieczna naprawa miniatur, audyt zdjęć, zdjęcia z galerii w danych strukturalnych produktu. Zastępuje fragment kodu „FOX SEO + Miniatury”.
 * Version: 1.0.0
 * Author: FOX.SKLEP.PL
 * Requires at least: 5.8
 * Requires PHP: 7.4
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

define( 'FXI_VER', '1.0.0' );

/* =====================================================================
 * Ustawienia
 * ================================================================== */

function fxi_defaults() {
	return array(
		'api_key'        => '',
		'model'          => 'gpt-4o-mini',
		'scope'          => 'FOX',
		'gallery'        => 1,
		'overwrite'      => 0,
		'set_title'      => 0,
		'auto_new'       => 1,
		'schema_gallery' => 1,
		'alt_fallback'   => 1,
	);
}

function fxi_opt( $k ) {
	static $o = null;
	if ( null === $o ) {
		$o = wp_parse_args( (array) get_option( 'fxi_settings', array() ), fxi_defaults() );
	}
	return isset( $o[ $k ] ) ? $o[ $k ] : null;
}

/** Klucz: stała z wp-config.php ma pierwszeństwo przed ustawieniem w panelu. */
function fxi_key() {
	if ( defined( 'FOX_IMG_AI_KEY' ) && FOX_IMG_AI_KEY ) {
		return (string) FOX_IMG_AI_KEY;
	}
	return (string) fxi_opt( 'api_key' );
}

function fxi_cap() {
	return current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
}

add_action( 'admin_init', function () {
	register_setting(
		'fxi_settings',
		'fxi_settings',
		array(
			'type'              => 'array',
			'sanitize_callback' => function ( $in ) {
				$old = wp_parse_args( (array) get_option( 'fxi_settings', array() ), fxi_defaults() );
				$in  = (array) $in;
				$out = array();
				// Puste pole = zostaw zapisany klucz; „usun” = usuń.
				$key            = isset( $in['api_key'] ) ? trim( sanitize_text_field( wp_unslash( $in['api_key'] ) ) ) : '';
				$out['api_key'] = 'usun' === $key ? '' : ( '' === $key ? $old['api_key'] : $key );
				$out['model']   = isset( $in['model'] ) && preg_match( '/^[a-z0-9.\-]+$/i', $in['model'] ) ? $in['model'] : 'gpt-4o-mini';
				$out['scope']   = isset( $in['scope'] ) ? sanitize_text_field( wp_unslash( $in['scope'] ) ) : '';
				foreach ( array( 'gallery', 'overwrite', 'set_title', 'auto_new', 'schema_gallery', 'alt_fallback' ) as $b ) {
					$out[ $b ] = empty( $in[ $b ] ) ? 0 : 1;
				}
				return $out;
			},
		)
	);
} );

/* =====================================================================
 * Zakres: produkty i ich zdjęcia
 * ================================================================== */

function fxi_scope_sql( &$args ) {
	global $wpdb;
	$scope = trim( (string) fxi_opt( 'scope' ) );
	if ( '' === $scope ) {
		return '';
	}
	$args[] = '%' . $wpdb->esc_like( $scope ) . '%';
	return ' AND post_title LIKE %s';
}

function fxi_count_products() {
	global $wpdb;
	$args = array();
	$w    = fxi_scope_sql( $args );
	$sql  = "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish'" . $w;
	return (int) $wpdb->get_var( $args ? $wpdb->prepare( $sql, $args ) : $sql ); // phpcs:ignore
}

/** Kolejne produkty od kursora (ID malejąco) – odporne na dodawanie nowych produktów w trakcie. */
function fxi_products_after( $cursor, $limit ) {
	global $wpdb;
	$args = array();
	$w    = fxi_scope_sql( $args );
	$sql  = "SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish'" . $w;
	if ( $cursor > 0 ) {
		$sql   .= ' AND ID < %d';
		$args[] = (int) $cursor;
	}
	$sql   .= ' ORDER BY ID DESC LIMIT %d';
	$args[] = (int) $limit;
	return $wpdb->get_results( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore
}

/** Zdjęcia produktu: [ id_załącznika => numer (0 = główne) ]. */
function fxi_product_images( $pid ) {
	$out  = array();
	$main = (int) get_post_thumbnail_id( $pid );
	if ( $main ) {
		$out[ $main ] = 0;
	}
	if ( fxi_opt( 'gallery' ) ) {
		$n = 1;
		foreach ( array_filter( array_map( 'intval', explode( ',', (string) get_post_meta( $pid, '_product_image_gallery', true ) ) ) ) as $g ) {
			if ( ! isset( $out[ $g ] ) && 'attachment' === get_post_type( $g ) ) {
				$out[ $g ] = $n++;
			}
		}
	}
	return $out;
}

/* =====================================================================
 * Naprawa miniatur – tryb bezpieczny
 * ================================================================== */

/**
 * Dorabia brakujące rozmiary jednego zdjęcia.
 * Zasady: nie kasuje plików, nie zmienia nazw, nie generuje od nowa tego, co istnieje.
 * Metadane: kopia przed zmianą, przywrócenie, gdy wynik jest gorszy albo wystąpił błąd.
 *
 * @return array [ 'status' => ok|fixed|err, 'msg' => string ]
 */
function fxi_repair_image( $id ) {
	require_once ABSPATH . 'wp-admin/includes/image.php';
	wp_raise_memory_limit( 'image' );
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 120 ); // phpcs:ignore
	}

	$file = get_attached_file( $id );
	if ( ! $file || ! file_exists( $file ) ) {
		// Brak wersji „-scaled” – ale jest oryginał? Wtedy WP odtworzy z oryginału.
		$orig = function_exists( 'wp_get_original_image_path' ) ? wp_get_original_image_path( $id ) : '';
		if ( ! $orig || ! file_exists( $orig ) ) {
			return array( 'status' => 'err', 'msg' => 'brak pliku na serwerze (nic nie zmieniono)' );
		}
		$file = $orig;
	}
	if ( ! wp_attachment_is_image( $id ) ) {
		return array( 'status' => 'ok', 'msg' => 'to nie jest obraz' );
	}

	$meta = wp_get_attachment_metadata( $id );
	$dir  = trailingslashit( dirname( $file ) );

	// Metadane puste/uszkodzone – jedyny przypadek pełnego generowania.
	if ( ! is_array( $meta ) || empty( $meta['width'] ) || empty( $meta['file'] ) ) {
		$new = wp_generate_attachment_metadata( $id, $file );
		if ( is_array( $new ) && ! empty( $new['width'] ) ) {
			if ( is_array( $meta ) && $meta ) {
				update_post_meta( $id, '_fxi_meta_backup', $meta );
			}
			wp_update_attachment_metadata( $id, $new );
			return array( 'status' => 'fixed', 'msg' => 'odtworzono metadane, rozmiary: ' . ( isset( $new['sizes'] ) ? count( $new['sizes'] ) : 0 ) );
		}
		return array( 'status' => 'err', 'msg' => 'nie udało się odczytać obrazu (nic nie zmieniono)' );
	}

	// Rozmiary zapisane w metadanych, których pliku brak – usuwamy z listy, żeby WP je dorobił.
	$backup  = $meta;
	$missing = array();
	foreach ( (array) ( isset( $meta['sizes'] ) ? $meta['sizes'] : array() ) as $name => $s ) {
		if ( empty( $s['file'] ) || ! file_exists( $dir . $s['file'] ) ) {
			$missing[] = $name;
			unset( $meta['sizes'][ $name ] );
		}
	}
	$todo = function_exists( 'wp_get_missing_image_subsizes' ) ? array_keys( (array) wp_get_missing_image_subsizes( $id ) ) : array();
	$todo = array_unique( array_merge( $todo, $missing ) );
	if ( ! $todo ) {
		return array( 'status' => 'ok', 'msg' => 'komplet rozmiarów (' . count( (array) $meta['sizes'] ) . ')' );
	}

	update_post_meta( $id, '_fxi_meta_backup', $backup );
	if ( $missing ) {
		wp_update_attachment_metadata( $id, $meta );
	}
	$res = function_exists( 'wp_update_image_subsizes' ) ? wp_update_image_subsizes( $id ) : new WP_Error( 'fxi', 'WordPress < 5.3' );
	if ( is_wp_error( $res ) ) {
		wp_update_attachment_metadata( $id, $backup ); // nie zostawiamy gorszego stanu
		return array( 'status' => 'err', 'msg' => 'błąd generowania: ' . $res->get_error_message() . ' – przywrócono poprzednie metadane' );
	}
	$after = wp_get_attachment_metadata( $id );
	$ok    = 0;
	foreach ( (array) ( isset( $after['sizes'] ) ? $after['sizes'] : array() ) as $s ) {
		if ( ! empty( $s['file'] ) && file_exists( $dir . $s['file'] ) ) {
			$ok++;
		}
	}
	return array( 'status' => 'fixed', 'msg' => 'dorobiono: ' . implode( ', ', $todo ) . ' | rozmiary OK: ' . $ok );
}

/* =====================================================================
 * Opisy zdjęć przez AI
 * ================================================================== */

/** Plik obrazu do wysłania (średni rozmiar – taniej i szybciej), jako data URL. */
function fxi_image_data_url( $id ) {
	$path = '';
	foreach ( array( 'woocommerce_single', 'large', 'medium_large' ) as $size ) {
		$i = image_get_intermediate_size( $id, $size );
		if ( $i && ! empty( $i['path'] ) ) {
			$up = wp_upload_dir();
			$p  = trailingslashit( $up['basedir'] ) . $i['path'];
			if ( file_exists( $p ) ) {
				$path = $p;
				break;
			}
		}
	}
	if ( ! $path ) {
		$path = get_attached_file( $id );
	}
	if ( ! $path || ! file_exists( $path ) || filesize( $path ) > 8 * MB_IN_BYTES ) {
		return '';
	}
	$type = wp_check_filetype( $path );
	$mime = $type['type'] ? $type['type'] : 'image/jpeg';
	return 'data:' . $mime . ';base64,' . base64_encode( file_get_contents( $path ) ); // phpcs:ignore
}

/** Dane produktu dla AI: nazwa, kategoria, kolor, model, SKU. */
function fxi_product_context( $pid ) {
	$p   = wc_get_product( $pid );
	$ctx = array( 'nazwa' => get_the_title( $pid ) );
	if ( ! $p ) {
		return $ctx;
	}
	$ctx['sku'] = $p->get_sku();
	$cats       = wp_get_post_terms( $pid, 'product_cat', array( 'fields' => 'names' ) );
	if ( $cats && ! is_wp_error( $cats ) ) {
		$ctx['kategorie'] = implode( ', ', array_slice( $cats, 0, 4 ) );
	}
	foreach ( $p->get_attributes() as $tax => $a ) {
		if ( preg_match( '/kolor|color|model/i', $tax ) ) {
			$ctx[ wc_attribute_label( $tax ) ] = $p->get_attribute( $tax );
		}
	}
	return array_filter( $ctx );
}

/**
 * Jedno zapytanie do AI dla jednego zdjęcia.
 *
 * @return array|WP_Error [ alt, title, caption, desc ]; kod błędu 'retry' = limit, spróbuj później.
 */
function fxi_ai_describe( $id, $pid, $index, $count ) {
	$key = fxi_key();
	if ( ! $key ) {
		return new WP_Error( 'nokey', 'brak klucza API (Ustawienia)' );
	}
	$img = fxi_image_data_url( $id );
	if ( ! $img ) {
		return new WP_Error( 'nofile', 'brak pliku zdjęcia' );
	}
	$ctx  = fxi_product_context( $pid );
	$info = '';
	foreach ( $ctx as $k => $v ) {
		$info .= '- ' . $k . ': ' . $v . "\n";
	}
	$which = 0 === $index ? 'zdjęcie główne' : 'zdjęcie ' . ( $index + 1 ) . ' z ' . $count . ' w galerii';

	$system = 'Jesteś specjalistą SEO sklepu internetowego z odzieżą i sprzętem motocyklowym oraz rowerowym marki FOX Racing (fox.sklep.pl). '
		. 'Piszesz po polsku teksty do zdjęć produktów pod Google Grafika i dostępność (czytniki ekranu). '
		. 'Opisujesz WYŁĄCZNIE to, co widać na zdjęciu, łącząc to z danymi produktu. Bez zmyślania cech, których nie widać i których nie ma w danych. '
		. 'Bez fraz „zdjęcie”, „obraz”, „grafika przedstawia”. Bez upychania słów kluczowych i bez wielkich liter w całych słowach (poza skrótami jak MX, MTB).';
	$user = "Dane produktu:\n" . $info . 'To jest ' . $which . ".\n\n"
		. "Zwróć tylko JSON:\n"
		. "{\"alt\":\"...\",\"title\":\"...\",\"caption\":\"...\",\"desc\":\"...\"}\n"
		. "- alt: 70–125 znaków; rodzaj produktu + FOX + model/nazwa + kolor + co widać (np. widok z przodu/tyłu, detal, na modelu). Naturalne zdanie, bez kropki na końcu.\n"
		. "- title: do 60 znaków, nazwa produktu + ujęcie (np. „– tył”).\n"
		. "- caption: do 100 znaków, krótki podpis dla klienta.\n"
		. "- desc: 1–2 zdania (do 250 znaków) o tym, co widać: krój, wzór, detale, materiał – tylko widoczne.\n";

	$res = wp_remote_post(
		'https://api.openai.com/v1/chat/completions',
		array(
			'timeout' => 45,
			'headers' => array(
				'Authorization' => 'Bearer ' . $key,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode(
				array(
					'model'           => (string) fxi_opt( 'model' ),
					'temperature'     => 0.3,
					'max_tokens'      => 400,
					'response_format' => array( 'type' => 'json_object' ),
					'messages'        => array(
						array( 'role' => 'system', 'content' => $system ),
						array(
							'role'    => 'user',
							'content' => array(
								array( 'type' => 'text', 'text' => $user ),
								array( 'type' => 'image_url', 'image_url' => array( 'url' => $img, 'detail' => 'low' ) ),
							),
						),
					),
				)
			),
		)
	);
	if ( is_wp_error( $res ) ) {
		return new WP_Error( 'retry', 'połączenie: ' . $res->get_error_message() );
	}
	$code = (int) wp_remote_retrieve_response_code( $res );
	$body = json_decode( wp_remote_retrieve_body( $res ), true );
	if ( 429 === $code || $code >= 500 ) {
		return new WP_Error( 'retry', 'limit/awaria API (' . $code . ')' );
	}
	if ( 200 !== $code ) {
		$m = isset( $body['error']['message'] ) ? $body['error']['message'] : 'HTTP ' . $code;
		return new WP_Error( 401 === $code ? 'nokey' : 'api', 'API: ' . wp_strip_all_tags( $m ) );
	}
	$txt = isset( $body['choices'][0]['message']['content'] ) ? $body['choices'][0]['message']['content'] : '';
	$j   = json_decode( trim( $txt ), true );
	if ( ! is_array( $j ) || empty( $j['alt'] ) ) {
		return new WP_Error( 'json', 'niepoprawna odpowiedź AI' );
	}
	$clean = function ( $s, $max ) {
		$s = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $s ) ), " \t\n\r\"'„”" );
		return mb_strlen( $s ) > $max ? rtrim( mb_substr( $s, 0, $max - 1 ), ' ,;–-' ) . '…' : $s;
	};
	$alt = rtrim( $clean( $j['alt'], 125 ), '.' );
	// Alt musi nieść markę – gdy AI ją pominęła, dopisujemy nazwę produktu.
	if ( false === mb_stripos( $alt, 'fox' ) ) {
		$alt = $clean( get_the_title( $pid ) . ' – ' . $alt, 125 );
	}
	return array(
		'alt'     => $alt,
		'title'   => $clean( isset( $j['title'] ) ? $j['title'] : '', 70 ),
		'caption' => $clean( isset( $j['caption'] ) ? $j['caption'] : '', 120 ),
		'desc'    => $clean( isset( $j['desc'] ) ? $j['desc'] : '', 300 ),
	);
}

/** Czy tytuł załącznika wygląda jak nazwa pliku (np. „IMG_1234”, „30451-001-xl”). */
function fxi_title_is_filename( $t ) {
	return '' === trim( $t ) || ! preg_match( '/\s/u', trim( $t ) ) || preg_match( '/^(img|dsc|photo|image)[\s_-]*\d+/i', $t );
}

/**
 * Opisuje jedno zdjęcie i zapisuje pola. Nie nadpisuje ręcznie wpisanego alt (chyba że w ustawieniach).
 *
 * @return array|WP_Error
 */
function fxi_seo_image( $id, $pid, $index, $count ) {
	if ( get_post_meta( $id, '_fxi_ai', true ) ) {
		return array( 'skip' => 'już opisane' );
	}
	$cur_alt = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
	if ( '' !== trim( $cur_alt ) && ! fxi_opt( 'overwrite' ) && ! fxi_alt_is_weak( $cur_alt, $id, $pid ) ) {
		update_post_meta( $id, '_fxi_ai', 'manual' );
		return array( 'skip' => 'ma ręczny alt' );
	}
	$d = fxi_ai_describe( $id, $pid, $index, $count );
	if ( is_wp_error( $d ) ) {
		return $d;
	}
	update_post_meta( $id, '_wp_attachment_image_alt', $d['alt'] );
	$post = get_post( $id );
	$upd  = array( 'ID' => $id );
	if ( $d['caption'] && '' === trim( $post->post_excerpt ) ) {
		$upd['post_excerpt'] = $d['caption'];
	}
	if ( $d['desc'] && '' === trim( $post->post_content ) ) {
		$upd['post_content'] = $d['desc'];
	}
	// Tytuł tylko na życzenie i tylko gdy obecny wygląda jak nazwa pliku
	// (niektóre wtyczki zmieniają nazwę PLIKU po zmianie tytułu – to psuje miniatury).
	if ( fxi_opt( 'set_title' ) && $d['title'] && fxi_title_is_filename( $post->post_title ) ) {
		$upd['post_title'] = $d['title'];
	}
	if ( count( $upd ) > 1 ) {
		wp_update_post( $upd );
	}
	update_post_meta( $id, '_fxi_ai', time() );
	return $d;
}

/** Alt „słaby” = pusty, nazwa pliku, sama nazwa produktu bez opisu albo bardzo krótki. */
function fxi_alt_is_weak( $alt, $id, $pid = 0 ) {
	$alt  = trim( $alt );
	$file = pathinfo( (string) get_attached_file( $id ), PATHINFO_FILENAME );
	$norm = function ( $s ) {
		return mb_strtolower( trim( preg_replace( '/[\s\-–—|]+/u', ' ', $s ) ) );
	};
	// Alt = sama nazwa produktu (typowe po imporcie) też jest do poprawy – nic nie mówi o ujęciu.
	$same = $pid && $norm( $alt ) === $norm( get_the_title( $pid ) );
	return $same || mb_strlen( $alt ) < 15 || 0 === strcasecmp( $alt, $file ) || ! preg_match( '/\s/u', $alt );
}

/* =====================================================================
 * Partie (AJAX)
 * ================================================================== */

add_action( 'wp_ajax_fxi_batch', function () {
	check_ajax_referer( 'fxi', 'nonce' );
	if ( ! fxi_cap() ) {
		wp_send_json_error( array( 'msg' => 'Brak uprawnień' ), 403 );
	}
	$mode = isset( $_POST['mode'] ) && 'seo' === $_POST['mode'] ? 'seo' : 'repair';
	if ( get_transient( 'fxi_lock' ) ) {
		wp_send_json_success( array( 'wait' => 5, 'log' => array( 'Inna partia jeszcze trwa – czekam…' ) ) );
	}
	set_transient( 'fxi_lock', 1, 90 );

	$state  = get_option( 'fxi_state_' . $mode, array( 'cursor' => 0, 'done' => 0, 'stats' => array() ) );
	$start  = microtime( true );
	$budget = 20; // sekund na partię – bezpiecznie poniżej limitów serwera
	$log    = array();
	$wait   = 0;
	$stop   = false;
	$stats  = wp_parse_args( (array) $state['stats'], array( 'ok' => 0, 'fixed' => 0, 'err' => 0, 'skip' => 0 ) );

	foreach ( fxi_products_after( (int) $state['cursor'], 'seo' === $mode ? 5 : 10 ) as $row ) {
		$imgs     = fxi_product_images( $row->ID );
		$finished = true;
		if ( ! $imgs ) {
			$log[] = 'Pominięto: ' . $row->post_title . ' (brak zdjęć)';
		}
		foreach ( $imgs as $id => $idx ) {
			if ( microtime( true ) - $start > $budget ) {
				$finished = false;
				break;
			}
			if ( 'repair' === $mode ) {
				$r = fxi_repair_image( $id );
				$stats[ $r['status'] ]++;
				if ( 'ok' !== $r['status'] ) {
					$log[] = ( 'err' === $r['status'] ? 'ERR ' : 'OK ' ) . $row->post_title . ' #' . $id . ': ' . $r['msg'];
				}
				continue;
			}
			$r = fxi_seo_image( $id, $row->ID, $idx, count( $imgs ) );
			if ( is_wp_error( $r ) ) {
				$log[] = 'ERR ' . $row->post_title . ' #' . $id . ': ' . $r->get_error_message();
				if ( in_array( $r->get_error_code(), array( 'retry', 'nokey' ), true ) ) {
					$finished = false;
					$stop     = 'nokey' === $r->get_error_code();
					$wait     = $stop ? 0 : 30;
					break;
				}
				$stats['err']++;
			} elseif ( isset( $r['skip'] ) ) {
				$stats['skip']++;
			} else {
				$stats['fixed']++;
				$log[] = 'OK ' . $row->post_title . ' #' . $id . ': ' . $r['alt'];
			}
		}
		if ( ! $finished ) {
			break; // ten produkt dokończymy w następnej partii (opisane zdjęcia są pomijane)
		}
		if ( 'repair' === $mode && function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $row->ID );
		}
		$state['cursor'] = (int) $row->ID;
		$state['done']++;
		if ( microtime( true ) - $start > $budget ) {
			break;
		}
	}
	$state['stats'] = $stats;
	$total          = fxi_count_products();
	$finished_all   = ! $wait && ! $stop && ! fxi_products_after( (int) $state['cursor'], 1 );
	update_option( 'fxi_state_' . $mode, $state, false );
	delete_transient( 'fxi_lock' );
	wp_send_json_success(
		array(
			'done'  => $finished_all,
			'stop'  => $stop,
			'wait'  => $wait,
			'count' => min( $state['done'], $total ),
			'total' => $total,
			'stats' => $stats,
			'log'   => $log,
		)
	);
} );

add_action( 'wp_ajax_fxi_reset', function () {
	check_ajax_referer( 'fxi', 'nonce' );
	if ( ! fxi_cap() ) {
		wp_send_json_error( null, 403 );
	}
	$mode = isset( $_POST['mode'] ) && 'seo' === $_POST['mode'] ? 'seo' : 'repair';
	delete_option( 'fxi_state_' . $mode );
	delete_transient( 'fxi_lock' );
	wp_send_json_success();
} );

/** Audyt: braki alt, słabe alt, małe zdjęcia, brakujące pliki miniatur. */
add_action( 'wp_ajax_fxi_audit', function () {
	check_ajax_referer( 'fxi', 'nonce' );
	if ( ! fxi_cap() ) {
		wp_send_json_error( null, 403 );
	}
	@set_time_limit( 120 ); // phpcs:ignore
	$a     = array( 'products' => 0, 'images' => 0, 'no_alt' => 0, 'weak_alt' => 0, 'ai' => 0, 'small' => 0, 'missing_files' => 0, 'no_image' => 0 );
	$small = array();
	$up    = wp_upload_dir();
	$cur   = 0;
	do {
		$rows = fxi_products_after( $cur, 300 );
		foreach ( $rows as $row ) {
			$cur = (int) $row->ID;
			$a['products']++;
			$imgs = fxi_product_images( $row->ID );
			if ( ! $imgs ) {
				$a['no_image']++;
			}
			foreach ( $imgs as $id => $idx ) {
				$a['images']++;
				$alt = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
				if ( '' === trim( $alt ) ) {
					$a['no_alt']++;
				} elseif ( fxi_alt_is_weak( $alt, $id, $row->ID ) ) {
					$a['weak_alt']++;
				}
				if ( get_post_meta( $id, '_fxi_ai', true ) ) {
					$a['ai']++;
				}
				$m = wp_get_attachment_metadata( $id );
				if ( 0 === $idx && is_array( $m ) && ! empty( $m['width'] ) && (int) $m['width'] < 1000 ) {
					$a['small']++;
					if ( count( $small ) < 40 ) {
						$small[] = array( 'name' => $row->post_title, 'url' => get_edit_post_link( $row->ID, 'raw' ), 'w' => (int) $m['width'] . '×' . (int) $m['height'] );
					}
				}
				if ( is_array( $m ) && ! empty( $m['file'] ) ) {
					$dir = trailingslashit( $up['basedir'] ) . trailingslashit( dirname( $m['file'] ) );
					foreach ( (array) ( isset( $m['sizes'] ) ? $m['sizes'] : array() ) as $s ) {
						if ( ! empty( $s['file'] ) && ! file_exists( $dir . $s['file'] ) ) {
							$a['missing_files']++;
							break;
						}
					}
				}
			}
		}
	} while ( count( $rows ) === 300 );
	wp_send_json_success( array( 'a' => $a, 'small' => $small ) );
} );

/* =====================================================================
 * Automatycznie dla nowych produktów (WP-Cron, 2 min po zapisaniu)
 * ================================================================== */

function fxi_queue_product( $pid ) {
	if ( ! fxi_opt( 'auto_new' ) || ! fxi_key() || 'publish' !== get_post_status( $pid ) ) {
		return;
	}
	$scope = trim( (string) fxi_opt( 'scope' ) );
	if ( $scope && false === mb_stripos( get_the_title( $pid ), $scope ) ) {
		return;
	}
	foreach ( array_keys( fxi_product_images( $pid ) ) as $id ) {
		if ( ! get_post_meta( $id, '_fxi_ai', true ) ) {
			if ( ! wp_next_scheduled( 'fxi_auto_product', array( (int) $pid ) ) ) {
				wp_schedule_single_event( time() + 120, 'fxi_auto_product', array( (int) $pid ) );
			}
			return;
		}
	}
}
add_action( 'woocommerce_new_product', 'fxi_queue_product', 50 );
add_action( 'woocommerce_update_product', 'fxi_queue_product', 50 );

add_action( 'fxi_auto_product', function ( $pid ) {
	if ( get_transient( 'fxi_lock' ) ) {
		wp_schedule_single_event( time() + 300, 'fxi_auto_product', array( (int) $pid ) );
		return;
	}
	$imgs = fxi_product_images( $pid );
	foreach ( $imgs as $id => $idx ) {
		$r = fxi_seo_image( $id, $pid, $idx, count( $imgs ) );
		if ( is_wp_error( $r ) && 'retry' === $r->get_error_code() ) {
			wp_schedule_single_event( time() + 900, 'fxi_auto_product', array( (int) $pid ) );
			return;
		}
	}
} );

/* =====================================================================
 * Front: alt awaryjny i zdjęcia w danych strukturalnych
 * ================================================================== */

/** Zdjęcie produktu bez alt → nazwa produktu (+ numer ujęcia w galerii). */
add_filter( 'wp_get_attachment_image_attributes', function ( $attr, $att ) {
	if ( is_admin() || ! fxi_opt( 'alt_fallback' ) || ( isset( $attr['alt'] ) && '' !== trim( $attr['alt'] ) ) ) {
		return $attr;
	}
	global $product;
	$pid = ( $product instanceof WC_Product ) ? $product->get_id() : 0;
	if ( ! $pid && $att->post_parent && 'product' === get_post_type( $att->post_parent ) ) {
		$pid = (int) $att->post_parent;
	}
	if ( $pid ) {
		$imgs = fxi_product_images( $pid );
		$n    = isset( $imgs[ $att->ID ] ) ? (int) $imgs[ $att->ID ] : 0;
		$attr['alt'] = get_the_title( $pid ) . ( $n ? ' – zdjęcie ' . ( $n + 1 ) : '' );
	}
	return $attr;
}, 20, 2 );

/** Product schema: wszystkie zdjęcia (główne + galeria) w pełnej rozdzielczości. */
add_filter( 'woocommerce_structured_data_product', function ( $data, $product ) {
	if ( ! fxi_opt( 'schema_gallery' ) || ! $product instanceof WC_Product ) {
		return $data;
	}
	$ids  = array_filter( array_merge( array( (int) $product->get_image_id() ), array_map( 'intval', $product->get_gallery_image_ids() ) ) );
	$urls = array();
	foreach ( array_unique( $ids ) as $id ) {
		$u = wp_get_attachment_image_url( $id, 'full' );
		if ( $u ) {
			$urls[] = $u;
		}
	}
	if ( count( $urls ) > 1 ) {
		$data['image'] = array_slice( $urls, 0, 10 );
	}
	return $data;
}, 20, 2 );

/* =====================================================================
 * Panel
 * ================================================================== */

add_action( 'admin_menu', function () {
	add_menu_page( 'FOX – SEO zdjęć', 'FOX SEO zdjęć', 'manage_woocommerce', 'fox-seo-zdjec', 'fxi_page', 'dashicons-format-image', 56 );
} );

function fxi_page() {
	if ( ! fxi_cap() ) {
		return;
	}
	$tab   = isset( $_GET['tab'] ) && 'ustawienia' === $_GET['tab'] ? 'ustawienia' : 'narzedzia'; // phpcs:ignore
	$base  = admin_url( 'admin.php?page=fox-seo-zdjec' );
	$total = fxi_count_products();
	echo '<div class="wrap"><h1>FOX – SEO zdjęć produktów</h1><nav class="nav-tab-wrapper">';
	echo '<a class="nav-tab' . ( 'narzedzia' === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( $base ) . '">Narzędzia</a>';
	echo '<a class="nav-tab' . ( 'ustawienia' === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( $base . '&tab=ustawienia' ) . '">Ustawienia</a></nav>';

	if ( 'ustawienia' === $tab ) {
		fxi_settings_page();
		echo '</div>';
		return;
	}
	if ( ! fxi_key() ) {
		echo '<div class="notice notice-warning"><p>Brak klucza API – wpisz go w <a href="' . esc_url( $base . '&tab=ustawienia' ) . '">Ustawieniach</a>. Naprawa miniatur i audyt działają bez klucza.</p></div>';
	}
	$sr = get_option( 'fxi_state_repair', array( 'done' => 0 ) );
	$ss = get_option( 'fxi_state_seo', array( 'done' => 0 ) );
	?>
	<p>Zakres: produkty opublikowane<?php echo fxi_opt( 'scope' ) ? ' z „' . esc_html( fxi_opt( 'scope' ) ) . '” w nazwie' : ''; ?> – <strong><?php echo (int) $total; ?></strong>; zdjęcie główne<?php echo fxi_opt( 'gallery' ) ? ' i galeria' : ''; ?>.</p>
	<div class="fxi-cards">
		<div class="fxi-card">
			<h2>1. Audyt</h2>
			<p>Ile zdjęć nie ma alt, ma słaby alt (nazwa pliku, jedno słowo), jest za małych (główne &lt; 1000 px) i ile ma brakujące pliki miniatur. Nic nie zmienia.</p>
			<button class="button" data-fxi-audit>Uruchom audyt</button>
			<div id="fxi-audit"></div>
		</div>
		<div class="fxi-card">
			<h2>2. Naprawa miniatur</h2>
			<p>Dorabia tylko <strong>brakujące</strong> rozmiary. Nie kasuje plików, nie zmienia nazw, nie generuje od nowa istniejących. Metadane z kopią – przy błędzie wracają do poprzedniego stanu.</p>
			<p><strong>Postęp:</strong> <span data-fxi-count="repair"><?php echo (int) min( $sr['done'], $total ); ?></span> / <?php echo (int) $total; ?></p>
			<button class="button button-primary" data-fxi-run="repair">Start / wznów</button>
			<button class="button-link" data-fxi-reset="repair">od początku</button>
		</div>
		<div class="fxi-card">
			<h2>3. Opisy zdjęć (AI)</h2>
			<p>Alt, podpis i opis po polsku na podstawie zdjęcia i danych produktu. Ręcznie wpisanych, sensownych altów nie rusza<?php echo fxi_opt( 'overwrite' ) ? ' <em>(włączone nadpisywanie!)</em>' : ''; ?>. Opisane zdjęcia są pomijane przy kolejnych uruchomieniach.</p>
			<p><strong>Postęp:</strong> <span data-fxi-count="seo"><?php echo (int) min( $ss['done'], $total ); ?></span> / <?php echo (int) $total; ?></p>
			<button class="button button-primary" data-fxi-run="seo" <?php disabled( ! fxi_key() ); ?>>Start / wznów</button>
			<button class="button-link" data-fxi-reset="seo">od początku</button>
		</div>
	</div>
	<p><button class="button" data-fxi-stop>Stop</button> <span id="fxi-status">Gotowe do pracy.</span></p>
	<progress id="fxi-progress" value="0" max="<?php echo (int) max( 1, $total ); ?>" style="width:100%;height:20px"></progress>
	<div id="fxi-log"></div>
	<style>
		.fxi-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px;margin:16px 0}
		.fxi-card{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:4px 16px 16px}
		#fxi-log{background:#111;color:#ddd;padding:12px;height:380px;overflow:auto;font:12px/1.5 monospace;border-left:4px solid #f26522;margin-top:10px}
		#fxi-log .e{color:#ff7070}#fxi-log .o{color:#7dffa6}#fxi-log .i{color:#7cc8ff}
		#fxi-audit table{margin-top:10px}#fxi-audit td{padding:2px 10px 2px 0}
	</style>
	<script>
	(function () {
		var nonce = <?php echo wp_json_encode( wp_create_nonce( 'fxi' ) ); ?>, run = null, timer = null;
		var logEl = document.getElementById('fxi-log'), st = document.getElementById('fxi-status'), pr = document.getElementById('fxi-progress');
		function log(t, c) { var d = document.createElement('div'); d.textContent = t; if (c) { d.className = c; } logEl.insertBefore(d, logEl.firstChild); }
		function post(data) {
			var f = new FormData(); f.append('nonce', nonce);
			Object.keys(data).forEach(function (k) { f.append(k, data[k]); });
			return fetch(ajaxurl, { method: 'POST', body: f, credentials: 'same-origin' }).then(function (r) { return r.json(); });
		}
		function step() {
			if (!run) { return; }
			st.textContent = 'Pracuję: ' + (run === 'seo' ? 'opisy AI' : 'miniatury') + '…';
			post({ action: 'fxi_batch', mode: run }).then(function (r) {
				if (!r || !r.success) { log('Błąd odpowiedzi serwera – zatrzymano.', 'e'); stop(); return; }
				var d = r.data;
				(d.log || []).forEach(function (l) { log(l, l.indexOf('ERR') === 0 ? 'e' : (l.indexOf('OK') === 0 ? 'o' : '')); });
				if (d.total) { pr.max = d.total; pr.value = d.count; document.querySelector('[data-fxi-count="' + run + '"]').textContent = d.count; }
				if (d.stats) { st.textContent = 'zrobione: ' + d.stats.fixed + ' · bez zmian/pominięte: ' + (d.stats.ok + d.stats.skip) + ' · błędy: ' + d.stats.err; }
				if (d.stop) { log('Zatrzymano – sprawdź klucz API w Ustawieniach.', 'e'); stop(); return; }
				if (d.done) { log('ZAKOŃCZONO.', 'i'); stop(true); return; }
				timer = setTimeout(step, d.wait ? d.wait * 1000 : 300);
				if (d.wait) { log('Czekam ' + d.wait + ' s (limit API / inna partia)…', 'i'); }
			}).catch(function () { log('Błąd połączenia – ponawiam za 10 s.', 'e'); timer = setTimeout(step, 10000); });
		}
		function stop(done) { run = null; clearTimeout(timer); if (!done) { st.textContent = 'Zatrzymano.'; } }
		document.addEventListener('click', function (e) {
			var b = e.target.closest('[data-fxi-run],[data-fxi-stop],[data-fxi-reset],[data-fxi-audit]');
			if (!b) { return; }
			e.preventDefault();
			if (b.hasAttribute('data-fxi-stop')) { stop(); log('STOP', 'i'); return; }
			if (b.hasAttribute('data-fxi-reset')) {
				if (!confirm('Zacząć ten proces od początku? (Opisane już zdjęcia i tak zostaną pominięte.)')) { return; }
				post({ action: 'fxi_reset', mode: b.getAttribute('data-fxi-reset') }).then(function () { document.querySelector('[data-fxi-count="' + b.getAttribute('data-fxi-reset') + '"]').textContent = '0'; log('Postęp wyzerowany.', 'i'); });
				return;
			}
			if (b.hasAttribute('data-fxi-audit')) {
				b.disabled = true; b.textContent = 'Liczę…';
				post({ action: 'fxi_audit' }).then(function (r) {
					b.disabled = false; b.textContent = 'Uruchom audyt';
					if (!r || !r.success) { return; }
					var a = r.data.a, box = document.getElementById('fxi-audit'), rows = [
						['Produkty', a.products], ['Produkty bez zdjęcia', a.no_image], ['Zdjęcia', a.images], ['Bez alt', a.no_alt],
						['Słaby alt', a.weak_alt], ['Opisane przez AI / zostawione ręczne', a.ai], ['Główne zdjęcie < 1000 px', a.small], ['Brakujące pliki miniatur', a.missing_files]
					];
					var t = document.createElement('table');
					rows.forEach(function (x) { var tr = t.insertRow(); tr.insertCell().textContent = x[0]; var c = tr.insertCell(); c.innerHTML = '<strong></strong>'; c.firstChild.textContent = x[1]; });
					box.innerHTML = ''; box.appendChild(t);
					if (r.data.small.length) {
						var p = document.createElement('details'), s = document.createElement('summary'); s.textContent = 'Małe zdjęcia główne (pierwsze ' + r.data.small.length + ')'; p.appendChild(s);
						r.data.small.forEach(function (x) { var d = document.createElement('div'), l = document.createElement('a'); l.href = x.url; l.textContent = x.name; d.appendChild(l); d.appendChild(document.createTextNode(' – ' + x.w)); p.appendChild(d); });
						box.appendChild(p);
					}
				});
				return;
			}
			if (run) { return; }
			run = b.getAttribute('data-fxi-run');
			log('START: ' + (run === 'seo' ? 'opisy zdjęć AI' : 'naprawa miniatur'), 'i');
			step();
		});
	})();
	</script>
	<?php
	echo '</div>';
}

function fxi_settings_page() {
	$key   = fxi_key();
	$const = defined( 'FOX_IMG_AI_KEY' ) && FOX_IMG_AI_KEY;
	echo '<form method="post" action="options.php">';
	settings_fields( 'fxi_settings' );
	$f = function ( $k ) {
		return 'fxi_settings[' . $k . ']';
	};
	?>
	<table class="form-table" role="presentation">
		<tr><th>Klucz API OpenAI</th><td>
			<?php if ( $const ) : ?>
				<p>Ustawiony w wp-config.php (<code>FOX_IMG_AI_KEY</code>) – …<?php echo esc_html( substr( $key, -4 ) ); ?></p>
			<?php else : ?>
				<input type="password" class="regular-text" name="<?php echo esc_attr( $f( 'api_key' ) ); ?>" value="" autocomplete="new-password" placeholder="<?php echo $key ? esc_attr( 'zapisany …' . substr( $key, -4 ) . ' – zostaw puste, żeby nie zmieniać' ) : 'sk-…'; ?>">
				<p class="description">Klucz nie jest pokazywany ani wysyłany nigdzie poza api.openai.com. Wpisz <code>usun</code>, żeby go skasować. Bezpieczniej: w wp-config.php <code>define( 'FOX_IMG_AI_KEY', 'sk-…' );</code></p>
			<?php endif; ?>
		</td></tr>
		<tr><th>Model</th><td>
			<select name="<?php echo esc_attr( $f( 'model' ) ); ?>">
				<?php foreach ( array( 'gpt-4o-mini' => 'gpt-4o-mini – tani, wystarcza do altów (zalecany)', 'gpt-4o' => 'gpt-4o – dokładniejszy, ok. 15× droższy', 'gpt-4.1-mini' => 'gpt-4.1-mini', 'gpt-4.1' => 'gpt-4.1' ) as $v => $l ) : ?>
					<option value="<?php echo esc_attr( $v ); ?>" <?php selected( fxi_opt( 'model' ), $v ); ?>><?php echo esc_html( $l ); ?></option>
				<?php endforeach; ?>
			</select>
		</td></tr>
		<tr><th>Zakres</th><td><input type="text" name="<?php echo esc_attr( $f( 'scope' ) ); ?>" value="<?php echo esc_attr( fxi_opt( 'scope' ) ); ?>"> <span class="description">tylko produkty z tym tekstem w nazwie (puste = wszystkie)</span></td></tr>
		<?php
		$checks = array(
			'gallery'        => array( 'Zdjęcia z galerii', 'Opisuj i naprawiaj także zdjęcia z galerii, nie tylko główne (zalecane – każde zdjęcie to osobna szansa w Google Grafika).' ),
			'auto_new'       => array( 'Automatycznie dla nowych', 'Nowy/zmieniony produkt z nieopisanymi zdjęciami – opis w tle po 2 minutach.' ),
			'overwrite'      => array( 'Nadpisuj ręczne alty', 'Domyślnie wyłączone: sensowny alt wpisany ręcznie zostaje. Słabe alty (nazwa pliku, jedno słowo, sama nazwa produktu) są poprawiane zawsze.' ),
			'set_title'      => array( 'Zmieniaj tytuł załącznika', 'Tylko gdy tytuł wygląda jak nazwa pliku. Uwaga: wtyczki typu „Media File Renamer” zmieniają wtedy nazwę pliku – a to psuje miniatury. Włącz tylko, gdy takiej wtyczki nie ma.' ),
			'alt_fallback'   => array( 'Alt awaryjny', 'Zdjęcie produktu bez alt dostaje na stronie nazwę produktu (+ numer ujęcia).' ),
			'schema_gallery' => array( 'Galeria w danych strukturalnych', 'W schemacie Product wszystkie zdjęcia produktu w pełnej rozdzielczości (Google zaleca kilka zdjęć).' ),
		);
		foreach ( $checks as $k => $c ) {
			echo '<tr><th>' . esc_html( $c[0] ) . '</th><td><label><input type="checkbox" name="' . esc_attr( $f( $k ) ) . '" value="1" ' . checked( fxi_opt( $k ), 1, false ) . '> ' . esc_html( $c[1] ) . '</label></td></tr>';
		}
		?>
	</table>
	<?php
	submit_button( 'Zapisz' );
	echo '</form>';
}

/** Odinstalowanie: usuń ustawienia (znaczniki _fxi_ai na zdjęciach zostają – nieszkodliwe). */
register_uninstall_hook( __FILE__, 'fxi_uninstall' );
function fxi_uninstall() {
	delete_option( 'fxi_settings' );
	delete_option( 'fxi_state_repair' );
	delete_option( 'fxi_state_seo' );
}
