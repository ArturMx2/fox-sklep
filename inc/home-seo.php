<?php
/**
 * Strona główna pod SEO – treści, tytuł/opis, dane strukturalne.
 *
 * Treści domyślne napisane pod dane z Google Search Console (lipiec–październik 2026):
 * - marka („fox”, „fox racing”, „fox sklep”) – pozycje 1–3, utrzymać,
 * - frazy sklepowe bez marki („sklep cross”, „enduro sklep”, „sklep crossowy”, „sklep motocrossowy”,
 *   „sklep enduro”, „odzież enduro”) – pozycje 8–9, brak ich w tytule/H1/treści → H1, lead, sekcje,
 * - rower („fox mtb”, „fox rower”, „odzież rowerowa fox”, „fox ubrania na rower”) – pozycje 7–11,
 * - odzież („fox ubrania”, „odzież fox”, „bluza fox”) – CTR 2%, brak „odzież” w tytule,
 * - kategorie („kask fox”, „strój na crossa fox”, „zbroja fox”) – strona główna przechwytuje frazy
 *   kategorii bez kliknięć → mocne linki z dokładnymi nazwami do kategorii,
 * - lokalne („fox sklep wrocław”, „sklep enduro wrocław”, „sklep mokronos”) → sekcja sklepu + LocalBusiness.
 * Wszystko edytowalne w Wygląd → Dostosuj → FOX.SKLEP.PL.
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

function fx_home_seo_defaults( $home ) {
	$c = 'kategoria-produktu/';
	return array(
		'seo_title'        => 'Sklep FOX Racing – motocross, enduro i MTB | FOX.SKLEP.PL',
		'seo_desc'         => 'Sklep crossowy i enduro FOX Racing: kaski V1–V3, stroje na crossa, buty, ochraniacze i odzież rowerowa MTB. Sklep stacjonarny pod Wrocławiem, 14 dni na zwrot.',
		'seo_h1'           => 'Sklep FOX Racing – sprzęt na crossa, enduro i MTB',
		'seo_lead'         => 'Sklep crossowy, enduro i rowerowy z pełną ofertą FOX Racing: kaski, stroje na crossa, buty, ochraniacze i odzież MTB. Tylko oryginalne produkty z gwarancją, wysyłka w 48 godzin i sklep stacjonarny pod Wrocławiem, w którym przymierzysz sprzęt przed zakupem.',
		'seo_points'       => "Wysyłka w 48 h\nDarmowa dostawa od 300 zł\n14 dni na zwrot lub wymianę rozmiaru\nSklep stacjonarny pod Wrocławiem",
		'hub_moto_title'   => 'Sklep crossowy i enduro',
		'hub_moto_sub'     => 'Wszystko na tor i w teren – od kasku po buty.',
		'hub_moto_links'   => "Kaski Fox na crossa i enduro | {$home}{$c}off-road/kaski/\nStroje na crossa Fox | {$home}{$c}off-road/odziez-motocross/stroj-na-crossa/\nButy crossowe Fox | {$home}{$c}off-road/buty/\nZbroje i ochraniacze na crossa | {$home}{$c}off-road/ochraniacze/\nGogle crossowe Fox | {$home}{$c}off-road/gogle/\nRękawice na crossa | {$home}{$c}off-road/rekawiczki-fox/\nOdzież enduro i motocross | {$home}{$c}off-road/odziez-motocross/\nAkcesoria na crossa | {$home}{$c}off-road/akcesoria/",
		'hub_rower_title'  => 'Odzież rowerowa i kaski MTB',
		'hub_rower_sub'    => 'Sprzęt Fox na enduro, trail i downhill.',
		'hub_rower_links'  => "Odzież rowerowa Fox MTB | {$home}{$c}rowerowy/\nKaski rowerowe MTB | {$home}{$c}rowerowy/kaski-odziez-mtb/\nKaski full face Proframe | {$home}{$c}rowerowy/kaski-odziez-mtb/kaski-full-face/proframe/\nOchraniacze rowerowe | {$home}{$c}rowerowy/ochraniacze-akcesoria-rowerowy/\nButy rowerowe Fox Union | {$home}{$c}rowerowy/buty-rowerowe-fox-union/\nAkcesoria rowerowe Fox | {$home}{$c}rowerowy/akcesoria-rowerowy/",
		'hub_casual_title' => 'Odzież FOX na co dzień',
		'hub_casual_sub'   => 'Bluzy, kurtki, koszulki i czapki z kolekcji casual.',
		'hub_casual_links' => "Bluzy Fox | {$home}{$c}fox/odziez/bluzy-odziez-codz/\nKurtki Fox | {$home}{$c}fox/kurtki-fox/\nKoszulki Fox | {$home}{$c}fox/odziez/koszulki-odziez-codz/\nCzapki Fox | {$home}{$c}fox/akcesoria-odziez-codz/czapki-akcesoria-odziez-codz/\nWyprzedaż i outlet Fox | {$home}{$c}wyprzedaz/",
		'new_title_moto'   => 'Nowości na crossa i enduro',
		'new_title_rower'  => 'Nowości rowerowe Fox MTB',
		'explore_title'    => 'Odkrywaj kolekcje FOX',
		'faq_on'           => true,
		'faq_title'        => 'Najczęstsze pytania',
		'faq'              => "Czy w FOX.SKLEP.PL kupię oryginalne produkty FOX Racing? | Tak. Sprzedajemy wyłącznie oryginalne produkty FOX Racing z pełną gwarancją – od kasków i strojów na crossa, przez odzież rowerową MTB, po bluzy i czapki z kolekcji casual.\n"
			. "Gdzie kupić Fox we Wrocławiu? | W naszym sklepie stacjonarnym w Mokronosie Dolnym (ul. Stawowa 33A), przy zjeździe z autostrady A8 pod Wrocławiem. Zapraszamy od poniedziałku do piątku w godz. 11:00–17:00 – przymierzysz kask, buty czy strój na crossa przed zakupem.\n"
			. "Jak dobrać rozmiar kasku Fox? | Zmierz obwód głowy centymetrem krawieckim ok. 2 cm nad brwiami i porównaj wynik z tabelą rozmiarów przy kasku. Kask ma przylegać ciasno, ale nie uciskać. Jeśli wynik wypada pomiędzy rozmiarami, zadzwoń – doradzimy.\n"
			. "Czym różnią się kaski Fox V1, V2 i V3? | <a href=\"{$home}{$c}off-road/kaski/v1/\">Fox V1</a> to kask na start i do rekreacyjnej jazdy, <a href=\"{$home}{$c}off-road/kaski/v2/\">V2</a> – wybór dla regularnie jeżdżących, a <a href=\"{$home}{$c}off-road/kaski/v3/\">V3</a> i V3 RS to topowe, najlżejsze kaski wyczynowe. Wszystkie modele znajdziesz w kategorii <a href=\"{$home}{$c}off-road/kaski/\">kaski Fox</a>.\n"
			. "Co wchodzi w skład stroju na crossa? | <a href=\"{$home}{$c}off-road/odziez-motocross/stroj-na-crossa/\">Strój na crossa</a> to koszulka (bluza) i spodnie w tej samej grafice, najczęściej z rękawicami. U nas zbudujesz komplet jednym kliknięciem, wybierając rozmiar każdej części osobno.\n"
			. "Czy macie odzież rowerową Fox MTB? | Tak – <a href=\"{$home}{$c}rowerowy/kaski-odziez-mtb/\">kaski MTB</a> (full face Proframe i Rampage oraz kaski otwarte), ochraniacze, koszulki, spodenki, rękawice i <a href=\"{$home}{$c}rowerowy/akcesoria-rowerowy/\">akcesoria rowerowe</a> Fox.\n"
			. "Ile trwa dostawa i jak zwrócić produkt? | Zamówienia wysyłamy w 48 godzin (InPost lub kurier DPD), dostawa jest darmowa od 300 zł. Masz 14 dni na zwrot lub wymianę rozmiaru.",
		'about_title'      => 'FOX.SKLEP.PL – sklep motocrossowy, enduro i rowerowy',
		'seo_text'         => '<p>FOX Racing to marka, która od 1974 roku ubiera zawodników motocrossu, a dziś także rowerzystów MTB na całym świecie. FOX.SKLEP.PL od ponad 10 lat sprzedaje wyłącznie oryginalne produkty FOX z gwarancją – w sklepie internetowym i stacjonarnym pod Wrocławiem.</p>'
			. "\n<!--more-->\n"
			. '<h3>Sprzęt na crossa i enduro</h3><p>Kompletujesz sprzęt na tor albo w teren? Dobierzesz u nas <a href="' . $home . $c . 'off-road/kaski/">kask Fox</a> – od modelu V1 po V3 RS, <a href="' . $home . $c . 'off-road/odziez-motocross/stroj-na-crossa/">strój na crossa</a> w jednej grafice, <a href="' . $home . $c . 'off-road/buty/">buty crossowe</a> Instinct, Comp i Motion, <a href="' . $home . $c . 'off-road/ochraniacze/">zbroję i ochraniacze</a>, gogle oraz rękawice. Doradzimy, jak zestawić rozmiary, żeby kask, buzer i buty pracowały razem.</p>'
			. '<h3>Odzież rowerowa Fox MTB</h3><p>Dla rowerzystów mamy <a href="' . $home . $c . 'rowerowy/">odzież rowerową Fox</a> na enduro, trail i downhill: kaski full face Proframe i Rampage, lekkie kaski otwarte, ochraniacze kolan i łokci, koszulki, spodenki oraz akcesoria rowerowe.</p>'
			. '<h3>Odzież Fox na co dzień</h3><p>Poza trasą – <a href="' . $home . $c . 'fox/odziez/bluzy-odziez-codz/">bluzy Fox</a>, kurtki, koszulki i czapki z kolekcji casual, które noszą fani motosportu na co dzień.</p>'
			. '<h3>Dlaczego warto u nas kupić</h3><p>Wysyłka w 48 godzin, darmowa dostawa od 300 zł, 14 dni na zwrot lub wymianę rozmiaru i pomoc w doborze rozmiaru – telefonicznie albo na miejscu w sklepie w Mokronosie Dolnym.</p>',
		'store_text'       => 'Przymierz kask, buty albo strój na crossa przed zakupem – doradzimy rozmiar. Dojazd: zjazd z autostrady A8 pod Wrocławiem.',
		'store_name'       => 'FOX.SKLEP.PL – sklep stacjonarny',
		'store_street'     => 'ul. Stawowa 33A',
		'store_postcode'   => '',
		'store_city'       => 'Mokronos Dolny',
		'store_phone'      => '+48 71 880 85 54',
		'store_hours'      => 'Mo-Fr 11:00-17:00',
		'store_hours_text' => 'Pon.–pt. 11:00–17:00',
	);
}

/* ---------- Ustawienia ---------- */
add_action( 'customize_register', function ( $wp ) {
	$d   = fx_defaults();
	$add = function ( $key, $section, $label, $type = 'text' ) use ( $wp, $d ) {
		$san = array( 'text' => 'sanitize_text_field', 'html' => 'fx_sanitize_html', 'textarea' => 'fx_sanitize_textarea', 'checkbox' => 'fx_sanitize_bool' );
		$wp->add_setting( 'fx_' . $key, array( 'default' => isset( $d[ $key ] ) ? $d[ $key ] : '', 'sanitize_callback' => $san[ $type ] ) );
		$wp->add_control( 'fx_' . $key, array( 'section' => $section, 'label' => $label, 'type' => 'checkbox' === $type ? 'checkbox' : ( 'text' === $type ? 'text' : 'textarea' ) ) );
	};
	$add( 'seo_title', 'fx_home_seo', 'Tytuł strony głównej w Google (puste = z Yoast)' );
	$add( 'seo_desc', 'fx_home_seo', 'Opis strony głównej w Google (puste = z Yoast)', 'textarea' );
	$add( 'seo_lead', 'fx_home_seo', 'Wstęp pod H1 (na górze strony)', 'textarea' );
	$add( 'seo_points', 'fx_home_seo', 'Atuty pod wstępem (jedna linia = jeden punkt)', 'textarea' );
	$add( 'about_title', 'fx_home_seo', 'Nagłówek tekstu „O sklepie” (na dole strony)' );
	foreach ( array( 'moto' => 'Moto', 'rower' => 'Rower', 'casual' => 'Casual (dla wszystkich)' ) as $k => $l ) {
		$add( "hub_{$k}_title", 'fx_home_hubs', "{$l} – nagłówek" );
		$add( "hub_{$k}_sub", 'fx_home_hubs', "{$l} – podtytuł" );
		$add( "hub_{$k}_links", 'fx_home_hubs', "{$l} – kategorie („Nazwa linku | adres”, jedna na linię)", 'textarea' );
	}
	$add( 'new_title_moto', 'fx_home_new', 'Tytuł produktów – Moto' );
	$add( 'new_title_rower', 'fx_home_new', 'Tytuł produktów – Rower' );
	$add( 'faq_on', 'fx_home_faq', 'Pokaż sekcję', 'checkbox' );
	$add( 'faq_title', 'fx_home_faq', 'Tytuł sekcji' );
	$add( 'faq', 'fx_home_faq', 'Pytania („Pytanie | Odpowiedź”, jedna para na linię; w odpowiedzi można użyć linków)', 'html' );
	foreach ( array( 'store_name' => 'Nazwa sklepu (dane dla Google)', 'store_street' => 'Ulica', 'store_postcode' => 'Kod pocztowy', 'store_city' => 'Miejscowość', 'store_phone' => 'Telefon', 'store_hours_text' => 'Godziny (tekst na stronie)', 'store_hours' => 'Godziny dla Google (np. Mo-Fr 11:00-17:00; Sa 10:00-14:00)' ) as $k => $l ) {
		$add( $k, 'fx_home_store', $l );
	}
}, 30 );

