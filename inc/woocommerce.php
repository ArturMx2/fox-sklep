<?php
/**
 * Integracja z WooCommerce.
 *
 * Zasada: motyw nie zmienia adresów, tytułów, opisów ani danych strukturalnych.
 * Wyświetla istniejące treści w nowym układzie.
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

/* ---------- Domyślne opakowania i paski boczne Woo: wyłączamy, układ robią szablony ---------- */
remove_action( 'woocommerce_before_main_content', 'woocommerce_output_content_wrapper', 10 );
remove_action( 'woocommerce_after_main_content', 'woocommerce_output_content_wrapper_end', 10 );
remove_action( 'woocommerce_before_main_content', 'woocommerce_breadcrumb', 20 );
remove_action( 'woocommerce_sidebar', 'woocommerce_get_sidebar', 10 );

/* Opis kategorii wyświetla szablon archive-product.php (nad i pod listingiem). */
remove_action( 'woocommerce_archive_description', 'woocommerce_taxonomy_archive_description', 10 );
remove_action( 'woocommerce_archive_description', 'woocommerce_product_archive_description', 10 );

/* Liczba wyników i sortowanie są w pasku narzędzi szablonu. */
remove_action( 'woocommerce_before_shop_loop', 'woocommerce_result_count', 20 );
remove_action( 'woocommerce_before_shop_loop', 'woocommerce_catalog_ordering', 30 );

/* Karta produktu: zakładki zastępuje akordeon w kolumnie z ceną. */
remove_action( 'woocommerce_after_single_product_summary', 'woocommerce_output_product_data_tabs', 10 );
remove_action( 'woocommerce_before_single_product_summary', 'woocommerce_show_product_sale_flash', 10 );
remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_sharing', 50 );

add_action( 'woocommerce_single_product_summary', 'fx_size_help', 31 );
add_action( 'woocommerce_single_product_summary', 'fx_trust_box', 35 );
add_action( 'woocommerce_single_product_summary', 'fx_product_accordion', 45 );

/* Własne style Woo są w style.css – zostawiamy tylko układ bazowy. */
add_filter( 'woocommerce_enqueue_styles', function ( $styles ) {
	unset( $styles['woocommerce-smallscreen'] );
	return $styles;
} );

add_filter( 'woocommerce_output_related_products_args', function ( $args ) {
	$args['posts_per_page'] = 3;
	$args['columns']        = 3;
	return $args;
} );

add_filter( 'woocommerce_upsell_display_args', function ( $args ) {
	$args['posts_per_page'] = 3;
	$args['columns']        = 3;
	return $args;
} );

/* ---------- Licznik koszyka (odświeżany przez fragmenty Woo) ---------- */
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

/* ---------- Opis kategorii: górny i dolny ---------- */

/**
 * Zwraca [górny, dolny] opis kategorii.
 *
 * Górny: standardowe pole „Opis” kategorii (do znacznika <!--more-->, jeśli jest).
 * Dolny, w kolejności:
 *   1. pole motywu „Opis pod produktami” (fx_desc_bottom),
 *   2. tekst po <!--more--> w standardowym opisie,
 *   3. pole dolnego opisu zapisane przez poprzedni motyw lub wtyczkę
 *      (wykrywane automatycznie – klucze z „below”/„bottom”/„second” w nazwie),
 *      lista kluczy do nadpisania filtrem fx_bottom_desc_keys.
 */
