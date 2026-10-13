<?php
/**
 * Tabele rozmiarów.
 *
 * Produkty → Tabele rozmiarów: każda tabela to wpis z tytułem i treścią (tabela, zdjęcie,
 * instrukcja pomiaru). Przypisanie: do kategorii (pole w tabeli) albo do konkretnego
 * produktu (pole w produkcie, ma pierwszeństwo). Na karcie produktu pojawia się
 * przycisk „Tabela rozmiarów” otwierający okno z tabelą.
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

/*
 * Tabele mają też własne strony pod starymi adresami CommerceKit (/ckit_size_guide/nazwa/) –
 * Google ma je w indeksie i przynoszą ruch. Gdy CommerceKit jest jeszcze włączony, adresy
 * obsługuje on, a nasze tabele zostają tylko w panelu (bez konfliktu).
 */
function fx_sg_public() {
	return ! post_type_exists( 'ckit_size_guide' );
}

add_action( 'init', function () {
	$public = fx_sg_public();
	register_post_type(
		'fx_size_guide',
		array(
			'labels'              => array(
				'name'          => 'Tabele rozmiarów',
				'singular_name' => 'Tabela rozmiarów',
				'add_new_item'  => 'Dodaj tabelę rozmiarów',
				'edit_item'     => 'Edytuj tabelę rozmiarów',
				'menu_name'     => 'Tabele rozmiarów',
			),
			'public'              => $public,
			'publicly_queryable'  => $public,
			'exclude_from_search' => true,
			'show_in_nav_menus'   => false,
			'has_archive'         => false,
			'rewrite'             => $public ? array( 'slug' => 'ckit_size_guide', 'with_front' => false ) : false,
			'show_ui'             => true,
			'show_in_menu'        => 'edit.php?post_type=product',
			'show_in_rest'        => true,
			'supports'            => array( 'title', 'editor', 'revisions' ),
		)
	);
}, 20 );

/* Po zmianie (np. wyłączeniu CommerceKit albo aktualizacji motywu) odświeżamy adresy raz. */
add_action( 'init', function () {
	$sig = FX_VERSION . '|' . ( fx_sg_public() ? 'pub' : 'priv' );
	if ( get_option( 'fx_sg_rewrite' ) !== $sig ) {
		flush_rewrite_rules( false );
		update_option( 'fx_sg_rewrite', $sig, true );
	}
}, 99 );

/* Strona tabeli: zwykły układ strony (tytuł + treść), bez daty i kategorii wpisu. */
add_filter( 'single_template', function ( $tpl ) {
	if ( is_singular( 'fx_size_guide' ) ) {
		$page = locate_template( 'page.php' );
		return $page ? $page : $tpl;
	}
	return $tpl;
} );

/*
 * Stary adres tabeli, której nie ma w motywie (nie zaimportowana / usunięta):
 * 301 do strony „Tabela rozmiarów” zamiast błędu 404.
 */
add_action( 'template_redirect', function () {
	if ( ! is_404() || ! fx_sg_public() ) {
		return;
	}
	$path = (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH ); // phpcs:ignore
	if ( 0 !== strpos( $path, '/ckit_size_guide/' ) ) {
		return;
	}
	$page = get_page_by_path( 'tabela-rozmiarow' );
	wp_safe_redirect( $page ? get_permalink( $page ) : home_url( '/' ), 301 );
	exit;
} );

/* ---------- Przypisanie do kategorii ---------- */
add_action( 'add_meta_boxes_fx_size_guide', function () {
	add_meta_box( 'fx_sg_cats', 'Kategorie produktów', 'fx_sg_cats_box', 'fx_size_guide', 'side' );
	add_meta_box( 'fx_sg_products', 'Konkretne produkty', 'fx_sg_products_box', 'fx_size_guide', 'normal', 'high' );
} );

/* Wyszukiwarka produktów WooCommerce na ekranie tabeli rozmiarów. */
add_action( 'admin_enqueue_scripts', function () {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( $screen && 'fx_size_guide' === $screen->post_type ) {
		wp_enqueue_script( 'wc-enhanced-select' );
		wp_enqueue_style( 'woocommerce_admin_styles' );
	}
} );

