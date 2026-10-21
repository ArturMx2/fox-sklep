<?php
/**
 * Ustawienia w Wygląd → Dostosuj (Personalizacja).
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wartości domyślne.
 */
function fx_defaults() {
	static $d = null;
	if ( null !== $d ) {
		return $d;
	}
	$home = home_url( '/' );
	$d    = array(
		// Ogólne.
		'announce'        => 'Darmowa dostawa od 300 zł · 14 dni na zwrot · <a href="#salon">Przymierz w sklepie pod Wrocławiem</a>',
		'fonts'           => 'google',
		'loader_on'       => true,
		'loader_text'     => 'Ładujemy stronę…',
		'footer_note'     => 'Autoryzowany sklep FOX Racing',
		'size_chart_url'  => $home . 'tabela-rozmiarow/',
		'size_help'       => 'Nie wiesz, jaki rozmiar? Zadzwoń <a href="tel:+48718808554">+48 71 880 85 54</a> albo przymierz w sklepie.',
		'delivery_text'   => "Darmowa dostawa od 300 zł, InPost lub kurier DPD.\nMasz 14 dni na zwrot lub wymianę rozmiaru.",
		'trust_1'         => 'Darmowa dostawa od 300 zł · InPost lub DPD',
		'trust_2'         => 'Przymierz w sklepie w Mokronosie Dolnym',
		'trust_3'         => '14 dni na zwrot lub wymianę rozmiaru',
		// Menu główne.
		'menu_auto'        => false,
		'menu_auto_l3'     => 8,
		'menu_auto_append' => false,
		'menu_auto_skip'   => 'prezenty',
		// Kod.
		'code_head'       => '',
		'code_body'       => '',
		'datalayer'       => true,
		// Strona główna – hero.
		'home_enabled'    => true,
		'hero_img'        => 0,
		'hero_title'      => 'Kolekcja MX27',
		'hero_sub'        => 'Komplety na crossa w nowych grafikach',
		'hero_b1_label'   => 'Zbuduj komplet',
		'hero_b1_url'     => $home . 'kategoria-produktu/off-road/odziez-motocross/stroj-na-crossa/',
		'hero_b2_label'   => 'Kaski MX',
		'hero_b2_url'     => $home . 'kategoria-produktu/off-road/kaski/',
		// Przełącznik Moto / Rower.
		'worlds_on'         => true,
		'world_default'     => 'moto',
		'world_moto_img'    => 0,
		'world_moto_title'  => 'Moto',
		'world_moto_sub'    => 'Motocross i enduro – kaski, komplety, buty, ochraniacze',
		'world_moto_btn'    => 'Pokaż sprzęt moto',
		'world_moto_cat'    => 0,
		'world_rower_img'   => 0,
		'world_rower_title' => 'Rower',
		'world_rower_sub'   => 'MTB, enduro i downhill – kaski, odzież, ochraniacze',
		'world_rower_btn'   => 'Pokaż sprzęt rowerowy',
		'world_rower_cat'   => 0,
		'new_split'         => true,
		'guides_cat_moto'   => 0,
		'guides_cat_rower'  => 0,
		// Odkrywaj.
		'explore_title'   => 'Odkrywaj',
		'hero_on'         => true,
		'explore_on'      => true,
		'explore_count'   => 5,
		'tiles_on'        => true,
		'tiles_count'     => 4,
		'disc_on'         => true,
		'disc_count'      => 4,
		'new_on'          => true,
		// Kafle.
		'disc_title'      => 'Wybierz dyscyplinę',
		// Nowości.
		'new_title'       => 'Nowe w sklepie',
		'new_source'      => 'date',
		'new_tag'         => 'bestseller',
		'new_count'       => 6,
		'new_btn_label'   => 'Bestsellery',
		'new_btn_url'     => $home . 'tag-produktu/bestseller/',
		// Zawodnik.
		'rider_on'        => true,
		'rider_img'       => 0,
		'rider_kicker'    => 'Rodzina FOX Racing Polska',
		'rider_title'     => 'W czym jeździ Tymek Andrzejewski #212',
		'rider_text'      => 'Cały zestaw naszego zawodnika na jednej stronie, od kasku po buty.',
		'rider_list'      => "Kask | [Model]\nKomplet | [Model / grafika]\nGogle | [Model]\nButy | [Model]",
		'rider_btn_label' => 'Kup zestaw #212',
		'rider_btn_url'   => '',
		// Poradniki.
		'guides_on'       => true,
		'guides_title'    => 'Poradniki',
		'guides_count'    => 6,
		'guides_cat'      => 0,
		'guides_btn_url'  => $home . 'blog/',
		// SEO.
		'seo_h1'          => '',
		'seo_text'        => '',
		// Salon.
		'store_on'        => true,
		'store_map'       => true,
		'store_title'     => "Przymierz,\nzanim kupisz",
		'store_text'      => 'Sklep stacjonarny: ul. Stawowa 33A, Mokronos Dolny k. Wrocławia, zjazd z A8. Pon–pt 11:00–17:00. Telefon +48 71 880 85 54.',
		'store_btn_label' => 'Wyznacz trasę',
		'store_btn_url'   => 'https://goo.gl/maps/mPMKCkjxFu1EvcLLA',
	);

	$explore = array(
		array( 'Komplety MX27', 'kategoria-produktu/off-road/odziez-motocross/stroj-na-crossa/' ),
		array( 'W czym jeździ #212', '' ),
		array( 'Limitowana DNGR', 'kategoria-produktu/fox/' ),
		array( 'Ochraniacze', 'kategoria-produktu/off-road/ochraniacze/' ),
		array( 'Kaski Proframe', 'kategoria-produktu/rowerowy/kaski-odziez-mtb/kaski-full-face/proframe/' ),
	);
	$explore_world = array( 'moto', 'moto', 'all', 'moto', 'rower' );
	foreach ( $explore as $i => $e ) {
		$n                          = $i + 1;
		$d[ "explore_{$n}_world" ] = $explore_world[ $i ];
		$d[ "explore_{$n}_img" ]   = 0;
		$d[ "explore_{$n}_label" ] = $e[0];
		$d[ "explore_{$n}_url" ]   = $e[1] ? $home . $e[1] : '';
	}
	for ( $n = count( $explore ) + 1; $n <= 20; $n++ ) { // nowe kafle: domyślnie dla moto i roweru
		$d[ "explore_{$n}_world" ] = 'all';
	}

	$tiles = array(
		array( 'Kaski', "Motocross | {$home}kategoria-produktu/off-road/kaski/\nRower | {$home}kategoria-produktu/rowerowy/kaski-odziez-mtb/" ),
		array( 'Bluzy', "Zobacz | {$home}kategoria-produktu/fox/odziez/bluzy-odziez-codz/" ),
		array( 'Buty', "Instinct | {$home}kategoria-produktu/off-road/buty/buty-fox-instinct-2-0/\nComp | {$home}kategoria-produktu/off-road/buty/buty-fox-comp/\nMotion | {$home}kategoria-produktu/off-road/buty/buty-fox-motion/" ),
		array( 'Ochraniacze', "Motocross | {$home}kategoria-produktu/off-road/ochraniacze/\nRower | {$home}kategoria-produktu/rowerowy/ochraniacze-akcesoria-rowerowy/" ),
	);
	foreach ( $tiles as $i => $t ) {
		$n                        = $i + 1;
		$d[ "tile_{$n}_img" ]    = 0;
		$d[ "tile_{$n}_title" ]  = $t[0];
		$d[ "tile_{$n}_links" ]  = $t[1];
	}

	$disc = array(
		array( 'Motocross', 'kategoria-produktu/off-road/' ),
		array( 'Enduro', 'kategoria-produktu/off-road/buty/buty-enduro-fox/' ),
		array( 'Rower', 'kategoria-produktu/rowerowy/' ),
		array( 'Casual', 'kategoria-produktu/fox/' ),
	);
	foreach ( $disc as $i => $e ) {
		$n                       = $i + 1;
		$d[ "disc_{$n}_img" ]   = 0;
		$d[ "disc_{$n}_label" ] = $e[0];
		$d[ "disc_{$n}_url" ]   = $home . $e[1];
	}

	if ( function_exists( 'fx_home_seo_defaults' ) ) {
		$d = array_merge( $d, fx_home_seo_defaults( $home ) );
	}
	return $d;
}