function fx_term_descriptions( $term ) {
	if ( ! $term || is_wp_error( $term ) ) {
		return array( '', '' );
	}
	$raw   = (string) $term->description;
	$parts = explode( '<!--more-->', $raw, 2 );
	$top   = $parts[0];
	$bot   = '';

	$own = get_term_meta( $term->term_id, 'fx_desc_bottom', true );
	if ( is_string( $own ) && '' !== trim( $own ) ) {
		$bot = $own;
	} elseif ( isset( $parts[1] ) && '' !== trim( $parts[1] ) ) {
		$bot = $parts[1];
	} else {
		$bot = fx_legacy_bottom_desc( $term->term_id );
	}

	// Brak osobnego opisu dolnego, a górny jest długi: nad produktami zostaje wstęp,
	// reszta idzie pod listing. Cały tekst nadal jest na tej samej stronie.
	if ( '' === trim( wp_strip_all_tags( $bot ) ) ) {
		list( $top, $bot ) = fx_split_long_html( $top );
	}
	return array( $top, $bot );
}

/**
 * Dzieli długi HTML na wstęp (pierwszy akapit z treścią, ok. 150+ znaków) i resztę.
 * Krótkie opisy (do ~500 znaków) zostają w całości nad produktami.
 */
function fx_split_long_html( $html ) {
	$plain = trim( wp_strip_all_tags( $html ) );
	if ( mb_strlen( $plain ) < 500 ) {
		return array( $html, '' );
	}
	$html = wpautop( $html );
	// Bloki kończące się na zamknięciu akapitu, nagłówka lub listy.
	$parts = preg_split( '/(<\/(?:p|h[1-6]|ul|ol|div|blockquote)>)/i', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
	$intro = '';
	$i     = 0;
	$n     = count( $parts );
	while ( $i < $n ) {
		$intro .= $parts[ $i ];
		if ( isset( $parts[ $i + 1 ] ) ) {
			$intro .= $parts[ $i + 1 ];
		}
		$closing = isset( $parts[ $i + 1 ] ) ? strtolower( $parts[ $i + 1 ] ) : '';
		$i      += 2;
		$text    = trim( wp_strip_all_tags( $intro ) );
		// Wstęp kończymy na akapicie (nie na nagłówku), gdy ma już trochę treści.
		if ( '</p>' === $closing && mb_strlen( $text ) >= 150 ) {
			break;
		}
		if ( mb_strlen( $text ) > 700 ) {
			break;
		}
	}
	$rest = implode( '', array_slice( $parts, $i ) );
	if ( '' === trim( wp_strip_all_tags( $rest ) ) ) {
		return array( $html, '' );
	}
	// Kolejność tekstu zostaje bez zmian: wstęp (z ewentualnym nagłówkiem) nad produktami, dalsza część pod nimi.
	return array( $intro, $rest );
}

function fx_legacy_bottom_desc( $term_id ) {
	$keys = apply_filters( 'fx_bottom_desc_keys', array() );
	$meta = get_term_meta( $term_id );
	if ( ! is_array( $meta ) ) {
		return '';
	}
	if ( ! $keys ) {
		foreach ( array_keys( $meta ) as $k ) {
			if ( preg_match( '/(below|bottom|second|seo_desc|desc_2|lower)/i', $k ) ) {
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
				$val,
				'fx_desc_bottom',
				array(
					'textarea_name' => 'fx_desc_bottom',
					'textarea_rows' => 10,
					'media_buttons' => true,
				)
			);
			?>
			<p class="description">
				Tekst wyświetlany pod listą produktów (z nagłówkami H2/H3).
				<?php if ( $legacy ) : ?>
					<strong>Teraz motyw pokazuje dolny opis zapisany przez poprzedni motyw – nie musisz go przepisywać.</strong> Jeśli coś tu wpiszesz, zastąpi tamten tekst.
				<?php else : ?>
					Możesz też w standardowym opisie wstawić znacznik &lt;!--more--&gt; – tekst po nim trafi pod produkty.
				<?php endif; ?>
			</p>
		</td>
	</tr>
	<tr class="form-field">
		<th scope="row"><label for="fx_promo">Kafel promocyjny w listingu</label></th>
		<td>
			<?php $promo = get_term_meta( $term->term_id, 'fx_promo', true ); ?>
			<textarea name="fx_promo" id="fx_promo" rows="4" class="large-text" placeholder="Tytuł&#10;Tekst przycisku | adres linku&#10;adres zdjęcia"><?php echo esc_textarea( is_string( $promo ) ? $promo : '' ); ?></textarea>
			<p class="description">Duży kafel po 2. produkcie na pierwszej stronie kategorii. Linia 1: tytuł, linia 2: „Tekst przycisku | link”, linia 3: adres zdjęcia z biblioteki mediów. Puste = brak kafla.</p>
		</td>
	</tr>
	<?php
}, 20 );

add_action( 'edited_product_cat', function ( $term_id ) {
	if ( ! current_user_can( 'manage_product_terms' ) ) {
		return;
	}
	// phpcs:disable WordPress.Security.NonceVerification -- formularz edycji terminu ma własny nonce WordPressa.
	if ( isset( $_POST['fx_desc_bottom'] ) ) {
		$val = wp_kses_post( wp_unslash( $_POST['fx_desc_bottom'] ) );
		if ( '' === trim( $val ) ) {
			delete_term_meta( $term_id, 'fx_desc_bottom' );
		} else {
			update_term_meta( $term_id, 'fx_desc_bottom', $val );
		}
	}
	if ( isset( $_POST['fx_promo'] ) ) {
		update_term_meta( $term_id, 'fx_promo', sanitize_textarea_field( wp_unslash( $_POST['fx_promo'] ) ) );
	}
	// phpcs:enable
} );

/* Kafel promocyjny wstawiany po 2. produkcie (strona 1 kategorii). */
add_action( 'woocommerce_shop_loop', function () {
	static $seen = 0;
	if ( ! is_product_category() || get_query_var( 'paged' ) > 1 || 'products' === wc_get_loop_prop( 'name' ) ) {
		return;
	}
	$seen++;
	if ( 3 !== $seen ) { // Hak działa przed każdym produktem – wstawiamy przed trzecim.
		return;
	}
	$term  = get_queried_object();
	$promo = get_term_meta( $term->term_id, 'fx_promo', true );
	if ( ! is_string( $promo ) || '' === trim( $promo ) ) {
		return;
	}
	$lines = preg_split( '/\r\n|\r|\n/', trim( $promo ) );
	$title = isset( $lines[0] ) ? $lines[0] : '';
	$btn   = isset( $lines[1] ) ? fx_lines( $lines[1] ) : array();
	$img   = isset( $lines[2] ) ? trim( $lines[2] ) : '';
	echo '<li class="fx-promo-tile fx-shade">';
	if ( $img ) {
		echo '<img class="fx-cover" src="' . esc_url( $img ) . '" alt="" loading="lazy">';
	} else {
		echo '<span class="fx-cover fx-ph" aria-hidden="true"></span>';
	}
	echo '<div class="fx-tile__body"><div class="fx-tile__title">' . esc_html( $title ) . '</div>';
	if ( $btn && $btn[0][1] ) {
		echo '<div class="fx-tile__pills"><a class="fx-pill" href="' . esc_url( $btn[0][1] ) . '">' . esc_html( $btn[0][0] ) . '</a></div>';
	}
	echo '</div></li>';
}, 5 );

/* ---------- Podkategorie jako pigułki ---------- */
function fx_subcategory_chips() {
	if ( ! is_product_category() ) {
		return;
	}
	$term     = get_queried_object();
	$children = get_terms(
		array(
			'taxonomy'   => 'product_cat',
			'parent'     => $term->term_id,
			'hide_empty' => true,
		)
	);
	$parent_link = '';
	if ( empty( $children ) || is_wp_error( $children ) ) {
		if ( ! $term->parent ) {
			return;
		}
		$children    = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'parent'     => $term->parent,
				'hide_empty' => true,
			)
		);
		$parent_link = get_term_link( (int) $term->parent, 'product_cat' );
	}
	if ( empty( $children ) || is_wp_error( $children ) ) {
		return;
	}
	$all = $parent_link ? $parent_link : get_term_link( $term );
	echo '<a class="fx-chip' . ( $parent_link ? '' : ' is-active' ) . '" href="' . esc_url( $all ) . '">Wszystkie</a>';
	foreach ( $children as $c ) {
		$active = ( $c->term_id === $term->term_id ) ? ' is-active' : '';
		echo '<a class="fx-chip' . esc_attr( $active ) . '" href="' . esc_url( get_term_link( $c ) ) . '">' . esc_html( $c->name ) . '</a>';
	}
}

