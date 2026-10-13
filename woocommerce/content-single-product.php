<?php
/**
 * Karta produktu.
 *
 * Nadpisuje woocommerce/templates/content-single-product.php (wersja 3.6.0).
 * Kolejność w kolumnie z ceną (hak woocommerce_single_product_summary):
 * tytuł H1 (5), ocena (10), cena (10), krótki opis (20), koszyk (30),
 * pomoc z rozmiarem (31), informacje o dostawie (35), meta (40), akordeon z pełnym opisem (45).
 *
 * @package foxsklep
 * @version 3.6.0
 */

defined( 'ABSPATH' ) || exit;

global $product;

do_action( 'woocommerce_before_single_product' );

if ( post_password_required() ) {
	echo get_the_password_form(); // phpcs:ignore
	return;
}
?>
<div id="product-<?php the_ID(); ?>" <?php wc_product_class( '', $product ); ?>>

	<div class="fx-wrap">
		<?php fx_breadcrumb(); ?>
		<div class="fx-pdp">
			<div class="fx-pdp__gallery">
				<?php do_action( 'woocommerce_before_single_product_summary' ); ?>
			</div>
			<div class="summary entry-summary fx-pdp__summary">
				<?php do_action( 'woocommerce_single_product_summary' ); ?>
			</div>
		</div>
	</div>

	<?php
	if ( function_exists( 'fx_kit_section' ) ) {
		fx_kit_section( $product );
	}
	?>

	<div class="fx-wrap">
		<?php do_action( 'woocommerce_after_single_product_summary' ); ?>
	</div>

	<?php if ( $product->is_purchasable() && $product->is_in_stock() ) : ?>
		<div class="fx-sticky-atc" aria-hidden="true">
			<div class="fx-sticky-atc__name"><strong><?php echo esc_html( get_the_title() ); ?></strong><?php echo wp_kses_post( $product->get_price_html() ); ?></div>
			<button type="button" class="fx-btn" data-fx-scroll-atc><?php echo $product->is_type( 'variable' ) ? 'Wybierz rozmiar' : 'Do koszyka'; ?></button>
		</div>
	<?php endif; ?>
</div>

<?php do_action( 'woocommerce_after_single_product' ); ?>