/* ---------- Sanitizery ---------- */
function fx_sanitize_bool( $v ) {
	return (bool) $v;
}
function fx_sanitize_html( $v ) {
	return wp_kses_post( $v );
}
function fx_sanitize_textarea( $v ) {
	return sanitize_textarea_field( $v );
}
function fx_sanitize_code( $v ) {
	// Pełny kod (np. GTM) tylko dla administratora z uprawnieniem unfiltered_html.
	return current_user_can( 'unfiltered_html' ) ? $v : wp_kses_post( $v );
}
function fx_sanitize_fonts( $v ) {
	return in_array( $v, array( 'google', 'system' ), true ) ? $v : 'google';
}
function fx_sanitize_source( $v ) {
	return in_array( $v, array( 'date', 'tag', 'sale', 'featured' ), true ) ? $v : 'date';
}

add_action( 'customize_register', 'fx_customize' );
function fx_customize( $wp ) {
	$d = fx_defaults();

	$wp->add_panel(
		'fx_panel',
		array(
			'title'    => 'FOX.SKLEP.PL',
			'priority' => 20,
		)
	);

	$add = function ( $key, $section, $label, $type = 'text', $args = array() ) use ( $wp, $d ) {
		$san = array(
			'text'     => 'sanitize_text_field',
			'url'      => 'esc_url_raw',
			'html'     => 'fx_sanitize_html',
			'textarea' => 'fx_sanitize_textarea',
			'code'     => 'fx_sanitize_code',
			'checkbox' => 'fx_sanitize_bool',
			'number'   => 'absint',
			'image'    => 'absint',
			'fonts'    => 'fx_sanitize_fonts',
			'source'   => 'fx_sanitize_source',
			'cat'      => 'absint',
			'world'    => 'fx_sanitize_world',
			'pcat'     => 'absint',
		);
		$wp->add_setting(
			'fx_' . $key,
			array(
				'default'           => isset( $d[ $key ] ) ? $d[ $key ] : '',
				'sanitize_callback' => $san[ $type ],
				'transport'         => 'refresh',
			)
		);
		$base = array_merge(
			array(
				'section' => $section,
				'label'   => $label,
			),
			$args
		);
		if ( 'image' === $type ) {
			$wp->add_control( new WP_Customize_Media_Control( $wp, 'fx_' . $key, array_merge( $base, array( 'mime_type' => 'image' ) ) ) );
			return;
		}
		$map = array(
			'text'     => 'text',
			'url'      => 'url',
			'html'     => 'textarea',
			'textarea' => 'textarea',
			'code'     => 'textarea',
			'checkbox' => 'checkbox',
			'number'   => 'number',
		);
		if ( 'fonts' === $type ) {
			$base['type']    = 'select';
			$base['choices'] = array(
				'google' => 'Outfit + DM Sans (Google Fonts)',
				'system' => 'Fonty systemowe (bez zewnętrznych zapytań)',
			);
		} elseif ( 'source' === $type ) {
			$base['type']    = 'select';
			$base['choices'] = array(
				'date'     => 'Najnowsze produkty',
				'tag'      => 'Produkty z tagiem (poniżej)',
				'sale'     => 'Produkty w promocji',
				'featured' => 'Produkty polecane (gwiazdka)',
			);
		} elseif ( 'world' === $type ) {
			$base['type']    = 'select';
			$base['choices'] = array(
				'all'   => 'Zawsze (moto i rower)',
				'moto'  => 'Tylko Moto',
				'rower' => 'Tylko Rower',
			);
		} elseif ( 'pcat' === $type ) {
			$choices = array( 0 => 'Automatycznie' );
			$terms   = get_terms( array( 'taxonomy' => 'product_cat', 'parent' => 0, 'hide_empty' => false ) );
			foreach ( is_wp_error( $terms ) ? array() : $terms as $t ) {
				$choices[ $t->term_id ] = $t->name;
			}
			$base['type']    = 'select';
			$base['choices'] = $choices;
		} elseif ( 'cat' === $type ) {
			$choices = array( 0 => 'Wszystkie wpisy' );
			foreach ( get_categories( array( 'hide_empty' => false ) ) as $c ) {
				$choices[ $c->term_id ] = $c->name;
			}
			$base['type']    = 'select';
			$base['choices'] = $choices;
		} else {
			$base['type'] = $map[ $type ];
		}
		$wp->add_control( 'fx_' . $key, $base );
	};

	$section = function ( $id, $title, $desc = '' ) use ( $wp ) {
		$wp->add_section(
			$id,
			array(
				'title'       => $title,
				'panel'       => 'fx_panel',
				'description' => $desc,
			)
		);
	};

	/* Ogólne */
	$section( 'fx_general', 'Ogólne' );
	$add( 'announce', 'fx_general', 'Czarny pasek pod nagłówkiem (HTML dozwolony, puste = ukryty)', 'html' );
	$add( 'fonts', 'fx_general', 'Fonty', 'fonts', array( 'description' => 'Dla pełnej zgodności z RODO zostaw Google Fonts i zainstaluj wtyczkę OMGF – przeniesie fonty na Twój serwer.' ) );
	$add( 'loader_on', 'fx_general', 'Lisek „Ładujemy stronę…” przy przechodzeniu między stronami', 'checkbox' );
	$add( 'loader_text', 'fx_general', 'Tekst pod liskiem (puste = bez tekstu)' );
	$add( 'footer_note', 'fx_general', 'Tekst w stopce obok ©' );
	$add( 'size_chart_url', 'fx_general', 'Adres strony z tabelą rozmiarów', 'url' );
	$add( 'size_help', 'fx_general', 'Pomoc przy rozmiarze na karcie produktu (HTML)', 'html' );
	$add( 'trust_1', 'fx_general', 'Karta produktu – informacja 1 (dostawa)' );
	$add( 'trust_2', 'fx_general', 'Karta produktu – informacja 2 (sklep)' );
	$add( 'trust_3', 'fx_general', 'Karta produktu – informacja 3 (zwroty)' );
	$add( 'delivery_text', 'fx_general', 'Akordeon „Dostawa i zwroty” na karcie produktu', 'html' );

	/* Porównanie kasków */
	$section( 'fx_hc', 'Porównanie kasków V3 RS / V3 / V1', 'Sekcja „3 poziomy ochrony” na kartach kasków FOX V1, V3 i V3 RS. Zdjęcia najlepiej kwadratowe, z boku, na jednolitym jasnym tle (np. 1000×1000 px). Puste zdjęcie = zdjęcie kasku tego modelu z Waszych produktów. Pusty adres = kategoria modelu albo wyszukiwanie.' );
	foreach ( array( 'v3rs' => 'V3 RS', 'v3' => 'V3', 'v1' => 'V1' ) as $fx_k => $fx_n ) {
		$add( 'hc_img_' . $fx_k, 'fx_hc', 'Zdjęcie: ' . $fx_n, 'image' );
		$add( 'hc_url_' . $fx_k, 'fx_hc', 'Przycisk „Zobacz kaski ' . $fx_n . '” – adres', 'url' );
	}

	/* Menu główne */
	$section( 'fx_menu', 'Menu główne (automatyczne)', 'Pasek górny (CASUAL, MOTO, ROWER…) ustawiasz jak dotąd w Wygląd → Menu. Gdy włączysz tryb automatyczny, każda pozycja prowadząca do kategorii produktów dostaje rozwijany panel zbudowany sam z podkategorii, w których są produkty do kupienia. Puste kategorie znikają. Pozycje, które nie są kategoriami (np. Poradniki), zostają bez zmian. Podgląd bez włączania (tylko dla administratora): dodaj do adresu ?fx_menu=auto. Nazwę w menu albo ukrycie ustawisz w edycji kategorii.' );
	// Przełącznik w panelu „Menu” (tam, gdzie układa się menu), na samej górze.
	$wp->get_section( 'fx_menu' )->panel    = 'nav_menus';
	$wp->get_section( 'fx_menu' )->priority = 1;
	$add( 'menu_auto', 'fx_menu', 'Buduj panele menu automatycznie z kategorii', 'checkbox' );
	$add( 'menu_auto_l3', 'fx_menu', 'Ile linków pod nagłówkiem kolumny (potem „Więcej →”)', 'number', array( 'input_attrs' => array( 'min' => 3, 'max' => 20 ) ) );
	$add( 'menu_auto_append', 'fx_menu', 'Dopisuj na końcu paska kategorie główne, których w nim nie ma', 'checkbox' );
	$add( 'menu_auto_skip', 'fx_menu', 'Pomiń przy dopisywaniu (adresy kategorii, po przecinku, np. prezenty,wyprzedaz)' );

	/* Kod */
	$section( 'fx_code', 'Kod: Google Tag Manager i skrypty', 'Wklej tu kod GTM lub inne skrypty, jeśli były wpisane w ustawieniach starego motywu. Jeśli GTM dodaje wtyczka, zostaw puste – inaczej zdarzenia policzą się podwójnie.' );
	$add( 'code_head', 'fx_code', 'Kod w <head>', 'code' );
	$add( 'code_body', 'fx_code', 'Kod zaraz po <body> (np. GTM noscript)', 'code' );
	$add( 'datalayer', 'fx_code', 'Wysyłaj zdarzenie add_to_cart (GA4) do dataLayer z modułu „Zbuduj komplet”', 'checkbox' );

	/* Hero */
	$section( 'fx_home_hero', 'Strona główna – baner', 'Sekcje strony głównej działają, gdy w Ustawienia → Czytanie wybrana jest statyczna strona główna albo najnowsze wpisy.' );
	$add( 'home_enabled', 'fx_home_hero', 'Używaj nowej strony głównej motywu (odznacz, aby pokazać treść strony ustawionej jako główna)', 'checkbox' );
	$add( 'hero_on', 'fx_home_hero', 'Pokaż pojedynczy baner (gdy baner Moto / Rower jest wyłączony)', 'checkbox' );
	$add( 'hero_img', 'fx_home_hero', 'Zdjęcie banera (min. 2400 px szerokości)', 'image' );
	$add( 'hero_title', 'fx_home_hero', 'Nagłówek' );
	$add( 'hero_sub', 'fx_home_hero', 'Podtytuł' );
	$add( 'hero_b1_label', 'fx_home_hero', 'Przycisk 1 – tekst' );
	$add( 'hero_b1_url', 'fx_home_hero', 'Przycisk 1 – link', 'url' );
	$add( 'hero_b2_label', 'fx_home_hero', 'Przycisk 2 – tekst' );
	$add( 'hero_b2_url', 'fx_home_hero', 'Przycisk 2 – link', 'url' );

	/* Moto / Rower */
	$section( 'fx_home_worlds', 'Strona główna – Moto / Rower', 'Baner podzielony na Moto i Rower z przełącznikiem. Wybór klienta jest zapamiętywany i przestawia sekcje strony głównej (produkty, kafle „Odkrywaj”, poradniki). Ustawia się też sam, gdy klient ogląda kategorie moto albo rowerowe.' );
	$add( 'worlds_on', 'fx_home_worlds', 'Włącz baner Moto / Rower i przełącznik (zamiast pojedynczego banera)', 'checkbox' );
	$wp->add_setting( 'fx_world_default', array( 'default' => 'moto', 'sanitize_callback' => 'fx_sanitize_world' ) );
	$wp->add_control( 'fx_world_default', array( 'section' => 'fx_home_worlds', 'label' => 'Domyślnie (zanim klient wybierze)', 'type' => 'select', 'choices' => array( 'moto' => 'Moto', 'rower' => 'Rower' ) ) );
	foreach ( array( 'moto' => 'Moto', 'rower' => 'Rower' ) as $w => $wl ) {
		$add( "world_{$w}_img", 'fx_home_worlds', "{$wl} – zdjęcie (pionowe lub kwadratowe, min. 1400 px)", 'image' );
		$add( "world_{$w}_title", 'fx_home_worlds', "{$wl} – nagłówek" );
		$add( "world_{$w}_sub", 'fx_home_worlds', "{$wl} – podtytuł" );
		$add( "world_{$w}_btn", 'fx_home_worlds', "{$wl} – tekst przycisku" );
		$add( "world_{$w}_cat", 'fx_home_worlds', "{$wl} – kategoria główna", 'pcat' );
	}
	$add( 'new_split', 'fx_home_worlds', 'Sekcja produktów: osobne produkty dla Moto i Rower (z kategorii powyżej)', 'checkbox' );
	$add( 'guides_cat_moto', 'fx_home_worlds', 'Poradniki dla Moto – kategoria wpisów', 'cat' );
	$add( 'guides_cat_rower', 'fx_home_worlds', 'Poradniki dla Rower – kategoria wpisów', 'cat' );

	/* Odkrywaj */
	$section( 'fx_home_explore', 'Strona główna – Odkrywaj', 'Rząd pionowych kafli przewijany w bok. Puste kafle (bez nazwy) są pomijane.' );
	$add( 'explore_on', 'fx_home_explore', 'Pokaż sekcję', 'checkbox' );
	$add( 'explore_title', 'fx_home_explore', 'Tytuł sekcji' );
	$add( 'explore_count', 'fx_home_explore', 'Liczba kafli (1–20) – albo przyciski „+ Dodaj kafel” na dole', 'number', array( 'input_attrs' => array( 'min' => 1, 'max' => FX_EXPLORE_MAX ) ) );
	for ( $i = 1; $i <= FX_EXPLORE_MAX; $i++ ) {
		$on = fx_count_cb( 'explore_count', $i );
		$add( "explore_{$i}_img", 'fx_home_explore', "Kafel {$i} – zdjęcie (pionowe 5:7)", 'image', array( 'active_callback' => $on ) );
		$add( "explore_{$i}_label", 'fx_home_explore', "Kafel {$i} – nazwa", 'text', array( 'active_callback' => $on ) );
		$add( "explore_{$i}_url", 'fx_home_explore', "Kafel {$i} – link", 'url', array( 'active_callback' => $on ) );
		$add( "explore_{$i}_world", 'fx_home_explore', "Kafel {$i} – pokazuj dla", 'world', array( 'active_callback' => $on ) );
	}
	$wp->add_control( new FX_Count_Buttons_Control( $wp, 'fx_explore_add', array( 'section' => 'fx_home_explore', 'settings' => array(), 'count' => 'fx_explore_count', 'prefix' => 'fx_explore_', 'max' => FX_EXPLORE_MAX ) ) );

	/* Kafle */
	$section( 'fx_home_tiles', 'Strona główna – duże kafle', 'Linki w kaflu: jedna linia na przycisk, w formacie „Etykieta | adres”.' );
	$add( 'tiles_on', 'fx_home_tiles', 'Pokaż sekcję', 'checkbox' );
	$add( 'tiles_count', 'fx_home_tiles', 'Liczba kafli (1–8)', 'number', array( 'input_attrs' => array( 'min' => 1, 'max' => 8 ) ) );
	for ( $i = 1; $i <= 8; $i++ ) {
		$on = fx_count_cb( 'tiles_count', $i );
		$add( "tile_{$i}_img", 'fx_home_tiles', "Kafel {$i} – zdjęcie", 'image', array( 'active_callback' => $on ) );
		$add( "tile_{$i}_title", 'fx_home_tiles', "Kafel {$i} – tytuł", 'text', array( 'active_callback' => $on ) );
		$add( "tile_{$i}_links", 'fx_home_tiles', "Kafel {$i} – przyciski", 'textarea', array( 'active_callback' => $on ) );
		$add( "tile_{$i}_world", 'fx_home_tiles', "Kafel {$i} – pokazuj dla", 'world', array( 'active_callback' => $on ) );
	}

	/* Dyscypliny */
	$section( 'fx_home_disc', 'Strona główna – dyscypliny' );
	$add( 'disc_on', 'fx_home_disc', 'Pokaż sekcję (gdy baner Moto / Rower jest wyłączony)', 'checkbox' );
	$add( 'disc_title', 'fx_home_disc', 'Tytuł sekcji' );
	$add( 'disc_count', 'fx_home_disc', 'Liczba kafli (1–8)', 'number', array( 'input_attrs' => array( 'min' => 1, 'max' => 8 ) ) );
	for ( $i = 1; $i <= 8; $i++ ) {
		$on = fx_count_cb( 'disc_count', $i );
		$add( "disc_{$i}_img", 'fx_home_disc', "Kafel {$i} – zdjęcie (kwadrat)", 'image', array( 'active_callback' => $on ) );
		$add( "disc_{$i}_label", 'fx_home_disc', "Kafel {$i} – nazwa", 'text', array( 'active_callback' => $on ) );
		$add( "disc_{$i}_url", 'fx_home_disc', "Kafel {$i} – link", 'url', array( 'active_callback' => $on ) );
	}

	/* Nowości */
	$section( 'fx_home_new', 'Strona główna – produkty' );
	$add( 'new_on', 'fx_home_new', 'Pokaż sekcję', 'checkbox' );
	$add( 'new_title', 'fx_home_new', 'Tytuł sekcji' );
	$add( 'new_source', 'fx_home_new', 'Które produkty pokazać', 'source' );
	$add( 'new_tag', 'fx_home_new', 'Slug tagu (gdy wybrano „Produkty z tagiem”)' );
	$add( 'new_count', 'fx_home_new', 'Liczba produktów', 'number', array( 'input_attrs' => array( 'min' => 3, 'max' => 12, 'step' => 3 ) ) );
	$add( 'new_btn_label', 'fx_home_new', 'Przycisk – tekst' );
	$add( 'new_btn_url', 'fx_home_new', 'Przycisk – link', 'url' );

	/* Zawodnik */
	$section( 'fx_home_rider', 'Strona główna – zawodnik' );
	$add( 'rider_on', 'fx_home_rider', 'Pokaż sekcję', 'checkbox' );
	$add( 'rider_img', 'fx_home_rider', 'Zdjęcie', 'image' );
	$add( 'rider_kicker', 'fx_home_rider', 'Nadtytuł' );
	$add( 'rider_title', 'fx_home_rider', 'Tytuł' );
	$add( 'rider_text', 'fx_home_rider', 'Tekst', 'html' );
	$add( 'rider_list', 'fx_home_rider', 'Lista sprzętu („Element | Model”, jedna linia na pozycję)', 'textarea' );
	$add( 'rider_btn_label', 'fx_home_rider', 'Przycisk – tekst' );
	$add( 'rider_btn_url', 'fx_home_rider', 'Przycisk – link (puste = bez przycisku)', 'url' );

	/* Poradniki */
	$section( 'fx_home_guides', 'Strona główna – poradniki' );
	$add( 'guides_on', 'fx_home_guides', 'Pokaż sekcję', 'checkbox' );
	$add( 'guides_title', 'fx_home_guides', 'Tytuł sekcji' );
	$add( 'guides_count', 'fx_home_guides', 'Ile wpisów pokazać (1–9)', 'number' );
	$add( 'guides_cat', 'fx_home_guides', 'Kategoria wpisów', 'cat' );
	$add( 'guides_btn_url', 'fx_home_guides', 'Link „Wszystkie”', 'url' );

	/* SEO */
	$section( 'fx_home_seo', 'Strona główna – H1 i tekst SEO', 'Przenieś tu obecny nagłówek H1 i tekst SEO ze strony głównej. Wstaw znacznik <!--more--> w miejscu, od którego tekst ma być zwinięty pod „Czytaj więcej” (zostaje w kodzie strony).' );
	$add( 'seo_h1', 'fx_home_seo', 'Nagłówek H1 strony głównej' );
	$add( 'seo_text', 'fx_home_seo', 'Tekst SEO (HTML: akapity, H2, linki)', 'html' );

	/* Salon */
	$section( 'fx_home_store', 'Strona główna – sklep stacjonarny' );
	$add( 'store_on', 'fx_home_store', 'Pokaż sekcję', 'checkbox' );
	$add( 'store_map', 'fx_home_store', 'Animowana mapa dojazdu (z Rynku, lotniska, stadionu, A4)', 'checkbox' );
	$add( 'store_title', 'fx_home_store', 'Tytuł', 'textarea' );
	$add( 'store_text', 'fx_home_store', 'Tekst', 'html' );
	$add( 'store_btn_label', 'fx_home_store', 'Przycisk – tekst' );
	$add( 'store_btn_url', 'fx_home_store', 'Przycisk – link', 'url' );
}

