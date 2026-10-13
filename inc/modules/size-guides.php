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

add_action( 'init', function () {
	register_post_type(
		'fx_size_guide',
		array(
			'labels'       => array(
				'name'          => 'Tabele rozmiarów',
				'singular_name' => 'Tabela rozmiarów',
				'add_new_item'  => 'Dodaj tabelę rozmiarów',
				'edit_item'     => 'Edytuj tabelę rozmiarów',
				'menu_name'     => 'Tabele rozmiarów',
			),
			'public'       => false,
			'show_ui'      => true,
			'show_in_menu' => 'edit.php?post_type=product',
			'show_in_rest' => true,
			'supports'     => array( 'title', 'editor', 'revisions' ),
		)
	);
} );

/* ---------- Przypisanie do kategorii ---------- */
add_action( 'add_meta_boxes_fx_size_guide', function () {
	add_meta_box( 'fx_sg_cats', 'Kategorie produktów', 'fx_sg_cats_box', 'fx_size_guide', 'side' );
} );

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

	$map = get_transient( 'fx_sg_map' );
	if ( false === $map ) {
		$map = array();
		foreach ( get_posts( array( 'post_type' => 'fx_size_guide', 'numberposts' => 500, 'fields' => 'ids' ) ) as $gid ) {
			foreach ( (array) get_post_meta( $gid, '_fx_cats', true ) as $cid ) {
				$map[ (int) $cid ] = (int) $gid;
			}
		}
		set_transient( 'fx_sg_map', $map, DAY_IN_SECONDS );
	}
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
