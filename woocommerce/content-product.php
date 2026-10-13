<?php
/**
 * Karta produktu w listingu.
 *
 * Nadpisuje woocommerce/templates/content-product.php (wersja 9.4.0).
 * Hak woocommerce_after_shop_loop_item zostaje – korzystają z niego wtyczki analityczne.
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
?>
<li <?php wc_product_class( '', $product ); ?>>
	<a class="fx-card woocommerce-LoopProduct-link woocommerce-loop-product__link" href="<?php echo esc_url( apply_filters( 'woocommerce_loop_product_link', get_the_permalink(), $product ) ); ?>">
		<span class="fx-card__media">
			<?php echo $product->get_image( 'woocommerce_thumbnail', array( 'loading' => 'lazy' ) ); // phpcs:ignore ?>
			<?php
			if ( $fx_alt_img ) {
				echo wp_get_attachment_image( $fx_alt_img, 'woocommerce_thumbnail', false, array( 'class' => 'fx-card__alt', 'loading' => 'lazy', 'alt' => '' ) );
			}
			?>
		</span>
		<?php echo fx_card_badge( $product ); // phpcs:ignore ?>
		<h2 class="woocommerce-loop-product__title fx-card__title"><?php echo esc_html( get_the_title() ); ?></h2>
		<?php woocommerce_template_loop_price(); ?>
	</a>
	<?php do_action( 'woocommerce_after_shop_loop_item' ); ?>
</li>
