<?php
/**
 * Karta produktu w listingu.
 *
 * Nadpisuje woocommerce/templates/content-product.php (wersja 9.4.0).
 * Celowo wywołujemy tylko hak woocommerce_after_shop_loop_item (poza linkiem karty) –
 * korzystają z niego wtyczki analityczne (GTM4WP) i ulubione. Pozostałych haków listingu
 * nie wywołujemy, bo wtyczki pisane pod Shoptimizera (CommerceKit) otwierają w nich
 * i zamykają własne opakowania, co rozsypuje kartę.
 *
 * @package foxsklep
 * @version 9.4.0
 */

defined( 'ABSPATH' ) || exit;

global $product;

if ( ! is_a( $product, WC_Product::class ) || ! $product->is_visible() ) {
	return;
}

$fx_gallery = $product->get_gallery_image_ids();
$fx_alt_img = $fx_gallery ? (int) $fx_gallery[0] : 0;
$fx_link    = apply_filters( 'woocommerce_loop_product_link', get_the_permalink(), $product );
?>
<li <?php wc_product_class( 'fx-card-li', $product ); ?>>
	<a class="fx-card woocommerce-LoopProduct-link woocommerce-loop-product__link" href="<?php echo esc_url( $fx_link ); ?>">
		<span class="fx-card__media">
			<?php echo $product->get_image( 'woocommerce_thumbnail', array( 'loading' => 'lazy' ) ); // phpcs:ignore ?>
			<?php
			if ( $fx_alt_img ) {
				echo wp_get_attachment_image( $fx_alt_img, 'woocommerce_thumbnail', false, array( 'class' => 'fx-card__alt', 'loading' => 'lazy', 'alt' => '' ) );
			}
			?>
			<span class="fx-card__labels"><?php echo fx_card_badge( $product ); // phpcs:ignore ?></span>
		</span>
		<span class="woocommerce-loop-product__title fx-card__title"><?php echo esc_html( get_the_title() ); ?></span>
		<?php woocommerce_template_loop_price(); ?>
	</a>
	<div class="fx-card__hooks"><?php do_action( 'woocommerce_after_shop_loop_item' ); ?></div>
</li>
