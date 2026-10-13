<?php
/**
 * Faktura na firmę: „Chcę otrzymać fakturę” + numer NIP w zamówieniu.
 *
 * Tak jak na enduro.sklep.pl: te same nazwy pól (is_company_checkbox, vat_number_input)
 * i to samo pole zamówienia (_vat_number), więc integracje (np. BaseLinker) czytają NIP tak samo.
 * Dodatkowo: sprawdzenie poprawności polskiego NIP (suma kontrolna), zgodność z HPOS.
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

// Ten sam kod może już działać jako fragment w innej wtyczce – wtedy nie dublujemy pól.
if ( function_exists( 'display_is_company_checkbox' ) ) {
	return;
}

/** Czysty NIP: bez spacji i myślników; prefiks kraju zostaje dla numerów zagranicznych. */
function fx_nip_clean( $v ) {
	$v = strtoupper( preg_replace( '/[\s\-\.]/', '', (string) $v ) );
	return preg_replace( '/^PL(?=\d{10}$)/', '', $v );
}

/** Polski NIP: 10 cyfr z poprawną sumą kontrolną. Numery UE z prefiksem kraju (np. DE…) przepuszczamy. */
function fx_nip_valid( $v ) {
	$v = fx_nip_clean( $v );
	if ( preg_match( '/^[A-Z]{2}[0-9A-Z]{2,13}$/', $v ) ) {
		return true;
	}
	if ( ! preg_match( '/^\d{10}$/', $v ) ) {
		return false;
	}
	$w   = array( 6, 5, 7, 2, 3, 4, 5, 6, 7 );
	$sum = 0;
	for ( $i = 0; $i < 9; $i++ ) {
		$sum += (int) $v[ $i ] * $w[ $i ];
	}
	return ( $sum % 11 ) % 10 === (int) $v[9] && ( $sum % 11 ) !== 10;
}

/* 1. Pole „Chcę otrzymać fakturę” i NIP nad danymi rozliczeniowymi */
add_action( 'woocommerce_before_checkout_billing_form', function ( $checkout ) {
	$on = (bool) $checkout->get_value( 'is_company_checkbox' );
	echo '<div class="fx-invoice">';
	woocommerce_form_field(
		'is_company_checkbox',
		array(
			'type'  => 'checkbox',
			'class' => array( 'form-row-wide', 'fx-invoice__check' ),
			'label' => 'Chcę otrzymać fakturę na firmę',
		),
		$on ? 1 : 0
	);
	echo '<div id="vat_number_input_field_container"' . ( $on ? '' : ' hidden' ) . '>';
	woocommerce_form_field(
		'vat_number_input',
		array(
			'type'              => 'text',
			'class'             => array( 'form-row-wide', 'fx-invoice__nip' ),
			'label'             => 'Numer NIP',
			'placeholder'       => 'np. 8971234567',
			'required'          => false,
			'custom_attributes' => array( 'inputmode' => 'numeric', 'autocomplete' => 'off' ),
		),
		$checkout->get_value( 'vat_number_input' )
	);
	echo '<p class="fx-invoice__hint">Fakturę wystawimy na firmę i adres z poniższych danych – wpisz nazwę firmy w polu „Nazwa firmy”.</p>';
	echo '</div></div>';
} );

/* 2. Walidacja: NIP wymagany i poprawny, gdy zaznaczono fakturę */
add_action( 'woocommerce_checkout_process', function () {
	if ( empty( $_POST['is_company_checkbox'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	$nip = isset( $_POST['vat_number_input'] ) ? sanitize_text_field( wp_unslash( $_POST['vat_number_input'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	if ( '' === trim( $nip ) ) {
		wc_add_notice( '<strong>Numer NIP</strong> jest wymagany, jeśli chcesz otrzymać fakturę.', 'error' );
	} elseif ( ! fx_nip_valid( $nip ) ) {
		wc_add_notice( '<strong>Numer NIP</strong> jest niepoprawny – sprawdź, czy wpisano wszystkie 10 cyfr.', 'error' );
	}
} );

/* 3. Pokazywanie pola i oznaczenie jako wymagane */
add_action( 'woocommerce_after_checkout_form', function () {
	?>
	<script>
	jQuery(function ($) {
		var $cb = $('#is_company_checkbox'), $box = $('#vat_number_input_field_container'), $row = $('#vat_number_input_field'), $in = $('#vat_number_input');
		function sync(anim) {
			if ($cb.is(':checked')) {
				$box.prop('hidden', false);
				if (anim) { $box.hide().slideDown(150); }
				$row.addClass('validate-required fx-req').removeClass('woocommerce-validated');
			} else {
				$box.prop('hidden', true);
				$in.val('');
				$row.removeClass('validate-required fx-req woocommerce-invalid woocommerce-invalid-required-field');
			}
		}
		sync(false);
		$cb.on('change', function () { sync(true); if ($cb.is(':checked')) { $in.trigger('focus'); } });
	});
	</script>
	<?php
} );

/* 4. Zapis w zamówieniu (ten sam klucz co na enduro: _vat_number) */
add_action( 'woocommerce_checkout_create_order', function ( $order ) {
	if ( empty( $_POST['is_company_checkbox'] ) || empty( $_POST['vat_number_input'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	$order->update_meta_data( '_vat_number', fx_nip_clean( sanitize_text_field( wp_unslash( $_POST['vat_number_input'] ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
	$order->update_meta_data( '_fx_wants_invoice', 'yes' );
} );

/* 5. Panel admina: NIP pod adresem rozliczeniowym */
add_action( 'woocommerce_admin_order_data_after_billing_address', function ( $order ) {
	$nip = $order->get_meta( '_vat_number' );
	if ( $nip ) {
		echo '<p><strong>Numer NIP:</strong> ' . esc_html( $nip ) . '<br><em>Klient prosi o fakturę.</em></p>';
	}
} );

/* 6. Strona podziękowania, „Moje konto” i e-maile */
add_action( 'woocommerce_order_details_after_order_table', function ( $order ) {
	$nip = $order->get_meta( '_vat_number' );
	if ( $nip ) {
		echo '<p class="fx-order-nip"><strong>Faktura na firmę – NIP:</strong> ' . esc_html( $nip ) . '</p>';
	}
} );
add_action( 'woocommerce_email_after_order_table', function ( $order, $sent_to_admin = false, $plain_text = false ) {
	$nip = $order instanceof WC_Order ? $order->get_meta( '_vat_number' ) : '';
	if ( ! $nip ) {
		return;
	}
	if ( $plain_text ) {
		echo "\nFaktura na firmę – NIP: " . esc_html( $nip ) . "\n";
	} else {
		echo '<p><strong>Faktura na firmę – NIP:</strong> ' . esc_html( $nip ) . '</p>';
	}
}, 10, 3 );