function fx_sg_products_box( $post ) {
	$ids = array_filter( array_map( 'absint', (array) get_post_meta( $post->ID, '_fx_products', true ) ) );
	echo '<p class="description">Tabela pokaże się przy tych produktach niezależnie od kategorii (ma pierwszeństwo przed tabelą kategorii).</p>';
	echo '<select class="wc-product-search" multiple="multiple" style="width:100%" name="fx_products[]" data-placeholder="Wyszukaj produkt…" data-action="woocommerce_json_search_products">';
	foreach ( $ids as $id ) {
		$p = wc_get_product( $id );
		if ( $p ) {
			echo '<option value="' . (int) $id . '" selected="selected">' . esc_html( wp_strip_all_tags( $p->get_formatted_name() ) ) . '</option>';
		}
	}
	echo '</select>';
}

function fx_sg_cats_box( $post ) {
	wp_nonce_field( 'fx_sg_save', 'fx_sg_nonce' );
	$sel   = array_map( 'intval', (array) get_post_meta( $post->ID, '_fx_cats', true ) );
	$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
	echo '<p class="description">Tabela pokaże się przy produktach z tych kategorii (i ich podkategorii).</p>';
	echo '<div style="max-height:320px;overflow:auto">';
	foreach ( fx_sg_sort_terms( $terms ) as $t ) {
		echo '<label style="display:block;margin:3px 0 3px ' . (int) ( $t->fx_depth * 14 ) . 'px"><input type="checkbox" name="fx_cats[]" value="' . (int) $t->term_id . '" ' . checked( in_array( $t->term_id, $sel, true ), true, false ) . '> ' . esc_html( $t->name ) . '</label>';
	}
	echo '</div>';
}

function fx_sg_sort_terms( $terms, $parent = 0, $depth = 0 ) {
	$out = array();
	if ( is_wp_error( $terms ) ) {
		return $out;
	}
	foreach ( $terms as $t ) {
		if ( (int) $t->parent === $parent ) {
			$t->fx_depth = $depth;
			$out[]       = $t;
			$out         = array_merge( $out, fx_sg_sort_terms( $terms, (int) $t->term_id, $depth + 1 ) );
		}
	}
	return $out;
}