/* ---------- Karta produktu: elementy kolumny z ceną ---------- */
function fx_size_help() {
	global $product;
	if ( ! $product || ! $product->is_type( 'variable' ) ) {
		return;
	}
	$help = fx_mod( 'size_help' );
	$url  = fx_mod( 'size_chart_url' );
	echo '<div class="fx-size-help">';
	if ( $url ) {
		echo '<a href="' . esc_url( $url ) . '" style="text-decoration:underline;font-weight:500">Tabela rozmiarów</a> · ';
	}
	echo wp_kses_post( $help ) . '</div>';
}

function fx_trust_box() {
	$rows = array(
		array( 'truck', fx_mod( 'trust_1' ) ),
		array( 'pin', fx_mod( 'trust_2' ) ),
		array( 'return', fx_mod( 'trust_3' ) ),
	);
	$rows = array_filter( $rows, function ( $r ) {
		return '' !== $r[1];
	} );
	if ( ! $rows ) {
		return;
	}
	echo '<div class="fx-trust">';
	foreach ( $rows as $r ) {
		echo '<div>' . fx_icon( $r[0], 20 ) . '<span>' . esc_html( $r[1] ) . '</span></div>'; // phpcs:ignore
	}
	echo '</div>';
}

/**
 * Akordeon: pełny opis produktu (w HTML, pierwsza sekcja otwarta),
 * informacje dodatkowe, rozmiar i krój, dostawa, opinie.
 */
