<?php
/**
 * Etykiety (badge) na zdjęciach produktów.
 *
 * Automatyczne: Promocja / −X%, Nowość, Ostatnie sztuki, Wyprzedane.
 * Ręczne: pole „Etykiety” w produkcie i w kategorii – jedna etykieta na linię,
 * „!” na początku = etykieta wyróżniona (czarna), np. „!Ocieplane”.
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

/* Domyślne kółko „Promocja!” WooCommerce zastępujemy naszymi etykietami. */
remove_action( 'woocommerce_before_shop_loop_item_title', 'woocommerce_show_product_loop_sale_flash', 10 );
remove_action( 'woocommerce_before_single_product_summary', 'woocommerce_show_product_sale_flash', 10 );

add_action( 'woocommerce_before_shop_loop_item_title', function () {
	global $product;
	echo fx_badges_html( $product ); // phpcs:ignore
}, 9 );

add_action( 'woocommerce_before_single_product_summary', function () {
	global $product;
	echo fx_badges_html( $product, 'fx-badges--single' ); // phpcs:ignore
}, 15 );

/**
 * Lista etykiet produktu: [ [tekst, wariant], ... ].
 */
function fx_product_badges( $product ) {
	if ( ! $product instanceof WC_Product ) {
		return array();
	}
	$out = array();

	if ( ! $product->is_in_stock() ) {
		return array( array( 'Wyprzedane', 'muted' ) );
	}

	if ( $product->is_on_sale() ) {
		$pct = fx_sale_percent( $product );
		$out[] = ( fx_tool( 'badge_sale_pct' ) && $pct >= 1 ) ? array( '−' . $pct . '%', 'sale' ) : array( 'Promocja', 'sale' );
	}

	$days    = (int) fx_tool( 'badge_new_days' );
	$created = $product->get_date_created();
	if ( $days > 0 && $created && ( time() - $created->getTimestamp() ) < $days * DAY_IN_SECONDS ) {
		$out[] = array( 'Nowość', 'default' );
	}

	// Ręczne etykiety: produkt, a jeśli brak – jego kategorie.
	$manual = trim( (string) get_post_meta( $product->get_id(), '_fx_badges', true ) );
	if ( '' === $manual ) {
		foreach ( $product->get_category_ids() as $cat_id ) {
			$cat_badges = trim( (string) get_term_meta( $cat_id, 'fx_badges', true ) );
			if ( '' !== $cat_badges ) {
				$manual = $cat_badges;
				break;
			}
		}
	}
	foreach ( preg_split( '/\r\n|\r|\n/', $manual ) as $line ) {
		$line = trim( $line );
		if ( '' === $line ) {
			continue;
		}
		$strong = ( '!' === $line[0] );
		$out[]  = array( ltrim( $line, '!' ), $strong ? 'strong' : 'default' );
	}

	$low = (int) fx_tool( 'badge_low_stock' );
	if ( $low > 0 && fx_low_stock( $product, $low ) ) {
		$out[] = array( 'Ostatnie sztuki', 'default' );
	}

	return apply_filters( 'fx_product_badges', array_slice( $out, 0, 3 ), $product );
}

function fx_sale_percent( $product ) {
	if ( $product->is_type( 'variable' ) ) {
		$max = 0;
		foreach ( $product->get_visible_children() as $vid ) {
			$v = wc_get_product( $vid );
			if ( $v && $v->is_on_sale() && (float) $v->get_regular_price() > 0 ) {
				$max = max( $max, ( 1 - (float) $v->get_sale_price() / (float) $v->get_regular_price() ) * 100 );
			}
		}
		return (int) round( $max );
	}
	$reg = (float) $product->get_regular_price();
	$sal = (float) $product->get_sale_price();
	return ( $reg > 0 && $sal > 0 ) ? (int) round( ( 1 - $sal / $reg ) * 100 ) : 0;
}

function fx_low_stock( $product, $limit ) {
	if ( $product->is_type( 'variable' ) ) {
		$total   = 0;
		$managed = false;
		foreach ( $product->get_visible_children() as $vid ) {
			$v = wc_get_product( $vid );
			if ( $v && $v->managing_stock() ) {
				$managed = true;
				$total  += max( 0, (int) $v->get_stock_quantity() );
			}
		}
		return $managed && $total > 0 && $total <= $limit;
	}
	return $product->managing_stock() && $product->get_stock_quantity() > 0 && $product->get_stock_quantity() <= $limit;
}

function fx_badges_html( $product, $extra_class = '' ) {
	$badges = fx_product_badges( $product );
	if ( ! $badges ) {
		return '';
	}
	$html = '<span class="fx-badges ' . esc_attr( $extra_class ) . '">';
	foreach ( $badges as $b ) {
		$html .= '<span class="fx-badge fx-badge--' . esc_attr( $b[1] ) . '">' . esc_html( $b[0] ) . '</span>';
	}
	return $html . '</span>';
}

/* ---------- Pola w panelu ---------- */
add_action( 'woocommerce_product_options_general_product_data', function () {
	echo '<div class="options_group">';
	woocommerce_wp_textarea_input(
		array(
			'id'          => '_fx_badges',
			'label'       => 'Etykiety',
			'placeholder' => "Ocieplane\n!Limitowana",
			'description' => 'Jedna etykieta na linię. „!” na początku = etykieta czarna. Puste = etykiety z kategorii. Promocja, Nowość i Wyprzedane dodają się same.',
			'desc_tip'    => true,
			'rows'        => 2,
		)
	);
	echo '</div>';
} );

add_action( 'woocommerce_admin_process_product_object', function ( $product ) {
	if ( isset( $_POST['_fx_badges'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		$product->update_meta_data( '_fx_badges', sanitize_textarea_field( wp_unslash( $_POST['_fx_badges'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
	}
} );

add_action( 'product_cat_edit_form_fields', function ( $term ) {
	$val = get_term_meta( $term->term_id, 'fx_badges', true );
	?>
	<tr class="form-field">
		<th scope="row"><label for="fx_badges">Etykiety produktów</label></th>
		<td>
			<textarea name="fx_badges" id="fx_badges" rows="3" class="large-text"><?php echo esc_textarea( (string) $val ); ?></textarea>
			<p class="description">Etykiety dla wszystkich produktów tej kategorii, które nie mają własnych. Jedna na linię, „!” na początku = czarna.</p>
		</td>
	</tr>
	<?php
}, 30 );

add_action( 'edited_product_cat', function ( $term_id ) {
	if ( current_user_can( 'manage_product_terms' ) && isset( $_POST['fx_badges'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		update_term_meta( $term_id, 'fx_badges', sanitize_textarea_field( wp_unslash( $_POST['fx_badges'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
	}
} );
