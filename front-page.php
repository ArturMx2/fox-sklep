<?php
/**
 * Strona główna.
 *
 * Gdy w Personalizacji wyłączysz „nową stronę główną”, pokazywana jest
 * zwykła treść strony ustawionej jako główna (Ustawienia → Czytanie).
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

if ( ! fx_mod( 'home_enabled' ) ) {
	if ( 'page' === get_option( 'show_on_front' ) ) {
		include get_page_template();
	} else {
		include get_home_template();
	}
	return;
}

get_header();
?>

<?php /* ---------- Baner ---------- */ ?>
<section class="fx-hero fx-shade-flat">
	<?php echo fx_media( fx_mod( 'hero_img' ), 'full', 'fx-cover', '' ); // phpcs:ignore ?>
	<div class="fx-hero__in">
		<?php if ( fx_mod( 'hero_title' ) ) : ?>
			<h2 class="fx-hero__title"><?php echo esc_html( fx_mod( 'hero_title' ) ); ?></h2>
		<?php endif; ?>
		<?php if ( fx_mod( 'hero_sub' ) ) : ?>
			<p class="fx-hero__sub"><?php echo esc_html( fx_mod( 'hero_sub' ) ); ?></p>
		<?php endif; ?>
		<div class="fx-hero__ctas">
			<?php if ( fx_mod( 'hero_b1_label' ) && fx_mod( 'hero_b1_url' ) ) : ?>
				<a class="fx-btn fx-btn--ghost-white" href="<?php echo esc_url( fx_mod( 'hero_b1_url' ) ); ?>"><?php echo esc_html( fx_mod( 'hero_b1_label' ) ); ?></a>
			<?php endif; ?>
			<?php if ( fx_mod( 'hero_b2_label' ) && fx_mod( 'hero_b2_url' ) ) : ?>
				<a class="fx-btn fx-btn--white" href="<?php echo esc_url( fx_mod( 'hero_b2_url' ) ); ?>"><?php echo esc_html( fx_mod( 'hero_b2_label' ) ); ?></a>
			<?php endif; ?>
		</div>
	</div>
</section>

<?php /* ---------- Odkrywaj ---------- */ ?>
<section style="padding:48px 0 64px">
	<h2 class="fx-h-section fx-wrap" style="margin-bottom:24px"><?php echo esc_html( fx_mod( 'explore_title' ) ); ?></h2>
	<div class="fx-scroller">
		<?php
		for ( $i = 1; $i <= 5; $i++ ) :
			$label = fx_mod( "explore_{$i}_label" );
			if ( ! $label ) {
				continue;
			}
			$url = fx_mod( "explore_{$i}_url" );
			?>
			<a href="<?php echo esc_url( $url ? $url : '#' ); ?>">
				<span class="fx-media"><?php echo fx_media( fx_mod( "explore_{$i}_img" ), 'fx-portrait', 'fx-cover', $label ); // phpcs:ignore ?></span>
				<span class="fx-scroller__label"><?php echo esc_html( $label ); ?></span>
			</a>
		<?php endfor; ?>
	</div>
</section>

<?php /* ---------- Duże kafle ---------- */ ?>
<section class="fx-tiles">
	<?php
	for ( $i = 1; $i <= 4; $i++ ) :
		$title = fx_mod( "tile_{$i}_title" );
		if ( ! $title ) {
			continue;
		}
		?>
		<div class="fx-tile fx-shade">
			<?php echo fx_media( fx_mod( "tile_{$i}_img" ), 'fx-tile', 'fx-cover', $title ); // phpcs:ignore ?>
			<div class="fx-tile__body">
				<h3 class="fx-tile__title"><?php echo esc_html( $title ); ?></h3>
				<div class="fx-tile__pills">
					<?php foreach ( fx_lines( fx_mod( "tile_{$i}_links" ) ) as $l ) : ?>
						<a class="fx-pill" href="<?php echo esc_url( $l[1] ); ?>"><?php echo esc_html( $l[0] ); ?></a>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
	<?php endfor; ?>