function fx_product_accordion() {
	global $product, $post;
	if ( ! $product ) {
		return;
	}
	echo '<div class="fx-acc">';

	$content = $post ? $post->post_content : '';
	if ( '' !== trim( $content ) ) {
		echo '<details open><summary>Opis produktu</summary><div class="fx-acc__body">';
		the_content();
		echo '</div></details>';
	}

	$tech = get_post_meta( $product->get_id(), '_fx_tech', true );
	if ( $tech ) {
		echo '<details><summary>Technologia i materiały</summary><div class="fx-acc__body">' . fx_rich( $tech ) . '</div></details>'; // phpcs:ignore
	}

	if ( $product->has_attributes() || $product->has_weight() || $product->has_dimensions() ) {
		echo '<details><summary>Informacje dodatkowe</summary><div class="fx-acc__body">';
		wc_display_product_attributes( $product );
		echo '</div></details>';
	}

	$fit = get_post_meta( $product->get_id(), '_fx_fit', true );
	if ( $fit || fx_mod( 'size_chart_url' ) ) {
		echo '<details><summary>Rozmiar i krój</summary><div class="fx-acc__body">';
		if ( $fit ) {
			echo fx_rich( $fit ); // phpcs:ignore
		}
		if ( fx_mod( 'size_chart_url' ) ) {
			echo '<p><a href="' . esc_url( fx_mod( 'size_chart_url' ) ) . '">Pełna tabela rozmiarów</a></p>';
		}
		echo '</div></details>';
	}

	if ( fx_mod( 'delivery_text' ) ) {
		echo '<details><summary>Dostawa i zwroty</summary><div class="fx-acc__body">' . fx_rich( fx_mod( 'delivery_text' ) ) . '</div></details>'; // phpcs:ignore
	}

	if ( comments_open() && 'yes' === get_option( 'woocommerce_enable_reviews' ) ) {
		$count = $product->get_review_count();
		echo '<details id="reviews-acc"><summary>Opinie (' . (int) $count . ')</summary><div class="fx-acc__body">';
		comments_template();
		echo '</div></details>';
	}

	echo '</div>';
}

