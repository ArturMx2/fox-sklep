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

	if ( fx_is_elementor() ) {
		// Strona z Elementora ma własny układ i nagłówki – wyświetlamy ją bez zmian.
		the_content();
		continue;
	}

	$fx_is_shop_page = function_exists( 'is_cart' ) && ( is_cart() || is_checkout() || is_account_page() );
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
