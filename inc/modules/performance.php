<?php
/**
 * Wydajność (PageSpeed): mniej blokujących plików, czcionki z własnego serwera,
 * szybsze pierwsze zdjęcie, właściwe rozmiary miniatur.
 *
 * Każdy punkt ma filtr „fx_perf_*” – można go wyłączyć bez zmiany kodu motywu.
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

/* =====================================================================
 * 1. Czcionki z własnego serwera
 * Motyw raz pobiera pliki z Google Fonts na serwer sklepu (uploads/fx-fonts) i wstawia krótki
 * @font-face prosto do <head>. Bez zapytania do fonts.googleapis.com (blokowało stronę ~0,8 s)
 * i bez łączenia z kolejną domeną. Gdy pobranie się nie uda – zostaje Google Fonts jak dotąd.
 * ================================================================== */

function fx_fonts_google_url() {
	return 'https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Outfit:wght@500;600&display=swap';
}

/** Dane lokalnych czcionek albo null, gdy jeszcze ich nie ma. */
function fx_fonts_local() {
	$d = get_option( 'fx_fonts_local' );
	return ( is_array( $d ) && ! empty( $d['css'] ) && md5( fx_fonts_google_url() ) === ( $d['src'] ?? '' ) ) ? $d : null;
}

/** Pobranie czcionek na serwer (tylko latin + latin-ext – polskie znaki; bez cyrylicy itp.). */
function fx_fonts_build() {
	$res = wp_remote_get(
		fx_fonts_google_url(),
		array(
			'timeout'    => 15,
			'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
		)
	);
	if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
		return false;
	}
	$css = wp_remote_retrieve_body( $res );
	// Bloki @font-face poprzedzone komentarzem z nazwą podzbioru znaków.
	if ( ! preg_match_all( '#/\*\s*([a-z\-]+)\s*\*/\s*(@font-face\s*\{[^}]+\})#i', $css, $m, PREG_SET_ORDER ) ) {
		return false;
	}
	$up  = wp_upload_dir();
	$dir = trailingslashit( $up['basedir'] ) . 'fx-fonts/';
	$url = trailingslashit( set_url_scheme( $up['baseurl'] ) ) . 'fx-fonts/';
	if ( ! wp_mkdir_p( $dir ) ) {
		return false;
	}
	$out     = '';
	$files   = array();
	$preload = array();
	foreach ( $m as $blk ) {
		if ( ! in_array( strtolower( $blk[1] ), array( 'latin', 'latin-ext' ), true ) ) {
			continue;
		}
		$face = $blk[2];
		if ( ! preg_match( '#url\((https://fonts\.gstatic\.com/[^)]+\.woff2)\)#', $face, $u ) ) {
			continue;
		}
		$name = md5( $u[1] ) . '.woff2';
		if ( ! isset( $files[ $name ] ) ) {
			if ( ! file_exists( $dir . $name ) ) {
				$f = wp_remote_get( $u[1], array( 'timeout' => 15 ) );
				if ( is_wp_error( $f ) || 200 !== (int) wp_remote_retrieve_response_code( $f ) || strlen( wp_remote_retrieve_body( $f ) ) < 1000 ) {
					return false;
				}
				file_put_contents( $dir . $name, wp_remote_retrieve_body( $f ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
			$files[ $name ] = true;
		}
		$face = str_replace( $u[1], $url . $name, $face );
		$out .= preg_replace( '/\s+/', ' ', $face ) . "\n";
		// Wstępnie ładujemy tylko tekst główny (DM Sans, zwykłe znaki łacińskie).
		if ( 'latin' === strtolower( $blk[1] ) && false !== stripos( $face, 'DM Sans' ) && ! $preload ) {
			$preload[] = $url . $name;
		}
	}
	if ( ! $out ) {
		return false;
	}
	update_option(
		'fx_fonts_local',
		array(
			'src'     => md5( fx_fonts_google_url() ),
			'css'     => $out,
			'preload' => $preload,
			'built'   => time(),
		),
		true
	);
	return true;
}

/* Budowa w tle: w panelu albo w cronie, najwyżej raz na 6 h przy niepowodzeniu. */
function fx_fonts_maybe_build() {
	if ( 'google' !== fx_mod( 'fonts' ) || ! apply_filters( 'fx_perf_local_fonts', true ) || fx_fonts_local() || get_transient( 'fx_fonts_try' ) ) {
		return;
	}
	set_transient( 'fx_fonts_try', 1, 6 * HOUR_IN_SECONDS );
	fx_fonts_build();
}
add_action( 'admin_init', 'fx_fonts_maybe_build' );
add_action( 'fx_fonts_build_event', 'fx_fonts_maybe_build' );
add_action( 'init', function () {
	if ( ! is_admin() && 'google' === fx_mod( 'fonts' ) && ! fx_fonts_local() && ! wp_next_scheduled( 'fx_fonts_build_event' ) && ! get_transient( 'fx_fonts_try' ) ) {
		wp_schedule_single_event( time() + 30, 'fx_fonts_build_event' );
	}
} );

/** Czy używamy lokalnych czcionek na tej stronie. */
function fx_fonts_use_local() {
	return 'google' === fx_mod( 'fonts' ) && apply_filters( 'fx_perf_local_fonts', true ) && fx_fonts_local();
}

add_action( 'wp_enqueue_scripts', function () {
	if ( fx_fonts_use_local() ) {
		wp_dequeue_style( 'fx-fonts' );
	}
}, 20 );

add_action( 'wp_head', function () {
	if ( ! fx_fonts_use_local() ) {
		return;
	}
	$d = fx_fonts_local();
	foreach ( (array) $d['preload'] as $p ) {
		echo '<link rel="preload" href="' . esc_url( $p ) . '" as="font" type="font/woff2" crossorigin>' . "\n";
	}
	echo '<style id="fx-fonts-local">' . wp_strip_all_tags( $d['css'] ) . '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
}, 2 );

add_filter( 'wp_resource_hints', function ( $urls, $relation ) {
	if ( 'preconnect' === $relation && fx_fonts_use_local() ) {
		$urls = array_values(
			array_filter(
				$urls,
				function ( $u ) {
					$h = is_array( $u ) ? ( $u['href'] ?? '' ) : $u;
					return false === strpos( $h, 'fonts.gstatic.com' ) && false === strpos( $h, 'fonts.googleapis.com' );
				}
			)
		);
	}
	return $urls;
}, 20, 2 );

/* =====================================================================
 * 2. Skrypty i style wtyczek tylko tam, gdzie są potrzebne
 * ================================================================== */

/** Czy uchwyt (skrypt/styl) pochodzi z danej wtyczki. */
function fx_perf_src_has( $obj, $handle, $needle ) {
	return isset( $obj->registered[ $handle ] ) && false !== strpos( (string) $obj->registered[ $handle ]->src, $needle );
}

add_action( 'wp_enqueue_scripts', function () {
	if ( is_admin() ) {
		return;
	}
	global $wp_scripts, $wp_styles;

	/*
	 * Przelewy24: skrypty płatności dla zamówienia BLOKOWEGO (wc-blocks + React, ~750 KB JS)
	 * ładowały się na każdej stronie. Sklep ma klasyczne zamówienie, więc poza kasą są zbędne.
	 * Na stronie zamówienia (także „zapłać za zamówienie”) i w koszyku zostają bez zmian.
	 */
	if ( apply_filters( 'fx_perf_p24_checkout_only', true ) && ! ( function_exists( 'is_checkout' ) && ( is_checkout() || is_cart() ) ) ) {
		foreach ( (array) $wp_scripts->queue as $h ) {
			if ( fx_perf_src_has( $wp_scripts, $h, '/woo-przelewy24/' ) ) {
				wp_dequeue_script( $h );
			}
		}
		foreach ( (array) $wp_styles->queue as $h ) {
			if ( fx_perf_src_has( $wp_styles, $h, '/woo-przelewy24/' ) ) {
				wp_dequeue_style( $h );
			}
		}
	}

	/* Instagram Feed (Smash Balloon): style tylko na stronach z kanałem Instagrama. */
	if ( apply_filters( 'fx_perf_sbi_only_with_feed', true ) ) {
		$post = get_post();
		$has  = $post && ( has_shortcode( $post->post_content, 'instagram-feed' ) || false !== strpos( $post->post_content, 'wp:sbi/' ) || false !== strpos( $post->post_content, 'sbi_' ) );
		if ( ! $has || ! is_singular() ) {
			foreach ( (array) $wp_styles->queue as $h ) {
				if ( fx_perf_src_has( $wp_styles, $h, '/instagram-feed/' ) ) {
					wp_dequeue_style( $h );
				}
			}
			foreach ( (array) $wp_scripts->queue as $h ) {
				if ( fx_perf_src_has( $wp_scripts, $h, '/instagram-feed/' ) ) {
					wp_dequeue_script( $h );
				}
			}
		}
	}

	/* Style bloków edytora – na listach produktów nie ma treści z bloków. */
	if ( apply_filters( 'fx_perf_no_block_css_on_archives', true ) && function_exists( 'is_shop' ) && ( is_shop() || is_product_taxonomy() ) ) {
		wp_dequeue_style( 'wp-block-library' );
		wp_dequeue_style( 'wp-block-library-theme' );
		wp_dequeue_style( 'wc-blocks-style' );
		wp_dequeue_style( 'classic-theme-styles' );
	}
}, 999 );

/* =====================================================================
 * 3. Zdjęcia: szybkie pierwsze zdjęcie i właściwe rozmiary miniatur
 * ================================================================== */

/**
 * Miniatury na listach produktów: telefon pokazuje 2 kolumny, komputer 3–4.
 * Wcześniej przeglądarka pobierała 600 px na kafelek szerokości ~180 px.
 */
add_filter( 'wp_get_attachment_image_attributes', function ( $attr, $att, $size ) {
	if ( is_admin() || 'woocommerce_thumbnail' !== $size || ! apply_filters( 'fx_perf_loop_sizes', true ) ) {
		return $attr;
	}
	if ( ! empty( $attr['srcset'] ) ) {
		$attr['sizes'] = '(max-width: 900px) 50vw, 340px';
	}
	// Pierwsza miniatura na liście (zwykle element LCP na telefonie) – bez czekania.
	static $first = true;
	if ( $first && function_exists( 'is_product_taxonomy' ) && ( is_product_taxonomy() || is_shop() ) ) {
		$first                 = false;
		$attr['loading']       = 'eager';
		$attr['fetchpriority'] = 'high';
	}
	return $attr;
}, 30, 3 );

/* =====================================================================
 * 4. Style WooCommerce w kodzie strony zamiast 3 osobnych plików (3.20.1)
 *    PageSpeed: woocommerce.css, -layout.css i -smallscreen.css blokowały wyświetlenie
 *    (ok. 1 s na telefonie). Te same reguły, ten sam porządek – tylko bez 3 zapytań.
 * ================================================================== */
function fx_perf_wc_css_inline() {
	static $css = null;
	if ( null !== $css ) {
		return $css;
	}
	$css = get_transient( 'fx_wc_css_inline' );
	$ver = defined( 'WC_VERSION' ) ? WC_VERSION : '';
	if ( is_array( $css ) && ( $css['v'] ?? '' ) === $ver ) {
		return $css;
	}
	$dir   = WC()->plugin_path() . '/assets/css/';
	$base  = WC()->plugin_url() . '/assets/';
	$parts = array();
	foreach ( array( 'woocommerce-layout' => '', 'woocommerce-smallscreen' => 'only screen and (max-width: 768px)', 'woocommerce' => '' ) as $file => $media ) {
		$raw = is_readable( $dir . $file . '.css' ) ? (string) file_get_contents( $dir . $file . '.css' ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( '' === $raw ) {
			$css = array( 'v' => $ver, 'css' => '' ); // Brak pliku – zostawiamy zwykłe ładowanie.
			return $css;
		}
		$raw     = str_replace( array( 'url("../', "url('../", 'url(../' ), array( 'url("' . $base, "url('" . $base, 'url(' . $base ), $raw );
		$parts[] = $media ? '@media ' . $media . '{' . $raw . '}' : $raw;
	}
	$css = array( 'v' => $ver, 'css' => implode( "\n", $parts ) );
	set_transient( 'fx_wc_css_inline', $css, DAY_IN_SECONDS );
	return $css;
}
add_action( 'wp_enqueue_scripts', function () {
	if ( is_admin() || ! apply_filters( 'fx_perf_inline_wc_css', true ) || ! function_exists( 'WC' ) ) {
		return;
	}
	if ( ! wp_style_is( 'woocommerce-general', 'enqueued' ) || '' === fx_perf_wc_css_inline()['css'] ) {
		return;
	}
	wp_dequeue_style( 'woocommerce-layout' );
	wp_dequeue_style( 'woocommerce-smallscreen' );
	wp_dequeue_style( 'woocommerce-general' );
	$GLOBALS['fx_wc_css_inlined'] = true;
}, 1000 );
// Przed stylami motywu (wp_print_styles = priorytet 8), więc kolejność reguł jak dotąd.
add_action( 'wp_head', function () {
	if ( ! empty( $GLOBALS['fx_wc_css_inlined'] ) ) {
		echo '<style id="fx-wc-css">' . fx_perf_wc_css_inline()['css'] . '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
	}
}, 7 );
