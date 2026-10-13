<?php
/**
 * Walidacja rozmiaru kasku.
 *
 * Gdy w koszyku jest produkt z kategorii kasków (ustawienia: Narzędzia sklepu),
 * klient musi podać obwód głowy w cm. Jeśli ustawione są zakresy rozmiarów
 * (np. „M | 57-58”), obwód jest porównywany z wybranym rozmiarem kasku –
 * przy niezgodności zamówienie wymaga potwierdzenia rozmiaru.
 * Obwód zapisuje się w zamówieniu: w panelu, w e-mailach i na stronie podziękowania.
 *
 * Działa z klasycznym zamówieniem [woocommerce_checkout] i z blokiem zamówienia.
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

/* ---------- Pomocnicze ---------- */
function fx_helmet_cat_ids() {
	static $ids = null;
	if ( null !== $ids ) {
		return $ids;
	}
	$ids = array();
	foreach ( array_filter( array_map( 'trim', explode( ',', (string) fx_tool( 'helmet_cats' ) ) ) ) as $slug ) {
		$t = get_term_by( 'slug', $slug, 'product_cat' );
		if ( $t ) {
			$ids[] = (int) $t->term_id;
			$ids   = array_merge( $ids, array_map( 'intval', get_term_children( $t->term_id, 'product_cat' ) ) );
		}
	}
	return $ids = array_unique( $ids );
}

function fx_helmet_ranges() {
	$out = array();
	foreach ( fx_lines( fx_tool( 'helmet_ranges' ) ) as $r ) {
		if ( preg_match( '/(\d+(?:[.,]\d+)?)\s*[-–]\s*(\d+(?:[.,]\d+)?)/', $r[1], $m ) ) {
			$out[ mb_strtolower( trim( $r[0] ) ) ] = array( (float) str_replace( ',', '.', $m[1] ), (float) str_replace( ',', '.', $m[2] ) );
		}
	}
	return $out;
}

/**
 * Kaski w koszyku: [ [nazwa, rozmiar], ... ].
 */
function fx_cart_helmets() {
	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		return array();
	}
	$cats = fx_helmet_cat_ids();
	if ( ! $cats ) {
		return array();
	}
	$out = array();
	foreach ( WC()->cart->get_cart() as $item ) {
		$pid = (int) $item['product_id'];
		if ( ! array_intersect( wc_get_product_cat_ids( $pid ), $cats ) ) {
			continue;
		}
		$size = '';
		if ( ! empty( $item['variation'] ) ) {
			foreach ( $item['variation'] as $attr => $value ) {
				$tax   = str_replace( 'attribute_', '', $attr );
				$label = taxonomy_exists( $tax ) && ( $t = get_term_by( 'slug', $value, $tax ) ) ? $t->name : $value; // phpcs:ignore
				if ( '' === $size || preg_match( '/(rozmiar|size)/i', $tax ) ) {
					$size = $label;
				}
			}
		}
		$out[] = array( $item['data']->get_name(), $size );
	}
	return $out;
}

/**
 * Sprawdza obwód. Zwraca komunikat błędu albo ''.
 */
function fx_helmet_check( $cm, $confirmed ) {
	$helmets = fx_cart_helmets();
	if ( ! $helmets ) {
		return '';
	}
	$cm = (float) str_replace( ',', '.', (string) $cm );
	if ( $cm < 40 || $cm > 70 ) {
		return 'W koszyku jest kask – podaj obwód głowy w centymetrach (np. 57).';
	}
	$ranges = fx_helmet_ranges();
	if ( ! $ranges || $confirmed ) {
		return '';
	}
	foreach ( $helmets as $h ) {
		$key = mb_strtolower( $h[1] );
		if ( isset( $ranges[ $key ] ) && ( $cm < $ranges[ $key ][0] || $cm > $ranges[ $key ][1] ) ) {
			return sprintf(
				'Obwód %s cm nie pasuje do rozmiaru %s (%s–%s cm) kasku „%s”. Zmień rozmiar w koszyku albo zaznacz potwierdzenie rozmiaru.',
				wc_format_localized_decimal( $cm ),
				$h[1],
				wc_format_localized_decimal( $ranges[ $key ][0] ),
				wc_format_localized_decimal( $ranges[ $key ][1] ),
				$h[0]
			);
		}
	}
	return '';
}

/* ---------- Koszyk: informacja ---------- */
add_action( 'woocommerce_before_cart_table', function () {
	if ( fx_cart_helmets() ) {
		echo '<div class="woocommerce-info fx-helmet-info">W koszyku jest kask. Przy zamówieniu podasz obwód głowy – sprawdzimy, czy rozmiar pasuje.</div>';
	}
} );

