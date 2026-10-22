<?php
/**
 * Integracja z WooCommerce – wersja 2.
 *
 * Zasada: motyw NIE nadpisuje szablonów WooCommerce. Listing, kategorie i karta
 * produktu korzystają ze standardowych szablonów, których oczekują wtyczki
 * (CommerceKit, YITH, GTM4WP i inne). Motyw dodaje swoje elementy wyłącznie przez
 * oficjalne haki WooCommerce, a wygląd przez CSS.
 *
 * Motyw nie zmienia adresów, tytułów, opisów ani danych strukturalnych.
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

/* ---------- Opakowanie treści sklepu ---------- */
remove_action( 'woocommerce_before_main_content', 'woocommerce_output_content_wrapper', 10 );
remove_action( 'woocommerce_after_main_content', 'woocommerce_output_content_wrapper_end', 10 );
remove_action( 'woocommerce_sidebar', 'woocommerce_get_sidebar', 10 );

add_action( 'woocommerce_before_main_content', function () {
	echo '<div class="fx-wrap fx-shop">';
}, 10 );
add_action( 'woocommerce_after_main_content', function () {
	echo '</div>';
}, 50 );

/* Okruszki z Rank Math / Yoast, jeśli włączone (te same dane strukturalne co dziś). */
remove_action( 'woocommerce_before_main_content', 'woocommerce_breadcrumb', 20 );
add_action( 'woocommerce_before_main_content', 'fx_breadcrumb', 20 );

/* ---------- Liczba kolumn i produktów ---------- */
add_filter( 'loop_shop_columns', function () {
	return 3;
}, 99 );

/*
 * Produktów na stronę: tyle samo co w starym motywie (wiersze × kolumny z ustawień WooCommerce),
 * mimo że nowy układ ma 3 kolumny – dzięki temu /page/2/, /page/3/ pokazują to samo co dotąd.
 */
add_filter( 'loop_shop_per_page', function () {
	$n = function_exists( 'fx_tool' ) ? (int) fx_tool( 'per_page' ) : 0;
	if ( $n > 0 ) {
		return $n;
	}
	return max( 1, (int) get_option( 'woocommerce_catalog_rows', 4 ) ) * max( 1, (int) get_option( 'woocommerce_catalog_columns', 4 ) );
}, 20 );

/*
 * Strona listy poza zakresem (np. /page/9/, gdy produktów jest mniej niż kiedyś): 301 do ostatniej
 * istniejącej strony zamiast 404 – Google ma te adresy w indeksie.
 */
add_action( 'template_redirect', function () {
	if ( ! is_404() || ! get_query_var( 'paged' ) ) {
		return;
	}
	global $wp_query;
	$path = (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH ); // phpcs:ignore
	$base = preg_replace( '#/page/\d+/?$#', '/', $path );
	if ( $base === $path || 0 !== strpos( $base, '/' ) ) {
		return;
	}
	$max    = isset( $wp_query->max_num_pages ) ? (int) $wp_query->max_num_pages : 0;
	$target = home_url( $base . ( $max > 1 ? 'page/' . $max . '/' : '' ) );
	wp_safe_redirect( $target, 301 );
	exit;
}, 5 );

/*
 * Dane produktu dla Google (Product, cena, dostępność). WooCommerce dodaje je w podsumowaniu produktu;
 * gdy coś je zablokuje (np. wtyczka poprzedniego motywu), dodajemy je sami – bez podwójnego wpisu.
 */
add_action( 'woocommerce_after_single_product_summary', function () {
	global $product;
	if ( ! $product instanceof WC_Product || ! function_exists( 'WC' ) || empty( WC()->structured_data ) ) {
		return;
	}
	$sd = WC()->structured_data;
	foreach ( (array) $sd->get_data() as $item ) {
		if ( isset( $item['@type'] ) && 'Product' === $item['@type'] ) {
			return;
		}
	}
	$sd->generate_product_data( $product );
}, 999 );