</section>

<?php /* ---------- Dyscypliny ---------- */ ?>
<section class="fx-section fx-wrap">
	<h2 class="fx-h-label"><?php echo esc_html( fx_mod( 'disc_title' ) ); ?></h2>
	<div class="fx-disc">
		<?php
		for ( $i = 1; $i <= 4; $i++ ) :
			$label = fx_mod( "disc_{$i}_label" );
			if ( ! $label ) {
				continue;
			}
			?>
			<a href="<?php echo esc_url( fx_mod( "disc_{$i}_url" ) ); ?>">
				<?php echo fx_media( fx_mod( "disc_{$i}_img" ), 'fx-tile', 'fx-cover', $label ); // phpcs:ignore ?>
				<span class="fx-pill"><?php echo esc_html( $label ); ?></span>
			</a>
		<?php endfor; ?>
	</div>
</section>

<?php /* ---------- Produkty ---------- */ ?>
<?php if ( class_exists( 'WooCommerce' ) ) : ?>
<section class="fx-wrap" style="padding-bottom:80px">
	<div class="fx-row-head">
		<h2 class="fx-h-section"><?php echo esc_html( fx_mod( 'new_title' ) ); ?></h2>
		<?php if ( fx_mod( 'new_btn_label' ) && fx_mod( 'new_btn_url' ) ) : ?>
			<a class="fx-btn fx-btn--outline fx-btn--sm" href="<?php echo esc_url( fx_mod( 'new_btn_url' ) ); ?>"><?php echo esc_html( fx_mod( 'new_btn_label' ) ); ?></a>
		<?php endif; ?>
	</div>
	<?php
	$count  = max( 4, min( 12, (int) fx_mod( 'new_count' ) ) );
	$source = fx_mod( 'new_source' );
	$atts   = 'limit="' . $count . '" columns="4"';
	if ( 'tag' === $source && fx_mod( 'new_tag' ) ) {
		$atts .= ' visibility="visible" tag="' . esc_attr( sanitize_title( fx_mod( 'new_tag' ) ) ) . '" orderby="date" order="DESC"';
	} elseif ( 'sale' === $source ) {
		$atts .= ' visibility="visible" on_sale="true"';
	} elseif ( 'featured' === $source ) {
		$atts .= ' visibility="featured"';
	} else {
		$atts .= ' visibility="visible" orderby="date" order="DESC"';
	}
	echo do_shortcode( '[products ' . $atts . ']' );
	?>
</section>
<?php endif; ?>

<?php /* ---------- Zawodnik ---------- */ ?>
<?php if ( fx_mod( 'rider_on' ) ) : ?>
<section class="fx-split">
	<div class="fx-split__media"><?php echo fx_media( fx_mod( 'rider_img' ), 'fx-tile', 'fx-cover', fx_mod( 'rider_title' ) ); // phpcs:ignore ?></div>
	<div class="fx-split__body">
		<?php if ( fx_mod( 'rider_kicker' ) ) : ?>
			<div class="fx-kicker"><?php echo esc_html( fx_mod( 'rider_kicker' ) ); ?></div>
		<?php endif; ?>
		<h2 class="fx-split__title"><?php echo esc_html( fx_mod( 'rider_title' ) ); ?></h2>
		<?php if ( fx_mod( 'rider_text' ) ) : ?>
			<div style="margin-top:18px;color:var(--fx-ink-2);font-size:17px"><?php echo wp_kses_post( wpautop( fx_mod( 'rider_text' ) ) ); ?></div>
		<?php endif; ?>
		<?php $rows = fx_lines( fx_mod( 'rider_list' ) ); ?>
		<?php if ( $rows ) : ?>
			<ul class="fx-kv">
				<?php foreach ( $rows as $r ) : ?>
					<li><span><?php echo esc_html( $r[0] ); ?></span><span><?php echo esc_html( $r[1] ); ?></span></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<?php if ( fx_mod( 'rider_btn_label' ) && fx_mod( 'rider_btn_url' ) ) : ?>
			<div style="margin-top:28px"><a class="fx-btn" href="<?php echo esc_url( fx_mod( 'rider_btn_url' ) ); ?>"><?php echo esc_html( fx_mod( 'rider_btn_label' ) ); ?></a></div>
		<?php endif; ?>
	</div>
