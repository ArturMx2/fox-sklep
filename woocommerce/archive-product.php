<?php
/**
 * Lista produktów: kategorie, tagi, sklep, wyniki wyszukiwania.
 *
 * Nadpisuje woocommerce/templates/archive-product.php (wersja 8.6.0).
 * H1 = woocommerce_page_title(), czyli ten sam nagłówek co dotychczas.
 *
 * @package foxsklep
 * @version 8.6.0
 */

defined( 'ABSPATH' ) || exit;

get_header( 'shop' );

do_action( 'woocommerce_before_main_content' );

$fx_term  = ( is_product_category() || is_product_tag() || is_tax() ) ? get_queried_object() : null;
$fx_paged = max( 1, (int) get_query_var( 'paged' ) );
$fx_desc  = array( '', '' );
if ( $fx_term instanceof WP_Term ) {
	$fx_desc = fx_term_descriptions( $fx_term );
} elseif ( is_shop() && ! is_search() ) {
	$fx_shop = get_post( wc_get_page_id( 'shop' ) );
	if ( $fx_shop ) {
		$fx_desc = array( $fx_shop->post_content, '' );
	}
}
$fx_thumb = 0;
if ( $fx_term instanceof WP_Term && 'product_cat' === $fx_term->taxonomy ) {
	$fx_thumb = (int) get_term_meta( $fx_term->term_id, 'thumbnail_id', true );
	// Baner tylko z szerokiego zdjęcia – małe ikonki kategorii zostają w panelu, ale nie trafiają do banera.
	$fx_meta = $fx_thumb ? wp_get_attachment_metadata( $fx_thumb ) : array();
	if ( empty( $fx_meta['width'] ) || (int) $fx_meta['width'] < 1200 || (int) $fx_meta['width'] < (int) $fx_meta['height'] ) {
		$fx_thumb = 0;
	}
}
$fx_show_desc = ( 1 === $fx_paged ); // Jak w WooCommerce: opis tylko na pierwszej stronie.
?>

<?php if ( $fx_thumb ) : ?>
	<section class="fx-hero fx-hero--cat fx-shade-flat">
		<?php echo wp_get_attachment_image( $fx_thumb, 'full', false, array( 'class' => 'fx-cover', 'alt' => '' ) ); ?>
		<div class="fx-hero__in">
			<h1 class="fx-hero__title"><?php woocommerce_page_title(); ?></h1>
		</div>
	</section>
<?php endif; ?>

<div class="fx-wrap">
	<?php fx_breadcrumb(); ?>
	<?php if ( ! $fx_thumb ) : ?>
		<h1 class="fx-page__title" style="margin-bottom:8px"><?php woocommerce_page_title(); ?></h1>
	<?php endif; ?>

	<?php if ( $fx_show_desc && '' !== trim( wp_strip_all_tags( $fx_desc[0] ) ) ) : ?>
		<div class="fx-cat-intro term-description">
			<?php echo fx_rich( $fx_desc[0] ); // phpcs:ignore ?>
			<?php if ( '' !== trim( $fx_desc[1] ) ) : ?>
				<p><a href="#fx-opis">Czytaj pełny opis</a></p>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<?php do_action( 'woocommerce_archive_description' ); ?>
</div>

<?php if ( woocommerce_product_loop() ) : ?>

	<div class="fx-toolbar fx-wrap">
		<?php fx_subcategory_chips(); ?>
		<span class="fx-toolbar__spacer"></span>
		<?php if ( is_active_sidebar( 'shop-filters' ) ) : ?>
			<button class="fx-btn fx-btn--outline fx-btn--sm" type="button" data-fx-open="fx-filters" aria-controls="fx-filters" aria-expanded="false"><?php echo fx_icon( 'filter', 18 ); // phpcs:ignore ?>Filtry</button>
		<?php endif; ?>
		<?php woocommerce_catalog_ordering(); ?>
	</div>

	<div class="fx-wrap" style="padding-top:20px;padding-bottom:80px">
		<?php woocommerce_result_count(); ?>
		<?php
		do_action( 'woocommerce_before_shop_loop' );

		woocommerce_product_loop_start();

		if ( wc_get_loop_prop( 'total' ) ) {
			while ( have_posts() ) {
				the_post();
				do_action( 'woocommerce_shop_loop' );
				wc_get_template_part( 'content', 'product' );
			}
		}

		woocommerce_product_loop_end();

		do_action( 'woocommerce_after_shop_loop' );
		?>
	</div>

<?php else : ?>
	<div class="fx-wrap" style="padding-top:24px;padding-bottom:80px">
		<?php do_action( 'woocommerce_no_products_found' ); ?>
	</div>
<?php endif; ?>

<?php if ( $fx_show_desc && '' !== trim( wp_strip_all_tags( $fx_desc[1] ) ) ) : ?>
	<section id="fx-opis" class="fx-wrap fx-seo fx-cat-desc" style="padding-bottom:80px">
		<div class="fx-seo__head">
			<div class="fx-kicker">O kategorii</div>
		</div>
		<div class="fx-seo__body">
			<?php echo fx_rich( $fx_desc[1] ); // phpcs:ignore ?>
		</div>
	</section>
<?php endif; ?>

<?php if ( is_active_sidebar( 'shop-filters' ) ) : ?>
	<div class="fx-drawer fx-drawer--right" id="fx-filters" aria-hidden="true">
		<div class="fx-drawer__bg" data-fx-close></div>
		<div class="fx-drawer__panel" role="dialog" aria-modal="true" aria-label="Filtry">
			<div class="fx-drawer__head">
				<span class="fx-drawer__title">Filtry</span>
				<button class="fx-icon-btn" type="button" data-fx-close aria-label="Zamknij filtry"><?php echo fx_icon( 'close' ); // phpcs:ignore ?></button>
			</div>
			<?php dynamic_sidebar( 'shop-filters' ); ?>
		</div>
	</div>
<?php endif; ?>

<?php
do_action( 'woocommerce_after_main_content' );
do_action( 'woocommerce_sidebar' );

get_footer( 'shop' );
