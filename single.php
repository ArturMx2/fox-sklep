<?php
/**
 * Pojedynczy wpis (poradniki).
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

get_header();

while ( have_posts() ) :
	the_post();

	if ( fx_is_elementor() ) {
		the_content();
		continue;
	}
	?>
	<article id="post-<?php the_ID(); ?>" <?php post_class( 'fx-page fx-wrap' ); ?>>
		<?php fx_breadcrumb(); ?>
		<div style="max-width:820px">
			<div class="fx-kicker" style="margin-top:20px"><?php the_category( ', ' ); ?> · <?php echo esc_html( get_the_date() ); ?></div>
			<h1 class="fx-page__title" style="margin-top:6px"><?php the_title(); ?></h1>
		</div>
		<?php if ( has_post_thumbnail() ) : ?>
			<div style="margin:0 0 32px;max-width:1200px"><?php the_post_thumbnail( 'full' ); ?></div>
		<?php endif; ?>
		<div class="fx-content">
			<?php
			the_content();
			wp_link_pages();
			?>
		</div>
		<?php
		if ( comments_open() || get_comments_number() ) {
			echo '<div class="fx-content" style="margin-top:48px">';
			comments_template();
			echo '</div>';
		}
		?>
	</article>
	<?php
endwhile;

get_footer();
