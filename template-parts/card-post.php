<?php
/**
 * Karta wpisu (blog, poradniki).
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;
?>
<a class="fx-post" href="<?php the_permalink(); ?>">
	<span class="fx-media">
		<?php
		if ( has_post_thumbnail() ) {
			the_post_thumbnail( 'large', array( 'class' => 'fx-cover', 'loading' => 'lazy' ) );
		} else {
			echo '<span class="fx-cover fx-ph" aria-hidden="true"></span>';
		}
		?>
	</span>
	<span class="fx-post__title"><?php the_title(); ?></span>
</a>
