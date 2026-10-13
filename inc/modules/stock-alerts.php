<?php
/**
 * Powiadomienia o dostępności („Powiadom mnie, gdy rozmiar wróci”).
 *
 * Zapisy są przechowywane jako wpisy typu fx_stock_alert (WooCommerce → Powiadomienia o dostępności).
 * Gdy produkt lub wariant wraca na stan, zapisani klienci dostają jednorazowy e-mail
 * w szablonie WooCommerce, a zapis zmienia status na „wysłane”.
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', function () {
	register_post_type(
		'fx_stock_alert',
		array(
			'labels'          => array(
				'name'          => 'Powiadomienia o dostępności',
				'singular_name' => 'Powiadomienie o dostępności',
				'menu_name'     => 'Powiadomienia o dostępności',
			),
			'public'          => false,
			'show_ui'         => true,
			'show_in_menu'    => 'woocommerce',
			'supports'        => array( 'title' ),
			'capability_type' => 'post',
			'capabilities'    => array( 'create_posts' => 'do_not_allow' ),
			'map_meta_cap'    => true,
		)
	);
	register_post_status( 'fx_waiting', array( 'label' => 'Czeka', 'internal' => false, 'protected' => true, 'show_in_admin_all_list' => true, 'show_in_admin_status_list' => true, 'label_count' => _n_noop( 'Czeka <span class="count">(%s)</span>', 'Czeka <span class="count">(%s)</span>' ) ) );
	register_post_status( 'fx_sent', array( 'label' => 'Wysłane', 'internal' => false, 'protected' => true, 'show_in_admin_all_list' => true, 'show_in_admin_status_list' => true, 'label_count' => _n_noop( 'Wysłane <span class="count">(%s)</span>', 'Wysłane <span class="count">(%s)</span>' ) ) );
} );

/* ---------- Formularz na karcie produktu ---------- */
add_action( 'woocommerce_single_product_summary', function () {
	global $product;
	if ( ! fx_tool( 'stock_alerts' ) || ! $product ) {
		return;
	}
	$simple_oos = ! $product->is_type( 'variable' ) && ! $product->is_in_stock();
	if ( ! $product->is_type( 'variable' ) && ! $simple_oos ) {
		return;
	}
	?>
	<form class="fx-alert<?php echo $simple_oos ? '' : ' fx-hide'; ?>" data-fx-alert data-product="<?php echo (int) $product->get_id(); ?>" novalidate>
		<div class="fx-alert__title">Powiadom mnie, gdy będzie dostępny</div>
		<div class="fx-alert__row">
			<label class="screen-reader-text" for="fx-alert-email">Twój e-mail</label>
			<input type="email" id="fx-alert-email" name="email" placeholder="Twój e-mail" required autocomplete="email">
			<input type="text" name="website" tabindex="-1" autocomplete="off" class="fx-hp" aria-hidden="true">
			<input type="hidden" name="variation_id" value="0">
			<button type="submit" class="fx-btn">Powiadom mnie</button>
		</div>
		<p class="fx-alert__note"><?php echo esc_html( fx_tool( 'stock_alert_text' ) ); ?></p>
		<div class="fx-alert__msg" role="status" aria-live="polite"></div>
	</form>
	<?php
}, 32 );