add_action( 'customize_register', function ( $wp ) {
	$wp->add_section( 'fx_home_hubs', array( 'title' => 'Strona główna – kategorie (linki SEO)', 'panel' => 'fx_panel', 'description' => 'Kafle kategorii pod wstępem. Nazwy linków to frazy, na które mają wychodzić kategorie w Google (np. „Kaski Fox na crossa i enduro”). Kategorie, których nie ma w sklepie, są pomijane.' ) );
	$wp->add_section( 'fx_home_faq', array( 'title' => 'Strona główna – najczęstsze pytania', 'panel' => 'fx_panel' ) );
}, 25 );

/* ---------- Tytuł i opis strony głównej ---------- */
function fx_home_seo_on() {
	return is_front_page() && fx_mod( 'home_enabled' );
}
foreach ( array( 'wpseo_title', 'wpseo_opengraph_title', 'wpseo_twitter_title', 'rank_math/frontend/title' ) as $fx_f ) {
	add_filter( $fx_f, function ( $t ) {
		return ( fx_home_seo_on() && fx_mod( 'seo_title' ) ) ? fx_mod( 'seo_title' ) : $t;
	}, 99 );
}
foreach ( array( 'wpseo_metadesc', 'wpseo_opengraph_desc', 'wpseo_twitter_description', 'rank_math/frontend/description' ) as $fx_f ) {
	add_filter( $fx_f, function ( $t ) {
		return ( fx_home_seo_on() && fx_mod( 'seo_desc' ) ) ? fx_mod( 'seo_desc' ) : $t;
	}, 99 );
}
/* Bez wtyczki SEO: tytuł i opis z motywu. */
add_filter( 'pre_get_document_title', function ( $t ) {
	if ( fx_home_seo_on() && fx_mod( 'seo_title' ) && ! defined( 'WPSEO_VERSION' ) && ! class_exists( 'RankMath' ) ) {
		return fx_mod( 'seo_title' );
	}
	return $t;
}, 99 );
add_action( 'wp_head', function () {
	if ( fx_home_seo_on() && fx_mod( 'seo_desc' ) && ! defined( 'WPSEO_VERSION' ) && ! class_exists( 'RankMath' ) ) {
		echo '<meta name="description" content="' . esc_attr( fx_mod( 'seo_desc' ) ) . '">' . "\n";
	}
}, 2 );

