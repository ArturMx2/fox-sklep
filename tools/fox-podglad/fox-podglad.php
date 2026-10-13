<?php
/**
 * Plugin Name: FOX – podgląd motywu i porównanie SEO
 * Description: Pokazuje nowy motyw FOX.SKLEP.PL na działającym sklepie tylko osobom z kluczem podglądu (klienci i Google dalej widzą obecny motyw) i porównuje SEO obu wersji: Narzędzia → Porównanie SEO. Po przełączeniu motywu wtyczkę można usunąć.
 * Version: 1.0.0
 * Author: FOX.SKLEP.PL
 * Requires PHP: 7.4
 *
 * @package foxpodglad
 */

defined( 'ABSPATH' ) || exit;

/** Klucz podglądu (losowy, zapisany w bazie). */
function fxp_key() {
	$k = get_option( 'fxp_key' );
	if ( ! $k ) {
		$k = wp_generate_password( 20, false );
		update_option( 'fxp_key', $k, true );
	}
	return $k;
}

/** Katalog nowego motywu (szukany po nazwie „FOX.SKLEP.PL” / text domain „foxsklep”). */
function fxp_theme() {
	$cached = get_option( 'fxp_theme' );
	if ( $cached && is_dir( get_theme_root() . '/' . $cached ) ) {
		return $cached;
	}
	foreach ( wp_get_themes() as $slug => $t ) {
		if ( 'foxsklep' === $t->get( 'TextDomain' ) || 'FOX.SKLEP.PL' === $t->get( 'Name' ) ) {
			update_option( 'fxp_theme', $slug, true );
			return $slug;
		}
	}
	return '';
}

/** Adres strony w podglądzie nowego motywu. */
function fxp_preview_url( $url ) {
	return add_query_arg( 'fx_podglad', fxp_key(), $url );
}