add_action( 'wc_ajax_fx_stock_alert', function () {
	// phpcs:disable WordPress.Security.NonceVerification -- formularz publiczny, chroniony polem-pułapką i limitem.
	if ( ! empty( $_POST['website'] ) ) {
		wp_send_json_success( array( 'message' => 'Dziękujemy!' ) );
	}
	$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
	$pid   = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
	$vid   = isset( $_POST['variation_id'] ) ? absint( $_POST['variation_id'] ) : 0;
	// phpcs:enable
	if ( ! is_email( $email ) ) {
		wp_send_json_error( array( 'message' => 'Podaj poprawny adres e-mail.' ) );
	}
	// Limit: najwyżej 5 zapisów na 10 minut z jednego adresu IP (ochrona przed zalewaniem formularza).
	$ip   = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	$rkey = 'fx_sa_rate_' . md5( $ip );
	$hits = (int) get_transient( $rkey );
	if ( $hits >= 5 ) {
		wp_send_json_error( array( 'message' => 'Zbyt wiele zapisów w krótkim czasie. Spróbuj ponownie za kilka minut.' ) );
	}
	set_transient( $rkey, $hits + 1, 10 * MINUTE_IN_SECONDS );
	$target = $vid ? wc_get_product( $vid ) : wc_get_product( $pid );
	if ( ! $target || ( $vid && $target->get_parent_id() !== $pid ) ) {
		wp_send_json_error( array( 'message' => 'Nie znaleziono produktu.' ) );
	}
	if ( $target->is_in_stock() ) {
		wp_send_json_error( array( 'message' => 'Ten produkt jest już dostępny – możesz go kupić.' ) );
	}
	$target_id = $target->get_id();

	$dupe = get_posts(
		array(
			'post_type'   => 'fx_stock_alert',
			'post_status' => 'fx_waiting',
			'numberposts' => 1,
			'fields'      => 'ids',
			'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array( 'key' => '_email', 'value' => $email ),
				array( 'key' => '_target', 'value' => $target_id ),
			),
		)
	);
	if ( ! $dupe ) {
		$variant = $vid ? wc_get_formatted_variation( $target, true, false, false ) : '';
		$label   = $email . ' – ' . get_the_title( $pid ) . ( $variant ? ' (' . $variant . ')' : '' );
		wp_insert_post(
			array(
				'post_type'   => 'fx_stock_alert',
				'post_status' => 'fx_waiting',
				'post_title'  => $label,
				'meta_input'  => array(
					'_email'   => $email,
					'_target'  => $target_id,
					'_product' => $pid,
				),
			)
		);
	}
	wp_send_json_success( array( 'message' => 'Gotowe! Napiszemy na ' . $email . ', gdy produkt wróci.' ) );
} );

/* ---------- Wysyłka po powrocie na stan ---------- */
add_action( 'woocommerce_product_set_stock_status', 'fx_stock_alerts_maybe_send', 10, 2 );
add_action( 'woocommerce_variation_set_stock_status', 'fx_stock_alerts_maybe_send', 10, 2 );
function fx_stock_alerts_maybe_send( $product_id, $status ) {
	if ( 'instock' !== $status ) {
		return;
	}
	$alerts = get_posts(
		array(
			'post_type'   => 'fx_stock_alert',
			'post_status' => 'fx_waiting',
			'numberposts' => 200,
			'meta_key'    => '_target', // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value'  => (int) $product_id, // phpcs:ignore WordPress.DB.SlowDBQuery
		)
	);
	if ( ! $alerts ) {
		return;
	}
	$product = wc_get_product( $product_id );
	if ( ! $product ) {
		return;
	}
	$parent  = $product->get_parent_id() ? wc_get_product( $product->get_parent_id() ) : $product;
	$name    = $parent->get_name() . ( $product->get_parent_id() ? ' – ' . wc_get_formatted_variation( $product, true, false, false ) : '' );
	$link    = $product->get_permalink();
	$mailer  = WC()->mailer();
	$subject = 'Znowu dostępne: ' . $name;
	$body    = '<p>Dzień dobry,</p><p>produkt, na który czekałeś, jest znowu dostępny:</p>'
		. '<p><strong>' . esc_html( $name ) . '</strong></p>'
		. '<p><a href="' . esc_url( $link ) . '" style="display:inline-block;padding:12px 24px;background:#0C0A08;color:#fff;text-decoration:none;border-radius:40px">Zobacz produkt</a></p>'
		. '<p>Liczba sztuk bywa ograniczona – kto pierwszy, ten lepszy.</p>';
	$html    = $mailer->wrap_message( 'Znowu dostępne', $body );

	foreach ( $alerts as $alert ) {
		$email = get_post_meta( $alert->ID, '_email', true );
		if ( is_email( $email ) && $mailer->send( $email, $subject, $html ) ) {
			wp_update_post( array( 'ID' => $alert->ID, 'post_status' => 'fx_sent' ) );
			update_post_meta( $alert->ID, '_sent', current_time( 'mysql' ) );
		}
	}
}

/* ---------- Kolumny w panelu ---------- */
add_filter( 'manage_fx_stock_alert_posts_columns', function () {
	return array(
		'cb'      => '<input type="checkbox">',
		'title'   => 'Zapis',
		'fx_st'   => 'Status',
		'date'    => 'Data zapisu',
	);
} );
add_action( 'manage_fx_stock_alert_posts_custom_column', function ( $col, $id ) {
	if ( 'fx_st' === $col ) {
		echo 'fx_sent' === get_post_status( $id ) ? 'Wysłane ' . esc_html( (string) get_post_meta( $id, '_sent', true ) ) : 'Czeka';
	}
}, 10, 2 );