function fx_sanitize_world( $v ) {
	return in_array( $v, array( 'all', 'moto', 'rower' ), true ) ? $v : 'all';
}

/** ID kategorii głównej świata (Moto / Rower): z ustawień, inaczej po adresie off-road / rowerowy. */
function fx_world_cat( $world ) {
	$id = (int) fx_mod( "world_{$world}_cat" );
	if ( $id ) {
		return $id;
	}
	foreach ( 'moto' === $world ? array( 'off-road', 'moto', 'motocross' ) : array( 'rowerowy', 'rower', 'mtb' ) as $slug ) {
		$t = get_term_by( 'slug', $slug, 'product_cat' );
		if ( $t ) {
			return (int) $t->term_id;
		}
	}
	return 0;
}

/** Pole kafla widoczne w Dostosuj tylko, gdy jego numer mieści się w ustawionej liczbie kafli. */
/** Ile kafli „Odkrywaj” można dodać. */
if ( ! defined( 'FX_EXPLORE_MAX' ) ) {
	define( 'FX_EXPLORE_MAX', 20 );
}

/**
 * Przyciski „+ Dodaj kafel” / „− Usuń ostatni” pod listą kafli w Dostosuj.
 * Zmieniają pole „Liczba kafli” i od razu pokazują / chowają pola kolejnego kafla.
 */