/* Pola produktu: „Technologia i materiały” oraz „Rozmiar i krój”. */
add_action( 'woocommerce_product_options_general_product_data', function () {
	echo '<div class="options_group">';
	woocommerce_wp_textarea_input(
		array(
			'id'          => '_fx_tech',
			'label'       => 'Technologia i materiały',
			'description' => 'Osobna sekcja akordeonu na karcie produktu. Nie powtarzaj tu zdań z opisu – Google lepiej ocenia unikalny tekst.',
			'desc_tip'    => true,
			'rows'        => 4,
		)
	);
	woocommerce_wp_textarea_input(
		array(
			'id'          => '_fx_fit',
			'label'       => 'Rozmiar i krój',
			'description' => 'Np. „Model ma 182 cm i nosi M. Krój sportowy, pod buzer weź rozmiar większy.”',
			'desc_tip'    => true,
			'rows'        => 3,
		)
	);
	echo '</div>';
} );

add_action( 'woocommerce_admin_process_product_object', function ( $product ) {
	// phpcs:disable WordPress.Security.NonceVerification -- WooCommerce weryfikuje nonce zapisu produktu.
	foreach ( array( '_fx_tech', '_fx_fit' ) as $key ) {
		if ( isset( $_POST[ $key ] ) ) {
			$product->update_meta_data( $key, wp_kses_post( wp_unslash( $_POST[ $key ] ) ) );
		}
	}
	// phpcs:enable
} );

/* Czysta karta w listingu (zdjęcie, nazwa, cena) – bez przycisku „Dodaj do koszyka”. */
remove_action( 'woocommerce_after_shop_loop_item', 'woocommerce_template_loop_add_to_cart', 10 );
/*
 * Kartę rysuje content-product.php. Z haków listingu zdejmujemy domyślne elementy Woo
 * (link, plakietka „Promocja!”, zdjęcie, tytuł, ocena, cena) – haki zostają dla wtyczek
 * (ulubione, etykiety, GTM), które dzięki temu trafiają do wnętrza karty.
 */
add_action( 'init', function () {
	remove_action( 'woocommerce_before_shop_loop_item', 'woocommerce_template_loop_product_link_open', 10 );
	remove_action( 'woocommerce_after_shop_loop_item', 'woocommerce_template_loop_product_link_close', 5 );
	remove_action( 'woocommerce_before_shop_loop_item_title', 'woocommerce_show_product_loop_sale_flash', 10 );
	remove_action( 'woocommerce_before_shop_loop_item_title', 'woocommerce_template_loop_product_thumbnail', 10 );
	remove_action( 'woocommerce_shop_loop_item_title', 'woocommerce_template_loop_product_title', 10 );
	remove_action( 'woocommerce_after_shop_loop_item_title', 'woocommerce_template_loop_rating', 5 );
	remove_action( 'woocommerce_after_shop_loop_item_title', 'woocommerce_template_loop_price', 10 );
}, 20 );

/* 3 produkty w rzędzie (2 na telefonie – reguluje CSS). */
add_filter( 'loop_shop_columns', function () {
	return 3;
}, 20 );

/**
 * Plakietka na karcie produktu.
 */
function fx_card_badge( $product ) {
	if ( ! $product->is_in_stock() ) {
		return '<span class="fx-card__badge">Wyprzedane</span>';
	}
	if ( $product->is_on_sale() ) {
		return '<span class="fx-card__badge fx-card__badge--sale">Promocja</span>';
	}
	$created = $product->get_date_created();
	if ( $created && ( time() - $created->getTimestamp() ) < 30 * DAY_IN_SECONDS ) {
		return '<span class="fx-card__badge">Nowość</span>';
	}
	return '';
}
