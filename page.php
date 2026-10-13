<?php
/**
 * Strona (w tym koszyk, zamówienie, moje konto i strony z Elementora).
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

get_header();

while ( have_posts() ) :
	the_post();

	$fx_is_shop_page = function_exists( 'is_cart' ) && ( is_cart() || is_checkout() || is_account_page() );

	if ( fx_is_elementor() && ! $fx_is_shop_page ) {
		// Strona z Elementora ma własny układ i nagłówki – wyświetlamy ją bez zmian.
		// Koszyk, zamówienie i konto zawsze w układzie motywu (marginesy, szerokość), nawet gdy zbudowane w Elementorze.
		the_content();
		continue;
	}
	?>
	<article id="post-<?php the_ID(); ?>" <?php post_class( 'fx-page fx-wrap' ); ?>>
		<?php fx_breadcrumb(); ?>
		<h1 class="fx-page__title"><?php the_title(); ?></h1>
		<div class="fx-content<?php echo $fx_is_shop_page ? ' fx-wide' : ''; ?>">
			<?php
			the_content();
			wp_link_pages();
			?>
		</div>
	</article>
	<?php
endwhile;

get_footer();