add_action( 'customize_register', function () {
	if ( class_exists( 'FX_Count_Buttons_Control' ) ) {
		return;
	}
	class FX_Count_Buttons_Control extends WP_Customize_Control {
		public $type   = 'fx_count_buttons';
		public $count  = '';
		public $prefix = '';
		public $max    = 20;
		public function render_content() {
			?>
			<div class="fx-count-btns" data-count="<?php echo esc_attr( $this->count ); ?>" data-prefix="<?php echo esc_attr( $this->prefix ); ?>" data-max="<?php echo (int) $this->max; ?>" style="display:flex;gap:8px;flex-wrap:wrap;padding-top:6px;border-top:1px solid #dcdcde">
				<button type="button" class="button button-primary" data-add>+ Dodaj kafel</button>
				<button type="button" class="button" data-del>− Usuń ostatni</button>
				<p class="description" style="flex-basis:100%;margin:4px 0 0">„Usuń ostatni” tylko chowa kafel ze strony – jego zdjęcie i link zostają zapamiętane.</p>
			</div>
			<?php
		}
	}
}, 1 );

add_action( 'customize_controls_print_footer_scripts', function () {
	?>
	<script>
	(function(api){api.bind('ready',function(){
		document.addEventListener('click',function(e){
			var b=e.target.closest('.fx-count-btns [data-add], .fx-count-btns [data-del]');if(!b)return;
			var box=b.closest('.fx-count-btns'),key=box.dataset.count,pre=box.dataset.prefix,max=+box.dataset.max,s=api(key);if(!s)return;
			var n=parseInt(s.get(),10)||0,m=b.hasAttribute('data-add')?Math.min(max,n+1):Math.max(1,n-1);if(m===n)return;
			s.set(m);
			api.control.each(function(c){var r=new RegExp('^'+pre+'(\\d+)_');var x=c.id.match(r);if(x){c.active.set(+x[1]<=m)}});
			if(m>n){var first=api.control(pre+m+'_img')||api.control(pre+m+'_label');if(first){setTimeout(function(){first.focus()},150)}}
		});
	})})(wp.customize);
	</script>
	<?php
} );

function fx_count_cb( $count_key, $i ) {
	return function () use ( $count_key, $i ) {
		return $i <= (int) fx_mod( $count_key );
	};
}