/* Losowe „Podobne produkty” WooCommerce zastępuje sekcja „Z tej serii” (inc/modules/series.php). */
remove_action( 'woocommerce_after_single_product_summary', 'woocommerce_output_related_products', 20 );

add_filter( 'woocommerce_upsell_display_args', function ( $args ) {
	$args['posts_per_page'] = 3;
	$args['columns']        = 3;
	return $args;
}, 99 );

/* ---------- Licznik koszyka w nagłówku ---------- */
function fx_cart_count() {
	$n = ( function_exists( 'WC' ) && WC()->cart ) ? WC()->cart->get_cart_contents_count() : 0;
	echo '<span class="fx-cart-count" data-count="' . (int) $n . '">' . ( $n ? (int) $n : '' ) . '</span>';
}

add_filter( 'woocommerce_add_to_cart_fragments', function ( $fragments ) {
	ob_start();
	fx_cart_count();
	$fragments['span.fx-cart-count'] = ob_get_clean();
	return $fragments;
} );

/* =====================================================================
 * KATEGORIE: wstęp nad produktami, pełny opis pod produktami
 * ===================================================================== */

/* Standardowy opis kategorii zastępujemy wstępem (reszta trafia pod listing). */
remove_action( 'woocommerce_archive_description', 'woocommerce_taxonomy_archive_description', 10 );
add_action( 'woocommerce_archive_description', 'fx_archive_intro', 10 );

function fx_archive_intro() {
	if ( ! fx_is_term_archive() || get_query_var( 'paged' ) > 1 ) {
		return;
	}
	list( $top, $bot ) = fx_term_descriptions( get_queried_object() );
	if ( '' === trim( wp_strip_all_tags( $top ) ) ) {
		return;
	}
	echo '<div class="term-description fx-cat-intro"><div class="fx-cat-intro__text fx-prose">' . fx_rich( $top ) . '</div>'; // phpcs:ignore
	echo '<div class="fx-cat-intro__more">';
	if ( '' !== trim( wp_strip_all_tags( $bot ) ) ) {
		echo '<a href="#fx-opis">Czytaj pełny opis</a>';
	}
	echo '</div></div>';
}

/* Pełny opis pod listingiem – na końcu treści sklepu, przed zamknięciem opakowania. */
add_action( 'woocommerce_after_main_content', 'fx_archive_bottom', 40 );
function fx_archive_bottom() {
	if ( ! fx_is_term_archive() || get_query_var( 'paged' ) > 1 ) {
		return;
	}
	list( , $bot ) = fx_term_descriptions( get_queried_object() );
	if ( '' === trim( wp_strip_all_tags( $bot ) ) ) {
		return;
	}
	echo '<section id="fx-opis" class="fx-cat-desc"><div class="fx-cat-desc__body fx-prose">' . fx_rich( $bot ) . '</div></section>'; // phpcs:ignore
}

function fx_is_term_archive() {
	return ( is_product_category() || is_product_tag() || is_product_taxonomy() ) && get_queried_object() instanceof WP_Term;
}

/**
 * Zwraca [górny, dolny] opis kategorii.
 *
 * Górny: standardowe pole „Opis”. Dolny, w kolejności:
 *   1. pole motywu „Opis pod produktami” (fx_desc_bottom),
 *   2. tekst po <!--more--> w standardowym opisie,
 *   3. pole dolnego opisu zapisane przez poprzedni motyw lub wtyczkę (wykrywane automatycznie),
 * Gdy dolnego opisu brak, cały opis zostaje nad produktami – tak jak w starym motywie
 * (bez przenoszenia części tekstu pod listę; dzielenie można włączyć filtrem fx_split_top_desc).
 */
function fx_term_descriptions( $term ) {
	if ( ! $term instanceof WP_Term ) {
		return array( '', '' );
	}
	$parts = explode( '<!--more-->', (string) $term->description, 2 );
	$top   = $parts[0];

	$bot = get_term_meta( $term->term_id, 'fx_desc_bottom', true );
	if ( ! is_string( $bot ) || '' === trim( $bot ) ) {
		$bot = isset( $parts[1] ) ? $parts[1] : '';
	}
	if ( '' === trim( wp_strip_all_tags( $bot ) ) ) {
		$bot = fx_legacy_bottom_desc( $term->term_id );
	}
	if ( '' === trim( wp_strip_all_tags( $bot ) ) && apply_filters( 'fx_split_top_desc', false, $term ) ) {
		list( $top, $bot ) = fx_split_long_html( $top );
	}
	return array( $top, $bot );
}

