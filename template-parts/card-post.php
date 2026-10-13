<?php
/**
 * Karta wpisu (blog, poradniki) – układ jak dotychczas na fox.sklep.pl:
 * zdjęcie z kategorią, awatar autora, tytuł, zajawka, „Czytaj więcej”, data i komentarze.
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

$fx_cats = get_the_category();
$fx_com  = (int) get_comments_number();
?>
<article <?php post_class( 'fx-bcard' ); ?>>
	<a class="fx-bcard__media" href="<?php the_permalink(); ?>" tabindex="-1" aria-hidden="true">
		<?php
		if ( has_post_thumbnail() ) {
			the_post_thumbnail( 'large', array( 'loading' => 'lazy', 'alt' => '' ) );
		} else {
			echo '<span class="fx-cover fx-ph"></span>';
		}
		?>
		<?php if ( $fx_cats ) : ?>
			<span class="fx-bcard__cat"><?php echo esc_html( $fx_cats[0]->name ); ?></span>
		<?php endif; ?>
	</a>
	<span class="fx-bcard__avatar"><?php echo get_avatar( get_the_author_meta( 'ID' ), 80, '', get_the_author() ); ?></span>
	<div class="fx-bcard__body">
		<h3 class="fx-bcard__title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>
		<div class="fx-bcard__excerpt"><?php echo esc_html( wp_trim_words( get_the_excerpt(), 34, '…' ) ); ?></div>
		<a class="fx-bcard__more" href="<?php the_permalink(); ?>" aria-label="Czytaj więcej: <?php echo esc_attr( get_the_title() ); ?>">Czytaj więcej »</a>
	</div>
	<footer class="fx-bcard__meta">
		<time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date( 'd/m/Y' ) ); ?></time>
		<span aria-hidden="true">•</span>
		<span><?php echo $fx_com ? esc_html( $fx_com . ' ' . fx_pl( $fx_com, 'komentarz', 'komentarze', 'komentarzy' ) ) : 'Brak komentarzy'; ?></span>
	</footer>
</article>
