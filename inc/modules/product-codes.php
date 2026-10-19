<?php
/**
 * Kod produktu (SKU) i EAN pod nazwą produktu na karcie produktu.
 *
 * - EAN z pola „_woosea_ean” (Product Feed PRO; także w każdym wariancie), zapasowo z pola WooCommerce
 *   „GTIN, UPC, EAN lub ISBN” i pól innych wtyczek (filtr „fx_pcodes_ean_keys”),
 * - produkt z wariantami: po wyborze rozmiaru/koloru kod i EAN zmieniają się na wariant,
 *   bez wyboru widać kod modelu (a EAN dopiero po wyborze, bo każdy rozmiar ma inny),
 * - kliknięcie w kod kopiuje go do schowka (przydatne przy telefonie do sklepu),
 * - linijka „SKU:” na dole karty (przy kategoriach) jest ukryta, żeby kod nie dublował się.
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

/**
 * EAN produktu lub wariantu.
 * Najpierw pole „_woosea_ean” (Product Feed PRO – to samo, które idzie do Google Merchant),
 * potem pole WooCommerce „GTIN, UPC, EAN lub ISBN”, na końcu pola innych wtyczek.
 * Wariant nie dziedziczy EAN-u po produkcie głównym – każdy rozmiar ma własny kod.
 */
function fx_pcodes_ean( $product ) {
	if ( ! $product instanceof WC_Product ) {
		return '';
	}
	$id   = $product->get_id();
	$keys = apply_filters( 'fx_pcodes_ean_keys', array( '_woosea_ean', '_woosea_variable_ean', '_global_unique_id', '_gtin', '_ean', 'ean', 'gtin', '_wpm_gtin_code', 'hwp_product_gtin', 'hwp_var_gtin', '_alg_ean' ) );
	$ean  = '';
	foreach ( $keys as $k ) {
		$v = trim( (string) get_post_meta( $id, $k, true ) );
		if ( '' !== $v ) {
			$ean = $v;
			break;
		}
	}
	return preg_replace( '/[^0-9A-Za-z\-]/', '', $ean );
}

add_action( 'woocommerce_single_product_summary', function () {
	global $product;
	if ( ! $product instanceof WC_Product ) {
		return;
	}
	$sku = (string) $product->get_sku();
	$ean = $product->is_type( 'variable' ) ? '' : fx_pcodes_ean( $product );
	if ( '' === $sku && '' === $ean && ! $product->is_type( 'variable' ) ) {
		return;
	}
	?>
	<div class="fx-pcodes" data-fx-pcodes data-sku="<?php echo esc_attr( $sku ); ?>" data-ean="<?php echo esc_attr( $ean ); ?>">
		<span class="fx-pcodes__item<?php echo '' === $sku ? ' fx-hide' : ''; ?>" data-k="sku">Kod produktu: <button type="button" class="fx-pcodes__val" title="Kliknij, aby skopiować"><?php echo esc_html( $sku ); ?></button></span>
		<span class="fx-pcodes__item<?php echo '' === $ean ? ' fx-hide' : ''; ?>" data-k="ean">EAN: <button type="button" class="fx-pcodes__val" title="Kliknij, aby skopiować"><?php echo esc_html( $ean ); ?></button></span>
	</div>
	<?php
}, 6 );

/* EAN wariantu w danych wariantów (JS podmienia go po wyborze rozmiaru). */
add_filter( 'woocommerce_available_variation', function ( $data, $parent, $variation ) {
	$data['fx_ean'] = fx_pcodes_ean( $variation );
	return $data;
}, 10, 3 );

/* Bez dublowania: „SKU:” w metadanych na dole jest ukryte w style.css (kategorie i marka zostają). */

add_action( 'wp_footer', function () {
	if ( ! function_exists( 'is_product' ) || ! is_product() ) {
		return;
	}
	?>
	<script>
	(function(){var box=document.querySelector('[data-fx-pcodes]');if(!box)return;
	function set(k,v){var it=box.querySelector('[data-k="'+k+'"]');it.querySelector('.fx-pcodes__val').textContent=v||'';it.classList.toggle('fx-hide',!v)}
	if(window.jQuery){var $f=jQuery('form.variations_form');
		$f.on('found_variation',function(e,v){set('sku',v.sku||box.dataset.sku);set('ean',v.fx_ean||'')});
		$f.on('reset_data hide_variation',function(){set('sku',box.dataset.sku);set('ean',box.dataset.ean)})}
	box.addEventListener('click',function(e){var b=e.target.closest('.fx-pcodes__val');if(!b||!navigator.clipboard)return;
		navigator.clipboard.writeText(b.textContent.trim()).then(function(){b.classList.add('is-copied');setTimeout(function(){b.classList.remove('is-copied')},1400)})});
	})();
	</script>
	<?php
}, 30 );