function fx_legacy_bottom_desc( $term_id ) {
	$keys = apply_filters( 'fx_bottom_desc_keys', array() );
	$meta = get_term_meta( $term_id );
	if ( ! is_array( $meta ) ) {
		return '';
	}
	if ( ! $keys ) {
		foreach ( array_keys( $meta ) as $k ) {
			if ( 'fx_desc_bottom' !== $k && preg_match( '/(below|bottom|second|seo_desc|desc_2|lower)/i', $k ) ) {
				$keys[] = $k;
			}
		}
	}
	foreach ( $keys as $k ) {
		if ( empty( $meta[ $k ][0] ) ) {
			continue;
		}
		$val = maybe_unserialize( $meta[ $k ][0] );
		if ( is_string( $val ) && strlen( wp_strip_all_tags( $val ) ) > 40 ) {
			return $val;
		}
	}
	return '';
}

/**
 * Dzieli długi HTML na wstęp (do pierwszego akapitu z ~150+ znakami) i resztę.
 * Kolejność tekstu bez zmian. Krótkie opisy (<500 znaków) zostają w całości nad produktami.
 */
function fx_split_long_html( $html ) {
	if ( mb_strlen( trim( wp_strip_all_tags( $html ) ) ) < 500 ) {
		return array( $html, '' );
	}
	$html  = wpautop( $html );
	$parts = preg_split( '/(<\/(?:p|h[1-6]|ul|ol|div|blockquote)>)/i', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
	$intro = '';
	$i     = 0;
	$n     = count( $parts );
	while ( $i < $n ) {
		$intro  .= $parts[ $i ] . ( isset( $parts[ $i + 1 ] ) ? $parts[ $i + 1 ] : '' );
		$closing = isset( $parts[ $i + 1 ] ) ? strtolower( $parts[ $i + 1 ] ) : '';
		$i      += 2;
		$len     = mb_strlen( trim( wp_strip_all_tags( $intro ) ) );
		if ( ( '</p>' === $closing && $len >= 150 ) || $len > 700 ) {
			break;
		}
	}
	$rest = implode( '', array_slice( $parts, $i ) );
	if ( '' === trim( wp_strip_all_tags( $rest ) ) ) {
		return array( $html, '' );
	}
	return array( $intro, $rest );
}

/* Pole „Opis pod produktami” w edycji kategorii. */
add_action( 'product_cat_edit_form_fields', function ( $term ) {
	$val    = get_term_meta( $term->term_id, 'fx_desc_bottom', true );
	$legacy = $val ? '' : fx_legacy_bottom_desc( $term->term_id );
	?>
	<tr class="form-field">
		<th scope="row"><label for="fx_desc_bottom">Opis pod produktami (motyw FOX)</label></th>
		<td>
			<?php
			wp_editor(
				is_string( $val ) ? $val : '',
				'fx_desc_bottom',
				array(
					'textarea_name' => 'fx_desc_bottom',
					'textarea_rows' => 10,
				)
			);
			?>
			<p class="description">
				Tekst pod listą produktów (z nagłówkami H2/H3).
				<?php if ( $legacy ) : ?>
					<strong>Motyw pokazuje teraz dolny opis zapisany przez poprzedni motyw – nie musisz go przepisywać.</strong> Wpis tutaj zastąpi tamten tekst.
				<?php else : ?>
					Gdy to pole jest puste, a opis kategorii długi, motyw sam zostawi nad produktami pierwszy akapit, a resztę pokaże pod nimi.
				<?php endif; ?>
			</p>
		</td>
	</tr>
	<?php
}, 20 );

add_action( 'edited_product_cat', function ( $term_id ) {
	if ( ! current_user_can( 'manage_product_terms' ) || ! isset( $_POST['fx_desc_bottom'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	$val = wp_kses_post( wp_unslash( $_POST['fx_desc_bottom'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
	if ( '' === trim( $val ) ) {
		delete_term_meta( $term_id, 'fx_desc_bottom' );
	} else {
		update_term_meta( $term_id, 'fx_desc_bottom', $val );
	}
} );

/* ---------- Podkategorie jako pigułki nad listingiem ---------- */
add_action( 'woocommerce_before_shop_loop', 'fx_subcategory_chips', 15 );
function fx_subcategory_chips() {
	if ( ! is_product_category() ) {
		return;
	}
	$term     = get_queried_object();
	$children = get_terms( array( 'taxonomy' => 'product_cat', 'parent' => $term->term_id, 'hide_empty' => true ) );
	$parent   = '';
	if ( empty( $children ) || is_wp_error( $children ) ) {
		if ( ! $term->parent ) {
			return;
		}
		$children = get_terms( array( 'taxonomy' => 'product_cat', 'parent' => $term->parent, 'hide_empty' => true ) );
		$parent   = get_term_link( (int) $term->parent, 'product_cat' );
	}
	if ( empty( $children ) || is_wp_error( $children ) ) {
		return;
	}
	// Bez kategorii, w których klient nic nie zobaczy (same wyprzedane / ukryte produkty).
	$children = array_values( array_filter( $children, function ( $c ) use ( $term ) {
		return $c->term_id === $term->term_id || fx_cat_has_visible( $c->term_id );
	} ) );
	if ( count( $children ) < 1 ) {
		return;
	}
	echo '<nav class="fx-chips" aria-label="Podkategorie">';
	echo '<a class="fx-chip' . ( $parent ? '' : ' is-active' ) . '" href="' . esc_url( $parent ? $parent : get_term_link( $term ) ) . '">Wszystkie</a>';
	foreach ( $children as $c ) {
		$active = ( $c->term_id === $term->term_id ) ? ' is-active' : '';
		echo '<a class="fx-chip' . esc_attr( $active ) . '" href="' . esc_url( get_term_link( $c ) ) . '">' . esc_html( $c->name ) . '</a>';
	}
	echo '</nav>';
}

/* =====================================================================
 * KARTA PRODUKTU: krótki opis przy cenie, pełny opis w akordeonie
 * ===================================================================== */

/* Zakładki zastępuje akordeon w kolumnie z ceną (treść ta sama, w HTML). */
remove_action( 'woocommerce_after_single_product_summary', 'woocommerce_output_product_data_tabs', 10 );
add_action( 'woocommerce_single_product_summary', 'fx_size_help', 31 );
add_action( 'woocommerce_single_product_summary', 'fx_trust_box', 35 );
/* Opis i szczegóły pod częścią zakupową, na całą szerokość (przed kompletem i podobnymi produktami). */
add_action( 'woocommerce_after_single_product_summary', 'fx_product_accordion', 4 );

function fx_size_help() {
	global $product;
	if ( ! $product || ! $product->is_type( 'variable' ) || ! fx_mod( 'size_help' ) ) {
		return;
	}
	echo '<div class="fx-size-help">' . wp_kses_post( fx_mod( 'size_help' ) ) . '</div>';
}

function fx_trust_box() {
	$rows = array_filter(
		array(
			array( 'truck', fx_mod( 'trust_1' ) ),
			array( 'pin', fx_mod( 'trust_2' ) ),
			array( 'return', fx_mod( 'trust_3' ) ),
		),
		function ( $r ) {
			return '' !== $r[1];
		}
	);
	if ( ! $rows ) {
		return;
	}
	echo '<div class="fx-trust">';
	foreach ( $rows as $r ) {
		echo '<div>' . fx_icon( $r[0], 20 ) . '<span>' . esc_html( $r[1] ) . '</span></div>'; // phpcs:ignore
	}
	echo '</div>';
}

function fx_product_accordion() {
	global $product, $post;
	if ( ! $product ) {
		return;
	}
	echo '<section class="fx-pdesc" id="opis">';

	// Lewa kolumna: pełny opis produktu, zawsze rozwinięty.
	if ( $post && '' !== trim( $post->post_content ) ) {
		// Opis zawsze w całości, bez zwijania („Czytaj cały opis” usunięte na życzenie sklepu).
		echo '<div class="fx-pdesc__main"><h2 class="fx-pdesc__title">Opis produktu</h2><div class="fx-pdesc__body fx-content fx-prose">';
		echo fx_clean_html( apply_filters( 'the_content', get_the_content() ) ); // phpcs:ignore
		echo '</div></div>';
	}

	// Prawa kolumna: szczegóły w akordeonie.
	echo '<div class="fx-pdesc__side"><div class="fx-acc">';

	$tech = get_post_meta( $product->get_id(), '_fx_tech', true );
	if ( $tech ) {
		echo '<details><summary>Technologia i materiały</summary><div class="fx-acc__body">' . fx_rich( $tech ) . '</div></details>'; // phpcs:ignore
	}

	if ( $product->has_attributes() || $product->has_weight() || $product->has_dimensions() || fx_hidden_info_attrs( $product ) ) {
		echo '<details><summary>Informacje dodatkowe</summary><div class="fx-acc__body">';
		wc_display_product_attributes( $product );
		echo '</div></details>';
	}

	if ( fx_mod( 'delivery_text' ) ) {
		echo '<details><summary>Dostawa i zwroty</summary><div class="fx-acc__body">' . fx_rich( fx_mod( 'delivery_text' ) ) . '</div></details>'; // phpcs:ignore
	}

	if ( comments_open() && 'yes' === get_option( 'woocommerce_enable_reviews' ) ) {
		echo '<details id="reviews-acc"><summary>Opinie (' . (int) $product->get_review_count() . ')</summary><div class="fx-acc__body">';
		comments_template();
		echo '</div></details>';
	}

	echo '</div></div></section>';
}

/* Pole produktu: „Technologia i materiały”. */
add_action( 'woocommerce_product_options_general_product_data', function () {
	echo '<div class="options_group">';
	woocommerce_wp_textarea_input(
		array(
			'id'          => '_fx_tech',
			'label'       => 'Technologia i materiały',
			'description' => 'Osobna sekcja akordeonu na karcie produktu. Nie powtarzaj zdań z opisu.',
			'desc_tip'    => true,
			'rows'        => 4,
		)
	);
	echo '</div>';
} );

add_action( 'woocommerce_admin_process_product_object', function ( $product ) {
	foreach ( array( '_fx_tech' ) as $key ) {
		if ( isset( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$product->update_meta_data( $key, wp_kses_post( wp_unslash( $_POST[ $key ] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		}
	}
} );

/* Moduł „Zbuduj komplet” – pod kolumnami zdjęć i ceny, przed produktami powiązanymi. */
add_action( 'woocommerce_after_single_product_summary', function () {
	global $product;
	if ( function_exists( 'fx_kit_section' ) && ! fx_kit_is_page( $product ) ) {
		fx_kit_section( $product );
	}
}, 2 ); // zaraz pod zdjęciami i ceną – przed opisem (długie opisy spychały komplet daleko w dół)

/* Krótki opis – te same porządki co w opisie (jeden H1, bez wklejonych czcionek). */
add_filter( 'woocommerce_short_description', 'fx_clean_html', 20 );

/* =====================================================================
 * GALERIA PRODUKTU: wszystkie zdjęcia w pełnym rozmiarze (bez slidera)
 * ===================================================================== */
add_filter( 'woocommerce_gallery_image_size', function () {
	return 'woocommerce_single';
} );
add_filter( 'woocommerce_single_product_image_gallery_classes', function ( $classes ) {
	$classes[] = 'fx-gallery';
	return $classes;
} );

/* Zdjęcia + kolumna zakupowa w jednym bloku – kolumna przykleja się tylko na wysokości zdjęć,
   a po ostatnim zdjęciu cała sekcja jedzie dalej (opis, zestawy, seria). */
add_action( 'woocommerce_before_single_product_summary', function () {
	echo '<div class="fx-pmain">';
}, 1 );
add_action( 'woocommerce_after_single_product_summary', function () {
	echo '</div>';
}, 1 );

/* =====================================================================
 * KARTA PRODUKTU: „Wróć do kategorii” nad okruszkami
 * Domyślnie kategoria główna produktu (fx_main_category: dział OFF ROAD / ROWEROWY…, bez Prezentów), inaczej najgłębsza.
 * Skrypt podmienia link na listę, z której klient przyszedł (z filtrami i miejscem na liście).
 * ===================================================================== */
function fx_product_back_term( $product_id ) {
	// Ta sama kategoria co w okruszkach (dział OFF ROAD / ROWEROWY…, bez Prezentów i Wyprzedaży).
	if ( function_exists( 'fx_main_category' ) ) {
		$main = fx_main_category( $product_id );
		if ( $main ) {
			return $main;
		}
	}
	if ( function_exists( 'yoast_get_primary_term_id' ) ) {
		$pid = (int) yoast_get_primary_term_id( 'product_cat', $product_id );
		if ( $pid ) {
			$t = get_term( $pid, 'product_cat' );
			if ( $t instanceof WP_Term ) {
				return $t;
			}
		}
	}
	$terms = get_the_terms( $product_id, 'product_cat' );
	if ( ! $terms || is_wp_error( $terms ) ) {
		return null;
	}
	$best = null;
	$depth = -1;
	foreach ( $terms as $t ) {
		$d = count( get_ancestors( $t->term_id, 'product_cat' ) );
		if ( $d > $depth ) {
			$best  = $t;
			$depth = $d;
		}
	}
	return $best;
}

add_action( 'woocommerce_before_main_content', function () {
	if ( ! is_product() ) {
		return;
	}
	$t = fx_product_back_term( get_queried_object_id() );
	if ( ! $t ) {
		return;
	}
	echo '<a class="fx-back" data-fx-back href="' . esc_url( get_term_link( $t ) ) . '"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg><span>Wróć do: <strong>' . esc_html( $t->name ) . '</strong></span></a>';
}, 19 );

/* Nazwy produktów na kartach jako H3 (zamiast H2 WooCommerce): na stronie głównej i w kategoriach
   nagłówki H2 zostają dla sekcji, a dziesiątki nazw produktów nie rozmywają struktury strony. */
remove_action( 'woocommerce_shop_loop_item_title', 'woocommerce_template_loop_product_title', 10 );
add_action( 'woocommerce_shop_loop_item_title', function () {
	echo '<h3 class="' . esc_attr( apply_filters( 'woocommerce_product_loop_title_classes', 'woocommerce-loop-product__title' ) ) . '">' . get_the_title() . '</h3>'; // phpcs:ignore
}, 10 );

/* Atrybut z BaseLinkera „wariant” (pa_blvariant) wyświetlamy klientom jako „Rozmiar”. W panelu zostaje oryginał. */
add_filter( 'woocommerce_attribute_label', function ( $label, $name ) {
	if ( is_admin() && ! wp_doing_ajax() ) {
		return $label;
	}
	if ( preg_match( '/blvariant/i', (string) $name ) || preg_match( '/^\s*wariant\s*$/iu', (string) $label ) ) {
		return 'Rozmiar';
	}
	return $label;
}, 20, 2 );

/* ---------- Odświeżanie podręcznych danych motywu (filtry, rodziny kolorów, komplety, kask+gogle) ----------
 * Przy imporcie z BaseLinkera i pracy wtyczek w tle produkty zapisują się setkami. Zamiast czyścić
 * pamięć podręczną po KAŻDYM zapisie (i budować ją od nowa przy każdej wizycie), zbieramy zmiany
 * i czyścimy raz, 90 s po pierwszej z nich. Ręczny zapis w panelu – od razu, jak dotąd. */
function fx_cache_touch() {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done   = true;
	$manual = is_admin() && ! wp_doing_ajax() && ! wp_doing_cron() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) && ! ( defined( 'WP_CLI' ) && WP_CLI );
	if ( $manual ) {
		add_action( 'shutdown', 'fx_flush_caches' );
		return;
	}
	if ( ! wp_next_scheduled( 'fx_flush_caches' ) ) {
		wp_schedule_single_event( time() + 90, 'fx_flush_caches' );
	}
}
function fx_flush_caches() {
	if ( function_exists( 'fx_cf_flush' ) ) {
		fx_cf_flush(); // rodziny kolorów + listy filtrów
	} elseif ( function_exists( 'fx_filters_flush' ) ) {
		fx_filters_flush();
	}
	delete_transient( 'fx_kit_index_v3' );
	delete_transient( 'fx_hg_pool2' );
}
add_action( 'fx_flush_caches', 'fx_flush_caches' );

/* =====================================================================
 * „Informacje dodatkowe”: kolor zawsze widoczny
 * Import (BaseLinker) i ręczne dodawanie często zostawiają przy atrybucie „Kolor” odznaczone
 * „Widoczny na stronie produktu” – klient nie widział koloru, choć produkt go ma (i filtr go używa).
 * ===================================================================== */
function fx_hidden_info_attrs( $product ) {
	$out = array();
	if ( ! $product instanceof WC_Product ) {
		return $out;
	}
	foreach ( (array) apply_filters( 'fx_always_visible_attrs', array( 'pa_kolor' ) ) as $tax ) {
		$attrs = $product->get_attributes();
		if ( isset( $attrs[ $tax ] ) && $attrs[ $tax ] instanceof WC_Product_Attribute && ! $attrs[ $tax ]->get_visible() && ! $attrs[ $tax ]->get_variation() ) {
			$names = wc_get_product_terms( $product->get_id(), $tax, array( 'fields' => 'names' ) );
			if ( $names && ! is_wp_error( $names ) ) {
				$out[ $tax ] = $names;
			}
		}
	}
	return $out;
}
add_filter( 'woocommerce_display_product_attributes', function ( $rows, $product ) {
	foreach ( fx_hidden_info_attrs( $product ) as $tax => $names ) {
		$rows[ 'attribute_' . $tax ] = array(
			'label' => wc_attribute_label( $tax ),
			'value' => esc_html( implode( ', ', $names ) ),
		);
	}
	return $rows;
}, 20, 2 );

/**
 * Czy w kategorii (z podkategoriami) jest choć jeden produkt widoczny na liście
 * (opublikowany, nieukryty w katalogu, a gdy sklep ukrywa niedostępne – na stanie).
 * Wynik dla wszystkich kategorii w jednym zapisie, odświeżany z pamięcią motywu (fx_flush_caches).
 */
function fx_cat_has_visible( $term_id ) {
	static $map = null;
	if ( null === $map ) {
		$map = get_transient( 'fx_cat_vis' );
		$map = is_array( $map ) ? $map : array();
	}
	$term_id = (int) $term_id;
	if ( ! isset( $map[ $term_id ] ) ) {
		$tq = array( array( 'taxonomy' => 'product_cat', 'terms' => $term_id, 'include_children' => true ) );
		if ( function_exists( 'fx_filter_visible_tax' ) ) {
			$tq = fx_filter_visible_tax( $tq );
		}
		$ids              = get_posts( array( 'post_type' => 'product', 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids', 'no_found_rows' => true, 'tax_query' => $tq ) ); // phpcs:ignore
		$map[ $term_id ]  = $ids ? 1 : 0;
		set_transient( 'fx_cat_vis', $map, 6 * HOUR_IN_SECONDS );
	}
	return (bool) $map[ $term_id ];
}
add_action( 'fx_flush_caches', function () {
	delete_transient( 'fx_cat_vis' );
}, 20 );
foreach ( array( 'created_product_cat', 'edited_product_cat', 'delete_product_cat' ) as $fx_hook ) {
	add_action( $fx_hook, function () {
		delete_transient( 'fx_cat_vis' );
	} );
}