add_action( 'save_post_fx_size_guide', function ( $post_id ) {
	if ( ! isset( $_POST['fx_sg_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['fx_sg_nonce'] ) ), 'fx_sg_save' ) || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	$cats = isset( $_POST['fx_cats'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['fx_cats'] ) ) : array();
	update_post_meta( $post_id, '_fx_cats', $cats );
	$prods = isset( $_POST['fx_products'] ) ? array_values( array_filter( array_map( 'absint', (array) wp_unslash( $_POST['fx_products'] ) ) ) ) : array();
	update_post_meta( $post_id, '_fx_products', $prods );
	delete_transient( 'fx_sg_map' );
} );

/* ---------- Przypisanie do produktu ---------- */
add_action( 'woocommerce_product_options_general_product_data', function () {
	global $post;
	$guides  = get_posts( array( 'post_type' => 'fx_size_guide', 'numberposts' => 200, 'orderby' => 'title', 'order' => 'ASC' ) );
	$options = array(
		''   => 'Automatycznie (z kategorii)',
		'-1' => 'Brak tabeli',
	);
	foreach ( $guides as $g ) {
		$options[ $g->ID ] = $g->post_title;
	}
	echo '<div class="options_group">';
	woocommerce_wp_select(
		array(
			'id'      => '_fx_size_guide',
			'label'   => 'Tabela rozmiarów',
			'options' => $options,
			'value'   => get_post_meta( $post->ID, '_fx_size_guide', true ),
		)
	);
	echo '</div>';
} );

add_action( 'woocommerce_admin_process_product_object', function ( $product ) {
	if ( isset( $_POST['_fx_size_guide'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		$product->update_meta_data( '_fx_size_guide', sanitize_text_field( wp_unslash( $_POST['_fx_size_guide'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
	}
} );

/**
 * Tabela dla produktu: ustawienie produktu, inaczej najbliższa kategoria (z przodkami).
 */
function fx_size_guide_for( $product ) {
	if ( ! $product instanceof WC_Product ) {
		return 0;
	}
	$own = get_post_meta( $product->get_id(), '_fx_size_guide', true );
	if ( '-1' === (string) $own ) {
		return 0;
	}
	if ( $own && 'publish' === get_post_status( (int) $own ) ) {
		return (int) $own;
	}

	$all = get_transient( 'fx_sg_map' );
	if ( ! is_array( $all ) || ! isset( $all['cats'] ) ) {
		$all = array( 'cats' => array(), 'products' => array() );
		foreach ( get_posts( array( 'post_type' => 'fx_size_guide', 'numberposts' => 500, 'fields' => 'ids' ) ) as $gid ) {
			foreach ( (array) get_post_meta( $gid, '_fx_cats', true ) as $cid ) {
				$all['cats'][ (int) $cid ] = (int) $gid;
			}
			foreach ( (array) get_post_meta( $gid, '_fx_products', true ) as $pid ) {
				$all['products'][ (int) $pid ] = (int) $gid;
			}
		}
		set_transient( 'fx_sg_map', $all, DAY_IN_SECONDS );
	}
	if ( isset( $all['products'][ $product->get_id() ] ) ) {
		return $all['products'][ $product->get_id() ];
	}
	$map = $all['cats'];
	if ( ! $map ) {
		return 0;
	}
	// Najpierw bezpośrednie kategorie produktu, potem ich przodkowie (od najbliższego).
	$cats = $product->get_category_ids();
	foreach ( $cats as $cid ) {
		if ( isset( $map[ $cid ] ) ) {
			return $map[ $cid ];
		}
	}
	foreach ( $cats as $cid ) {
		foreach ( get_ancestors( $cid, 'product_cat', 'taxonomy' ) as $aid ) {
			if ( isset( $map[ $aid ] ) ) {
				return $map[ $aid ];
			}
		}
	}
	return 0;
}

add_action( 'deleted_post', function () {
	delete_transient( 'fx_sg_map' );
} );

/* ---------- Karta produktu: przycisk i okno ---------- */
add_action( 'woocommerce_single_product_summary', 'fx_size_guide_button', 25 );
function fx_size_guide_button() {
	global $product;
	$gid = fx_size_guide_for( $product );
	if ( $gid ) {
		echo '<button type="button" class="fx-sg-open" data-fx-dialog="fx-size-guide"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><rect x="2" y="7" width="20" height="10" rx="1"/><path d="M6 7v4M10 7v3M14 7v4M18 7v3"/></svg>Tabela rozmiarów</button>';
	} elseif ( fx_mod( 'size_chart_url' ) && $product && $product->is_type( 'variable' ) ) {
		echo '<a class="fx-sg-open" href="' . esc_url( fx_mod( 'size_chart_url' ) ) . '">Tabela rozmiarów</a>';
	}
}

add_action( 'wp_footer', function () {
	if ( ! is_product() ) {
		return;
	}
	$product = wc_get_product( get_queried_object_id() );
	$gid     = fx_size_guide_for( $product );
	if ( ! $gid ) {
		return;
	}
	$guide = get_post( $gid );
	echo '<dialog id="fx-size-guide" class="fx-dialog" aria-labelledby="fx-sg-title"><div class="fx-dialog__head"><h2 id="fx-sg-title">' . esc_html( get_the_title( $guide ) ) . '</h2><button type="button" class="fx-icon-btn" data-fx-dialog-close aria-label="Zamknij">' . fx_icon( 'close' ) . '</button></div><div class="fx-dialog__body fx-content">' . apply_filters( 'the_content', $guide->post_content ) . '</div></dialog>'; // phpcs:ignore
} );
