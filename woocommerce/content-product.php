<?php
/**
 * Karta produktu w listingu.
 *
 * Nadpisuje woocommerce/templates/content-product.php (wersja 9.4.0).
 * Wszystkie standardowe haki WooCommerce są wywoływane WEWNĄTRZ <li>, żeby elementy
 * dokładane przez wtyczki (ulubione, etykiety, dane GTM) trafiały do karty,
 * a nie zajmowały osobnych komórek siatki. Domyślne elementy Woo z tych haków
 * są wyłączone w inc/woocommerce.php – kartę rysuje ten szablon.
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
	<div class="fx-card__hooks fx-card__hooks--top"><?php do_action( 'woocommerce_before_shop_loop_item' ); ?></div>
	<a class="fx-card woocommerce-LoopProduct-link woocommerce-loop-product__link" href="<?php echo esc_url( $fx_link ); ?>">
		<span class="fx-card__media">
			<?php echo $product->get_image( 'woocommerce_thumbnail', array( 'loading' => 'lazy' ) ); // phpcs:ignore ?>
			<?php
			if ( $fx_alt_img ) {
				echo wp_get_attachment_image( $fx_alt_img, 'woocommerce_thumbnail', false, array( 'class' => 'fx-card__alt', 'loading' => 'lazy', 'alt' => '' ) );
			}
			?>
			<span class="fx-card__labels"><?php echo fx_card_badge( $product ); // phpcs:ignore ?><?php do_action( 'woocommerce_before_shop_loop_item_title' ); ?></span>
		</span>
		<h2 class="woocommerce-loop-product__title fx-card__title"><?php echo esc_html( get_the_title() ); ?></h2>
		<?php woocommerce_template_loop_price(); ?>
	</a>
	<div class="fx-card__hooks">
		<?php
		// Poza linkiem karty: wtyczki mogą tu wstawiać własne linki (np. próbki wariantów).
		do_action( 'woocommerce_shop_loop_item_title' );
		do_action( 'woocommerce_after_shop_loop_item_title' );
		do_action( 'woocommerce_after_shop_loop_item' );
		?>
	</div>
</li>
