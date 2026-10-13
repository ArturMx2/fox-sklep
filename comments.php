<?php
/**
 * Komentarze pod wpisami.
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

if ( post_password_required() ) {
	return;
}
?>
<div id="comments" class="comments-area">
	<?php if ( have_comments() ) : ?>
		<h2>Komentarze (<?php echo (int) get_comments_number(); ?>)</h2>
		<ol class="comment-list" style="padding-left:20px">
			<?php wp_list_comments( array( 'style' => 'ol', 'short_ping' => true ) ); ?>
		</ol>
		<?php the_comments_pagination(); ?>
	<?php endif; ?>
	<?php comment_form(); ?>
</div>