/* ---------- Klasyczne zamówienie ---------- */
add_filter( 'woocommerce_checkout_fields', function ( $fields ) {
	if ( ! fx_cart_helmets() ) {
		return $fields;
	}
	$fields['order'] = array(
		'fx_head_cm' => array(
			'type'              => 'number',
			'label'             => 'Obwód głowy (cm)',
			'description'       => 'Zmierz centymetrem krawieckim nad brwiami i uszami. Potrzebny do sprawdzenia rozmiaru kasku.',
			'required'          => true,
			'priority'          => 1,
			'class'             => array( 'form-row-wide' ),
			'custom_attributes' => array( 'min' => 40, 'max' => 70, 'step' => '0.5', 'inputmode' => 'decimal' ),
		),
	) + ( fx_helmet_ranges() ? array(
		'fx_head_confirm' => array(
			'type'     => 'checkbox',
			'label'    => 'Potwierdzam wybrany rozmiar kasku, nawet jeśli nie pasuje do podanego obwodu',
			'required' => false,
			'priority' => 2,
			'class'    => array( 'form-row-wide' ),
		),
	) : array() ) + ( isset( $fields['order'] ) ? $fields['order'] : array() );
	return $fields;
} );

add_action( 'woocommerce_after_checkout_validation', function ( $data, $errors ) {
	// phpcs:disable WordPress.Security.NonceVerification -- WooCommerce weryfikuje nonce zamówienia.
	$cm      = isset( $_POST['fx_head_cm'] ) ? sanitize_text_field( wp_unslash( $_POST['fx_head_cm'] ) ) : '';
	$confirm = ! empty( $_POST['fx_head_confirm'] );
	// phpcs:enable
	$msg = fx_helmet_check( $cm, $confirm );
	if ( $msg ) {
		$errors->add( 'fx_head_cm', $msg );
	}
}, 10, 2 );

add_action( 'woocommerce_checkout_create_order', function ( $order ) {
	// phpcs:disable WordPress.Security.NonceVerification
	if ( isset( $_POST['fx_head_cm'] ) && '' !== $_POST['fx_head_cm'] ) {
		$order->update_meta_data( '_fx_head_cm', sanitize_text_field( wp_unslash( $_POST['fx_head_cm'] ) ) );
		if ( ! empty( $_POST['fx_head_confirm'] ) ) {
			$order->update_meta_data( '_fx_head_confirm', 'yes' );
		}
	}
	// phpcs:enable
} );

/* ---------- Blok zamówienia (Checkout Block) ---------- */
add_action( 'woocommerce_init', function () {
	if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
		return;
	}
	try {
		woocommerce_register_additional_checkout_field(
			array(
				'id'         => 'fox/head-cm',
				'label'      => 'Obwód głowy w cm (wymagany przy zamówieniu kasku)',
				'location'   => 'order',
				'type'       => 'text',
				'required'   => false,
			)
		);
		if ( fx_helmet_ranges() ) {
			woocommerce_register_additional_checkout_field(
				array(
					'id'       => 'fox/head-confirm',
					'label'    => 'Potwierdzam rozmiar kasku, nawet jeśli nie pasuje do obwodu',
					'location' => 'order',
					'type'     => 'checkbox',
				)
			);
		}
	} catch ( \Throwable $e ) {
		return;
	}
} );

add_action( 'woocommerce_store_api_checkout_update_order_from_request', function ( $order, $request ) {
	$fields  = (array) $request->get_param( 'additional_fields' );
	$cm      = isset( $fields['fox/head-cm'] ) ? (string) $fields['fox/head-cm'] : '';
	$confirm = ! empty( $fields['fox/head-confirm'] );
	$msg     = fx_helmet_check( $cm, $confirm );
	if ( $msg ) {
		throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'fx_head_cm', esc_html( $msg ), 400 );
	}
	if ( '' !== $cm ) {
		$order->update_meta_data( '_fx_head_cm', sanitize_text_field( $cm ) );
	}
}, 10, 2 );

/* ---------- Wyświetlanie w zamówieniu ---------- */
add_action( 'woocommerce_admin_order_data_after_shipping_address', function ( $order ) {
	$cm = $order->get_meta( '_fx_head_cm' );
	if ( $cm ) {
		echo '<p><strong>Obwód głowy:</strong> ' . esc_html( $cm ) . ' cm' . ( 'yes' === $order->get_meta( '_fx_head_confirm' ) ? ' (klient potwierdził rozmiar mimo niezgodności)' : '' ) . '</p>';
	}
} );

add_filter( 'woocommerce_email_order_meta_fields', function ( $fields, $sent_to_admin, $order ) {
	$cm = $order->get_meta( '_fx_head_cm' );
	if ( $cm ) {
		$fields['fx_head_cm'] = array(
			'label' => 'Obwód głowy',
			'value' => $cm . ' cm',
		);
	}
	return $fields;
}, 10, 3 );

add_action( 'woocommerce_order_details_after_order_table', function ( $order ) {
	$cm = $order->get_meta( '_fx_head_cm' );
	if ( $cm ) {
		echo '<p class="fx-head-cm"><strong>Obwód głowy:</strong> ' . esc_html( $cm ) . ' cm</p>';
	}
} );
