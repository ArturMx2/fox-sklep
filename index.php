<?php
/**
 * Blog, archiwa, kategorie wpisów, wyniki wyszukiwania (poza produktami).
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

get_header();

if ( is_home() && ! is_front_page() ) {
	$fx_title = get_the_title( (int) get_option( 'page_for_posts' ) );
} elseif ( is_search() ) {
	/* translators: %s: fraza */
	$fx_title = sprintf( 'Wyniki wyszukiwania: %s', get_search_query() );
} elseif ( is_archive() ) {
	$fx_title = wp_strip_all_tags( get_the_archive_title() );
} else {
	$fx_title = get_bloginfo( 'name' );
}
?>
<section class="fx-page fx-wrap">
	<?php fx_breadcrumb(); ?>
	<h1 class="fx-page__title"><?php echo esc_html( $fx_title ); ?></h1>
	<?php
	if ( is_archive() ) {
		the_archive_description( '<div class="fx-cat-intro" style="margin-bottom:32px">', '</div>' );
	}
	?>

	<?php if ( have_posts() ) : ?>
		<div class="fx-posts">
			<?php
			while ( have_posts() ) :
				the_post();
				get_template_part( 'template-parts/card', 'post' );
			endwhile;
			?>
		</div>
		<div style="margin-top:48px">
			<?php
			the_posts_pagination(
				array(
					'prev_text' => '‹ Poprzednia',
					'next_text' => 'Następna ›',
				)
			);
			?>
		</div>
	<?php else : ?>
		<p>Nic nie znaleziono.</p>
		<?php get_search_form(); ?>
	<?php endif; ?>
</section>
<?php
get_footer();