/** Czy to żądanie ma pokazać nowy motyw: parametr ?fx_podglad=klucz albo ciasteczko po wejściu z kluczem. */
function fxp_is_preview() {
	static $on = null;
	if ( null !== $on ) {
		return $on;
	}
	$key = (string) get_option( 'fxp_key' );
	$get = isset( $_GET['fx_podglad'] ) ? sanitize_text_field( wp_unslash( $_GET['fx_podglad'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	$ck  = isset( $_COOKIE['fx_podglad'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['fx_podglad'] ) ) : '';
	// Panel admina zawsze w obecnym motywie (admin-ajax z podglądu – w nowym).
	$admin_page = is_admin() && ! ( defined( 'DOING_AJAX' ) && DOING_AJAX );
	$on  = ! $admin_page && $key && ( hash_equals( $key, $get ) || hash_equals( $key, $ck ) ) && '' !== fxp_theme();
	return $on;
}

/* Podmiana motywu tylko dla podglądu – bez zmiany ustawień sklepu. */
if ( fxp_is_preview() ) {
	$fxp_slug = fxp_theme();
	foreach ( array( 'template', 'stylesheet', 'pre_option_template', 'pre_option_stylesheet' ) as $fxp_f ) {
		add_filter( $fxp_f, function () use ( $fxp_slug ) {
			return $fxp_slug;
		}, 999 );
	}
	add_filter( 'pre_option_current_theme', function () {
		return 'FOX.SKLEP.PL';
	}, 999 );
	// Podgląd nie trafia do pamięci podręcznej ani do Google.
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
	add_action( 'send_headers', function () {
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		header( 'X-LiteSpeed-Cache-Control: no-cache' );
		header( 'X-Robots-Tag: noindex, nofollow' );
	} );
	// Wejście z ?fx_podglad=klucz zapamiętuje podgląd na 12 godzin, żeby można było klikać po sklepie.
	if ( isset( $_GET['fx_podglad'] ) && ! headers_sent() ) { // phpcs:ignore WordPress.Security.NonceVerification
		setcookie( 'fx_podglad', (string) get_option( 'fxp_key' ), time() + 12 * HOUR_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true );
	}
	// Mały pasek „Podgląd nowego motywu” z wyjściem.
	add_action( 'wp_footer', function () {
		echo '<div style="position:fixed;left:12px;bottom:12px;z-index:99999;background:#111;color:#fff;font:600 13px/1.3 system-ui,sans-serif;padding:10px 14px;border-radius:8px;box-shadow:0 4px 18px rgba(0,0,0,.3)">Podgląd nowego motywu · <a href="' . esc_url( add_query_arg( 'fx_podglad', 'koniec', home_url( '/' ) ) ) . '" style="color:#ff7a1a">zakończ</a></div>';
	} );
}

/* Wyjście z podglądu. */
add_action( 'init', function () {
	if ( isset( $_GET['fx_podglad'] ) && 'koniec' === $_GET['fx_podglad'] ) { // phpcs:ignore
		setcookie( 'fx_podglad', '', time() - 3600, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true );
		wp_safe_redirect( home_url( '/' ) );
		exit;
	}
} );

/* Porównanie SEO (ta sama kopia co w motywie). */
if ( is_admin() && ! function_exists( 'fx_seo_cmp_page' ) ) {
	require_once __DIR__ . '/seo-compare.php';
}

/* Adresy z największym ruchem w Google (Search Console, 3 miesiące do 13.10.2026). */
add_filter( 'fx_seo_cmp_sample', function () {
	return array(
		'/',
		'/kategoria-produktu/rowerowy/kaski-odziez-mtb/kaski-full-face/',
		'/kategoria-produktu/rowerowy/kaski-odziez-mtb/',
		'/kategoria-produktu/rowerowy/amortyzatory-fox/',
		'/kategoria-produktu/off-road/kaski/',
		'/kategoria-produktu/off-road/odziez-motocross/stroj-na-crossa/',
		'/kategoria-produktu/rowerowy/kaski-odziez-mtb/kaski-full-face/rampage/',
		'/kategoria-produktu/rowerowy/kaski-odziez-mtb/kaski-mtb/speedframe/',
		'/kategoria-produktu/rowerowy/odziez-mtb/',
		'/kategoria-produktu/off-road/rekawiczki-fox/',
		'/kategoria-produktu/off-road/ochraniacze/zbroje-i-buzery-ochraniacze/',
		'/kategoria-produktu/rowerowy/odziez-mtb/koszulki-odziez-mtb/',
		'/kategoria-produktu/off-road/gogle/',
		'/kategoria-produktu/rowerowy/odziez-mtb/spodnie-odziez-mtb/',
		'/kategoria-produktu/rowerowy/',
		'/kategoria-produktu/rowerowy/kaski-odziez-mtb/kaski-full-face/proframe/',
		'/kategoria-produktu/fox/odziez/bluzy-odziez-codz/',
		'/kategoria-produktu/rowerowy/rekawice-akcesoria-rowerowy/',
		'/kategoria-produktu/off-road/odziez-motocross/',
		'/kategoria-produktu/rowerowy/akcesoria-rowerowy/blotniki/',
		'/kategoria-produktu/rowerowy/kaski-odziez-mtb/kaski-full-face/proframe-rs/',
		'/kategoria-produktu/rowerowy/buty-rowerowe-fox-union/',
		'/kategoria-produktu/rowerowy/odziez-mtb/spodenki/',
		'/kategoria-produktu/rowerowy/ochraniacze-akcesoria-rowerowy/zbroje-i-buzery/',
		'/kategoria-produktu/fox/odziez/koszulki-odziez-codz/',
		'/kategoria-produktu/off-road/ochraniacze/',
		'/kategoria-produktu/off-road/buty/',
		'/kategoria-produktu/rowerowy/ochraniacze-akcesoria-rowerowy/',
		'/kategoria-produktu/off-road/odziez-motocross/koszulki-odziez-enduro/',
		'/kategoria-produktu/rowerowy/odziez-mtb/kurtki-odziez-mtb/',
		'/kategoria-produktu/rowerowy/gogle-akcesoria-rowerowy/',
		'/kategoria-produktu/rowerowy/ochraniacze-akcesoria-rowerowy/kolana/',
		'/kategoria-produktu/fox/akcesoria-odziez-codz/czapki-akcesoria-odziez-codz/',
		'/kategoria-produktu/off-road/kaski/v1/',
		'/kategoria-produktu/off-road/gogle/szyby-do-gogli/',
		'/kategoria-produktu/fox/odziez/kurtki-odziez/',
		'/kategoria-produktu/rowerowy/odziez-mtb/page/8/',
		'/kategoria-produktu/off-road/kaski/page/2/',
		'/kategoria-produktu/off-road/kaski/page/3/',
		'/produkt/buzer-fox-r3-black/',
		'/produkt/ochraniacz-klatki-piersiowej-fox-junior-raceframe-roost-black/',
		'/produkt/buzer-fox-raceframe-impact-sb-d3o-black/',
		'/produkt/amortyzator-fox-34-factory-2022-float-fit4-boost-140-mm-mtb-kolo-29/',
		'/produkt/kask-fox-v1-leed-pink/',
		'/produkt/ochraniacz-fox-launch-knee-czarny/',
		'/produkt/koszulka-z-ochraniaczami-fox-baseframe-pro-d3o-black/',
		'/produkt/amortyzator-fox-racing-shox-factory-32-100mm-float-sc-kolo-29/',
		'/produkt/amortyzator-fox-racing-shox-36-k-float-29-factory-gripx-boost-160mm-e-bike/',
		'/produkt/opona-rymax-ev-w7015-70-100-19-tt-dedykowana-do-sur-ron-light-bee-light-bee-x-przod-tyl/',
		'/produkt/kask-rowerowy-fox-rampage-rs-matte-black/',
		'/produkt/buzer-fox-airframe-black/',
		'/produkt/kurtka-fox-ranger-water-black/',
		'/produkt/rekawice-fox-dirtpaw-black-black/',
		'/produkt/orteza-kolanowa-ossur-cti3/',
		'/produkt/gogle-fox-main-core-goggle-black/',
		'/produkt/ochraniacz-kolan-fox-launch-pro-knee-guard-black/',
		'/produkt/ochraniacz-kolan-fox-enduro-pro-black/',
		'/produkt/ipone-samourai-olej-do-mieszanki-2t-100-syntetyk-1l-truskawka-zastepuje-800090/',
		'/produkt/kask-rowerowy-fox-junior-proframe-matte-czarny-full-face/',
		'/ckit_size_guide/tabela-rozmiarow-kask-fox-rampage/',
		'/ckit_size_guide/tabela-rozmiarow-butow-fox/',
		'/ckit_size_guide/tabela-rozmiarow-spodnie-crossowe-fox/',
		'/ckit_size_guide/tabela-rozmiarow-rekawiczki-fox-racing/',
		'/kalendarz-zawodow-motocross-2026/',
		'/buzer-fox-junior-jaki-ochraniacz-wybrac-dla-mlodego-rajdera-przeglad-modeli-raceframe-r3-i-raptor/',
		'/spodnie-rowerowe-fox/',
		'/poradnik-rekawice-fox/',
		'/category/produkty/',
		'/historia-marki-fox-racing/',
		'/home-6/',
		'/kalendarz-zawodow-enduro-i-cross-country-2025/',
	);
} );

/* Na stronie porównania: link do podglądu i stan. */
add_action( 'admin_notices', function () {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || 'tools_page_fx-seo-compare' !== $screen->id ) {
		return;
	}
	if ( ! fxp_theme() ) {
		echo '<div class="notice notice-error"><p><strong>Nie znaleziono motywu FOX.SKLEP.PL.</strong> Zainstaluj go (Wygląd → Motywy → Dodaj), ale nie włączaj – podgląd i porównanie działają bez włączania.</p></div>';
		return;
	}
	echo '<div class="notice notice-info"><p>Nowy motyw: <code>' . esc_html( fxp_theme() ) . '</code> (nie musi być włączony). <a href="' . esc_url( fxp_preview_url( home_url( '/' ) ) ) . '" target="_blank">Otwórz sklep w podglądzie nowego motywu</a> – widzisz go tylko Ty (link z kluczem), klienci i Google dalej widzą obecny motyw.</p></div>';
} );

/* Link „Porównanie SEO” na liście wtyczek. */
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
	array_unshift( $links, '<a href="' . esc_url( admin_url( 'tools.php?page=fx-seo-compare' ) ) . '">Porównanie SEO</a>' );
	return $links;
} );
