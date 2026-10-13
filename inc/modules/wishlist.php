<?php
/**
 * Ulubione.
 *
 * Gość: lista w przeglądarce (localStorage). Zalogowany klient: lista na koncie
 * (user meta), łączona z listą z przeglądarki po zalogowaniu.
 * Strona „Ulubione” tworzy się sama przy włączeniu motywu (shortcode [fx_wishlist]).
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

/* ---------- Serduszko na kartach (poza linkiem karty) i na produkcie ---------- */
add_action( 'woocommerce_before_shop_loop_item', function () {
	global $product;
	echo fx_wishlist_button( $product ); // phpcs:ignore
}, 5 );

add_action( 'woocommerce_after_add_to_cart_button', function () {
	global $product;
	echo fx_wishlist_button( $product, true ); // phpcs:ignore
} );

function fx_wishlist_button( $product, $with_label = false ) {
	if ( ! $product instanceof WC_Product ) {
		return '';
	}
	$id    = $product->get_id();
	$label = 'Dodaj do ulubionych: ' . $product->get_name();
	return '<button type="button" class="fx-wish' . ( $with_label ? ' fx-wish--label' : '' ) . '" data-fx-wish="' . (int) $id . '" aria-pressed="false" aria-label="' . esc_attr( $label ) . '">'
		. '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M12 20s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.6-7 10-7 10z"/></svg>'
		. ( $with_label ? '<span>Ulubione</span>' : '' ) . '</button>';
}

/* ---------- Strona „Ulubione” ---------- */
function fx_wishlist_page_id() {
	$id = (int) get_option( 'fx_wishlist_page' );
	return ( $id && 'publish' === get_post_status( $id ) ) ? $id : 0;
}

add_action( 'after_switch_theme', 'fx_wishlist_ensure_page' );
add_action( 'admin_init', function () {
	if ( ! fx_wishlist_page_id() && current_user_can( 'publish_pages' ) ) {
		fx_wishlist_ensure_page();
	}
} );
function fx_wishlist_ensure_page() {
	if ( fx_wishlist_page_id() ) {
		return;
	}
	$existing = get_page_by_path( 'ulubione' );
	if ( $existing ) {
		update_option( 'fx_wishlist_page', $existing->ID );
		return;
	}
	$id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Ulubione',
			'post_name'    => 'ulubione',
			'post_content' => '[fx_wishlist]',
		)
	);
	if ( $id && ! is_wp_error( $id ) ) {
		update_option( 'fx_wishlist_page', $id );
	}
}

/* Strona ulubionych nie powinna trafiać do Google. */
add_filter( 'wp_robots', function ( $robots ) {
	if ( fx_wishlist_page_id() && is_page( fx_wishlist_page_id() ) ) {
		$robots['noindex'] = true;
	}
	return $robots;
} );

add_shortcode( 'fx_wishlist', function () {
	return '<div class="fx-wishlist" data-fx-wishlist><p class="fx-wishlist__empty">Ładuję ulubione…</p></div>';
} );

/* ---------- Dane dla skryptu ---------- */
add_action( 'wp_enqueue_scripts', function () {
	$saved = is_user_logged_in() ? fx_wishlist_get( get_current_user_id() ) : array();
	wp_localize_script(
		'fx-theme',
		'fxWish',
		array(
			'url'      => WC_AJAX::get_endpoint( '%%endpoint%%' ),
			'loggedIn' => is_user_logged_in(),
			'saved'    => $saved,
			'page'     => fx_wishlist_page_id() ? get_permalink( fx_wishlist_page_id() ) : '',
			'nonce'    => is_user_logged_in() ? wp_create_nonce( 'fx_wish' ) : '',
		)
	);
}, 20 );

function fx_wishlist_get( $user_id ) {
	$ids = get_user_meta( $user_id, '_fx_wishlist', true );
	return is_array( $ids ) ? array_values( array_map( 'absint', $ids ) ) : array();
}

/* Zapis listy zalogowanego klienta (cała lista naraz – prosto i odpornie). */
add_action( 'wc_ajax_fx_wish_save', function () {
	if ( ! is_user_logged_in() || ! check_ajax_referer( 'fx_wish', 'nonce', false ) ) {
		wp_send_json_error( null, 403 );
	}
	$ids = isset( $_POST['ids'] ) ? json_decode( wp_unslash( $_POST['ids'] ), true ) : array(); // phpcs:ignore
	$ids = array_slice( array_values( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) ), 0, 200 );
	update_user_meta( get_current_user_id(), '_fx_wishlist', $ids );
	wp_send_json_success( $ids );
} );

/* Karty produktów do strony „Ulubione” (standardowy listing WooCommerce). */
add_action( 'wc_ajax_fx_wish_render', function () {
	$ids = isset( $_POST['ids'] ) ? json_decode( wp_unslash( $_POST['ids'] ), true ) : array(); // phpcs:ignore
	$ids = array_slice( array_filter( array_map( 'absint', (array) $ids ) ), 0, 100 );
	if ( ! $ids ) {
		wp_send_json_success( array( 'html' => '' ) );
	}
	$html = do_shortcode( '[products ids="' . implode( ',', $ids ) . '" orderby="post__in" columns="3" limit="100"]' );
	wp_send_json_success( array( 'html' => $html ) );
} );