</section>
<?php endif; ?>

<?php /* ---------- Poradniki ---------- */ ?>
<?php
if ( fx_mod( 'guides_on' ) ) :
	$args = array(
		'post_type'           => 'post',
		'posts_per_page'      => 3,
		'ignore_sticky_posts' => true,
		'no_found_rows'       => true,
	);
	if ( fx_mod( 'guides_cat' ) ) {
		$args['cat'] = (int) fx_mod( 'guides_cat' );
	}
	$guides = new WP_Query( $args );
	if ( $guides->have_posts() ) :
		?>
		<section class="fx-section fx-wrap">
			<div class="fx-row-head">
				<h2 class="fx-h-section"><?php echo esc_html( fx_mod( 'guides_title' ) ); ?></h2>
				<?php if ( fx_mod( 'guides_btn_url' ) ) : ?>
					<a class="fx-btn fx-btn--outline fx-btn--sm" href="<?php echo esc_url( fx_mod( 'guides_btn_url' ) ); ?>">Wszystkie</a>
				<?php endif; ?>
			</div>
			<div class="fx-posts">
				<?php
				while ( $guides->have_posts() ) :
					$guides->the_post();
					get_template_part( 'template-parts/card', 'post' );
				endwhile;
				wp_reset_postdata();
				?>
			</div>
		</section>
		<?php
	endif;
endif;
?>

<?php /* ---------- H1 i tekst SEO ---------- */ ?>
<?php
$seo_h1   = fx_mod( 'seo_h1' );
$seo_text = fx_mod( 'seo_text' );
if ( $seo_h1 || $seo_text ) :
	$parts = explode( '<!--more-->', $seo_text, 2 );
	?>
	<section class="fx-section fx-wrap fx-seo">
		<div class="fx-seo__head">
			<?php if ( $seo_h1 ) : ?>
				<h1><?php echo esc_html( $seo_h1 ); ?></h1>
			<?php endif; ?>
		</div>
		<div class="fx-seo__body">
			<?php echo fx_rich( $parts[0] ); // phpcs:ignore ?>
			<?php if ( isset( $parts[1] ) && trim( $parts[1] ) ) : ?>
				<details class="fx-more">
					<summary>Czytaj więcej</summary>
					<?php echo fx_rich( $parts[1] ); // phpcs:ignore ?>
				</details>
			<?php endif; ?>
		</div>
	</section>
<?php else : ?>
	<h1 class="screen-reader-text"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></h1>
<?php endif; ?>

<?php /* ---------- Sklep stacjonarny ---------- */ ?>
<?php if ( fx_mod( 'store_on' ) ) : ?>
<section id="salon" class="fx-store fx-wrap">
	<div class="fx-store__title"><?php echo nl2br( esc_html( fx_mod( 'store_title' ) ) ); ?></div>
	<p><?php echo wp_kses_post( fx_mod( 'store_text' ) ); ?></p>
	<?php if ( fx_mod( 'store_btn_label' ) && fx_mod( 'store_btn_url' ) ) : ?>
		<a class="fx-btn fx-btn--white" href="<?php echo esc_url( fx_mod( 'store_btn_url' ) ); ?>"><?php echo esc_html( fx_mod( 'store_btn_label' ) ); ?></a>
	<?php endif; ?>
</section>
<?php endif; ?>

<?php
get_footer();
