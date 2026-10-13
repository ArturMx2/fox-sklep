<?php
/**
 * Przyciski wariantów.
 *
 * Wszystkie atrybuty wariantów pokazują się jako przyciski zamiast listy rozwijanej.
 * Atrybuty z ustawionym kolorem (Produkty → Atrybuty → np. Kolor → edycja wartości → „Kolor próbki”)
 * pokazują się jako kółka w danym kolorze. Lista rozwijana Woo zostaje ukryta w kodzie,
 * więc logika wariantów WooCommerce działa bez zmian.
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

/* ---------- Pole koloru przy wartościach atrybutów ---------- */
add_action( 'admin_init', function () {
	if ( ! function_exists( 'wc_get_attribute_taxonomy_names' ) ) {
		return;
	}
	foreach ( wc_get_attribute_taxonomy_names() as $tax ) {
		add_action( "{$tax}_add_form_fields", 'fx_swatch_add_field' );
		add_action( "{$tax}_edit_form_fields", 'fx_swatch_edit_field' );
		add_action( "created_{$tax}", 'fx_swatch_save' );
		add_action( "edited_{$tax}", 'fx_swatch_save' );
	}
} );

function fx_swatch_add_field() {
	echo '<div class="form-field"><label for="fx_color">Kolor próbki</label><input type="text" name="fx_color" id="fx_color" placeholder="#000000 albo #000000,#ff0000"><p>Opcjonalnie. Jeden kolor lub dwa po przecinku (dwukolorowe kółko). Puste = przycisk z nazwą.</p></div>';
}

function fx_swatch_edit_field( $term ) {
	$val = get_term_meta( $term->term_id, 'fx_color', true );
	echo '<tr class="form-field"><th scope="row"><label for="fx_color">Kolor próbki</label></th><td><input type="text" name="fx_color" id="fx_color" value="' . esc_attr( (string) $val ) . '" placeholder="#000000 albo #000000,#ff0000"><p class="description">Jeden kolor lub dwa po przecinku. Puste = przycisk z nazwą.</p></td></tr>';
}

function fx_swatch_save( $term_id ) {
	if ( ! isset( $_POST['fx_color'] ) || ! current_user_can( 'manage_product_terms' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	$colors = array_filter( array_map( 'sanitize_hex_color', array_map( 'trim', explode( ',', sanitize_text_field( wp_unslash( $_POST['fx_color'] ) ) ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
	if ( $colors ) {
		update_term_meta( $term_id, 'fx_color', implode( ',', array_slice( $colors, 0, 2 ) ) );
	} else {
		delete_term_meta( $term_id, 'fx_color' );
	}
}

/* ---------- Dane kolorów dla skryptu na karcie produktu ---------- */
add_action( 'wp_enqueue_scripts', function () {
	if ( ! is_product() ) {
		return;
	}
	$product = wc_get_product( get_queried_object_id() );
	if ( ! $product || ! $product->is_type( 'variable' ) ) {
		return;
	}
	$map = array();
	foreach ( $product->get_variation_attributes() as $tax => $values ) {
		if ( ! taxonomy_exists( $tax ) ) {
			continue;
		}
		foreach ( wc_get_product_terms( $product->get_id(), $tax, array( 'fields' => 'all' ) ) as $t ) {
			$c = get_term_meta( $t->term_id, 'fx_color', true );
			if ( $c ) {
				$map[ 'attribute_' . sanitize_title( $tax ) ][ $t->slug ] = explode( ',', $c );
			}
		}
	}
	wp_localize_script( 'fx-theme', 'fxSwatches', array( 'colors' => (object) $map ) );
}, 20 );