/* ---------- Dane strukturalne: sklep stacjonarny + FAQ ---------- */
add_action( 'wp_footer', function () {
	if ( ! fx_home_seo_on() ) {
		return;
	}
	$graph = array();
	if ( fx_mod( 'store_on' ) && fx_mod( 'store_street' ) ) {
		$logo  = get_theme_mod( 'custom_logo' ) ? wp_get_attachment_image_url( get_theme_mod( 'custom_logo' ), 'full' ) : '';
		$store = array(
			'@type'     => 'SportingGoodsStore',
			'@id'       => home_url( '/#sklep-stacjonarny' ),
			'name'      => fx_mod( 'store_name' ),
			'url'       => home_url( '/' ),
			'telephone' => fx_mod( 'store_phone' ),
			'address'   => array_filter(
				array(
					'@type'           => 'PostalAddress',
					'streetAddress'   => fx_mod( 'store_street' ),
					'postalCode'      => fx_mod( 'store_postcode' ),
					'addressLocality' => fx_mod( 'store_city' ),
					'addressRegion'   => 'dolnośląskie',
					'addressCountry'  => 'PL',
				)
			),
			'brand'     => array( '@type' => 'Brand', 'name' => 'FOX Racing' ),
		);
		if ( fx_mod( 'store_hours' ) ) {
			$store['openingHours'] = array_values( array_filter( array_map( 'trim', explode( ';', fx_mod( 'store_hours' ) ) ) ) );
		}
		if ( fx_mod( 'store_btn_url' ) ) {
			$store['hasMap'] = fx_mod( 'store_btn_url' );
		}
		if ( $logo ) {
			$store['image'] = $logo;
		}
		$graph[] = array_filter( $store );
	}
	$faq = fx_home_faq_items();
	if ( fx_mod( 'faq_on' ) && $faq ) {
		$items = array();
		foreach ( $faq as $f ) {
			$items[] = array( '@type' => 'Question', 'name' => $f[0], 'acceptedAnswer' => array( '@type' => 'Answer', 'text' => wp_strip_all_tags( $f[1] ) ) );
		}
		$graph[] = array( '@type' => 'FAQPage', '@id' => home_url( '/#faq' ), 'mainEntity' => $items );
	}
	if ( $graph ) {
		echo '<script type="application/ld+json">' . wp_json_encode( array( '@context' => 'https://schema.org', '@graph' => $graph ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>' . "\n";
	}
} );

function fx_home_faq_items() {
	return array_values(
		array_filter(
			fx_lines( fx_mod( 'faq' ) ),
			function ( $l ) {
				return '' !== $l[0] && '' !== $l[1];
			}
		)
	);
}

/**
 * Kafle kategorii z listy „Nazwa | adres”: zdjęcie kategorii i liczba produktów, gdy adres to kategoria sklepu.
 * Kategorie, których nie ma, są pomijane (żeby nie linkować do 404).
 */
function fx_home_hub_items( $text ) {
	$out = array();
	foreach ( fx_lines( $text ) as $l ) {
		if ( '' === $l[0] || '' === $l[1] ) {
			continue;
		}
		$url  = $l[1];
		$term = null;
		$path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
		if ( preg_match( '#(?:^|/)kategoria-produktu/(.+)$#', $path, $m ) ) {
			$slugs = explode( '/', $m[1] );
			$term  = get_term_by( 'slug', end( $slugs ), 'product_cat' );
			if ( ! $term ) {
				continue;
			}
			$url = get_term_link( $term );
		}
		$out[] = array(
			'label' => $l[0],
			'url'   => $url,
			'img'   => $term ? fx_home_term_image( $term ) : 0,
			'count' => $term ? max( (int) $term->count, (int) get_term_meta( $term->term_id, 'product_count_product_cat', true ) ) : 0,
		);
	}
	return $out;
}

/** Zdjęcie kategorii; gdy kategoria nie ma miniatury – zdjęcie najnowszego produktu z niej (cache 1 dzień). */
function fx_home_term_image( $term ) {
	$id = (int) get_term_meta( $term->term_id, 'thumbnail_id', true );
	if ( $id ) {
		return $id;
	}
	$key = 'fx_hub_img_' . $term->term_id;
	$c   = get_transient( $key );
	if ( false !== $c ) {
		return (int) $c;
	}
	$id  = 0;
	$ids = get_posts(
		array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_key'       => '_thumbnail_id', // phpcs:ignore WordPress.DB.SlowDBQuery
			'tax_query'      => array( array( 'taxonomy' => 'product_cat', 'terms' => $term->term_id, 'include_children' => true ) ), // phpcs:ignore
		)
	);
	if ( $ids ) {
		$id = (int) get_post_thumbnail_id( $ids[0] );
	}
	set_transient( $key, $id, DAY_IN_SECONDS );
	return $id;
}
