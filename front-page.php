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

<?php
$fx_worlds = (bool) fx_mod( 'worlds_on' );
/* Atrybut „dla kogo” sekcji: moto / rower / wszyscy. */
$fx_w = function ( $w ) {
	$w = (string) $w;
	return ( 'moto' === $w || 'rower' === $w ) ? ' data-fx-world="' . $w . '"' : '';
};
?>
<?php if ( $fx_worlds ) : /* ---------- Baner Moto | Rower ---------- */ ?>
<section class="fx-worlds" aria-label="Wybierz: moto czy rower">
	<?php foreach ( array( 'moto', 'rower' ) as $w ) : ?>
		<?php
		$cat_id  = fx_world_cat( $w );
		$cat_url = $cat_id ? get_term_link( $cat_id, 'product_cat' ) : '';
		?>
		<div class="fx-world fx-world--<?php echo esc_attr( $w ); ?> fx-shade">
			<?php echo fx_media( fx_mod( "world_{$w}_img" ), 'full', 'fx-cover', fx_mod( "world_{$w}_title" ), true ); // phpcs:ignore ?>
			<div class="fx-world__body">
				<span class="fx-world__chosen" aria-hidden="true">Wybrane</span>
				<p class="fx-world__title"><?php echo esc_html( fx_mod( "world_{$w}_title" ) ); ?></p>
				<?php if ( fx_mod( "world_{$w}_sub" ) ) : ?>
					<p class="fx-world__sub"><?php echo esc_html( fx_mod( "world_{$w}_sub" ) ); ?></p>
				<?php endif; ?>
				<div class="fx-world__ctas">
					<button type="button" class="fx-btn fx-btn--white" data-fx-world-set="<?php echo esc_attr( $w ); ?>" data-fx-world-scroll><?php echo esc_html( fx_mod( "world_{$w}_btn" ) ); ?></button>
					<?php if ( $cat_url && ! is_wp_error( $cat_url ) ) : ?>
						<a class="fx-world__link" href="<?php echo esc_url( $cat_url ); ?>">Wszystkie kategorie</a>
					<?php endif; ?>
				</div>
			</div>
		</div>
	<?php endforeach; ?>
</section>
<div class="fx-wswitch" id="fx-world">
	<span class="fx-wswitch__label">Pokazuję sprzęt:</span>
	<div class="fx-wswitch__seg" role="group" aria-label="Moto czy rower">
		<button type="button" data-fx-world-set="moto"><?php echo esc_html( fx_mod( 'world_moto_title' ) ); ?></button>
		<button type="button" data-fx-world-set="rower"><?php echo esc_html( fx_mod( 'world_rower_title' ) ); ?></button>
	</div>
</div>
<?php elseif ( fx_mod( 'hero_on' ) ) : ?>
<section class="fx-hero fx-shade-flat">
	<?php echo fx_media( fx_mod( 'hero_img' ), 'full', 'fx-cover', '', true ); // phpcs:ignore ?>
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

<?php endif; ?>

<?php /* ---------- H1, wstęp i atuty (SEO) ---------- */ ?>
<?php if ( ! fx_mod( 'intro_on' ) ) : ?>
<h1 class="screen-reader-text"><?php echo esc_html( fx_mod( 'seo_h1' ) ? fx_mod( 'seo_h1' ) : get_bloginfo( 'name' ) ); ?></h1>
<?php else : ?>
<section class="fx-intro fx-wrap">
	<h1 class="fx-intro__h1"><?php echo esc_html( fx_mod( 'seo_h1' ) ? fx_mod( 'seo_h1' ) : get_bloginfo( 'name' ) ); ?></h1>
	<?php if ( fx_mod( 'seo_lead' ) ) : ?>
		<p class="fx-intro__lead"><?php echo wp_kses_post( fx_mod( 'seo_lead' ) ); ?></p>
	<?php endif; ?>
	<?php $points = array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) fx_mod( 'seo_points' ) ) ) ); ?>
	<?php if ( $points ) : ?>
		<ul class="fx-intro__points">
			<?php foreach ( $points as $pt ) : ?>
				<li><?php echo esc_html( $pt ); ?></li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</section>
<?php endif; ?>

<?php
/* ---------- Kategorie (linki SEO) ---------- */
$fx_hub = function ( $key, $world ) use ( $fx_w ) {
	if ( ! fx_mod( "hub_{$key}_on" ) ) {
		return;
	}
	$items = fx_home_hub_items( fx_mod( "hub_{$key}_links" ) );
	if ( ! $items ) {
		return;
	}
	?>
	<section class="fx-hub fx-wrap"<?php echo $fx_w( $world ); // phpcs:ignore ?>>
		<div class="fx-row-head">
			<div>
				<h2 class="fx-h-section"><?php echo esc_html( fx_mod( "hub_{$key}_title" ) ); ?></h2>
				<?php if ( fx_mod( "hub_{$key}_sub" ) ) : ?>
					<p class="fx-hub__sub"><?php echo esc_html( fx_mod( "hub_{$key}_sub" ) ); ?></p>
				<?php endif; ?>
			</div>
		</div>
		<ul class="fx-hub__grid">
			<?php foreach ( $items as $it ) : ?>
				<li>
					<a class="fx-hub__item" href="<?php echo esc_url( $it['url'] ); ?>">
						<span class="fx-hub__img"><?php echo fx_media( $it['img'], 'woocommerce_thumbnail', 'fx-cover', '' ); // phpcs:ignore ?></span>
						<span class="fx-hub__name"><?php echo esc_html( $it['label'] ); ?></span>
						<?php if ( $it['count'] ) : ?>
							<span class="fx-hub__count"><?php echo (int) $it['count']; ?> <?php echo esc_html( fx_pl( $it['count'], 'produkt', 'produkty', 'produktów' ) ); ?></span>
						<?php endif; ?>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
	</section>
	<?php
};
if ( $fx_worlds ) {
	$fx_hub( 'moto', 'moto' );
	$fx_hub( 'rower', 'rower' );
} else {
	$fx_hub( 'moto', '' );
	$fx_hub( 'rower', '' );
}
?>

<?php /* ---------- Produkty ---------- */ ?>
<?php
if ( class_exists( 'WooCommerce' ) && fx_mod( 'new_on' ) ) :
	$count  = max( 3, min( 12, (int) fx_mod( 'new_count' ) ) );
	$source = fx_mod( 'new_source' );
	$atts   = 'limit="' . $count . '" columns="3"';
	if ( 'tag' === $source && fx_mod( 'new_tag' ) ) {
		$atts .= ' visibility="visible" tag="' . esc_attr( sanitize_title( fx_mod( 'new_tag' ) ) ) . '" orderby="date" order="DESC"';
	} elseif ( 'sale' === $source ) {
		$atts .= ' visibility="visible" on_sale="true"';
	} elseif ( 'featured' === $source ) {
		$atts .= ' visibility="featured"';
	} else {
		$atts .= ' visibility="visible" orderby="date" order="DESC"';
	}
	$rows = array( array( '', '', '' ) );
	if ( $fx_worlds && fx_mod( 'new_split' ) ) {
		$rows = array();
		foreach ( array( 'moto', 'rower' ) as $w ) {
			$t = get_term( fx_world_cat( $w ), 'product_cat' );
			if ( $t instanceof WP_Term ) {
				$rows[] = array( $w, $t->slug, get_term_link( $t ) );
			}
		}
		if ( ! $rows ) {
			$rows = array( array( '', '', '' ) );
		}
	}
	foreach ( $rows as $row ) :
		list( $w, $slug, $link ) = $row;
		?>
<section class="fx-wrap" style="padding-bottom:80px"<?php echo $fx_w( $w ); // phpcs:ignore ?>>
	<div class="fx-row-head">
		<h2 class="fx-h-section"><?php echo esc_html( ( $w && fx_mod( "new_title_{$w}" ) ) ? fx_mod( "new_title_{$w}" ) : fx_mod( 'new_title' ) ); ?></h2>
		<?php if ( $w && $link ) : ?>
			<a class="fx-btn fx-btn--outline fx-btn--sm" href="<?php echo esc_url( $link ); ?>">Wszystko: <?php echo esc_html( fx_mod( "world_{$w}_title" ) ); ?></a>
		<?php elseif ( fx_mod( 'new_btn_label' ) && fx_mod( 'new_btn_url' ) ) : ?>
			<a class="fx-btn fx-btn--outline fx-btn--sm" href="<?php echo esc_url( fx_mod( 'new_btn_url' ) ); ?>"><?php echo esc_html( fx_mod( 'new_btn_label' ) ); ?></a>
		<?php endif; ?>
	</div>
	<?php echo do_shortcode( '[products ' . $atts . ( $slug ? ' category="' . esc_attr( $slug ) . '"' : '' ) . ']' ); ?>
</section>
		<?php
	endforeach;
endif;
?>

<?php /* ---------- Odkrywaj ---------- */ ?>
<?php if ( fx_mod( 'explore_on' ) ) : ?>
<section style="padding:48px 0 64px">
	<h2 class="fx-h-section fx-wrap" style="margin-bottom:24px"><?php echo esc_html( fx_mod( 'explore_title' ) ); ?></h2>
	<div class="fx-scroller">
		<?php
		for ( $i = 1; $i <= min( 12, max( 1, (int) fx_mod( 'explore_count' ) ) ); $i++ ) :
			$label = fx_mod( "explore_{$i}_label" );
			if ( ! $label ) {
				continue;
			}
			$url = fx_mod( "explore_{$i}_url" );
			?>
			<a href="<?php echo esc_url( $url ? $url : '#' ); ?>"<?php echo $fx_worlds ? $fx_w( fx_mod( "explore_{$i}_world" ) ) : ''; // phpcs:ignore ?>>
				<span class="fx-media"><?php echo fx_media( fx_mod( "explore_{$i}_img" ), 'fx-portrait', 'fx-cover', $label ); // phpcs:ignore ?></span>
				<span class="fx-scroller__label"><?php echo esc_html( $label ); ?></span>
			</a>
		<?php endfor; ?>
	</div>
</section>

<?php endif; ?>

<?php /* ---------- Duże kafle ---------- */ ?>
<?php if ( fx_mod( 'tiles_on' ) ) : ?>
<section class="fx-tiles">
	<?php
	for ( $i = 1; $i <= min( 8, max( 1, (int) fx_mod( 'tiles_count' ) ) ); $i++ ) :
		$title = fx_mod( "tile_{$i}_title" );
		if ( ! $title ) {
			continue;
		}
		?>
		<div class="fx-tile fx-shade"<?php echo $fx_worlds ? $fx_w( fx_mod( "tile_{$i}_world" ) ) : ''; // phpcs:ignore ?>>
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

<?php endif; ?>

<?php /* ---------- Dyscypliny (gdy przełącznik Moto / Rower wyłączony) ---------- */ ?>
<?php $fx_hub( 'casual', '' ); ?>
<?php if ( ! $fx_worlds && fx_mod( 'disc_on' ) ) : ?>
<section class="fx-section fx-wrap">
	<h2 class="fx-h-label"><?php echo esc_html( fx_mod( 'disc_title' ) ); ?></h2>
	<div class="fx-disc">
		<?php
		for ( $i = 1; $i <= min( 8, max( 1, (int) fx_mod( 'disc_count' ) ) ); $i++ ) :
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
	$gsets = array( array( '', (int) fx_mod( 'guides_cat' ) ) );
	if ( $fx_worlds && ( fx_mod( 'guides_cat_moto' ) || fx_mod( 'guides_cat_rower' ) ) ) {
		$gsets = array(
			array( 'moto', fx_mod( 'guides_cat_moto' ) ? (int) fx_mod( 'guides_cat_moto' ) : (int) fx_mod( 'guides_cat' ) ),
			array( 'rower', fx_mod( 'guides_cat_rower' ) ? (int) fx_mod( 'guides_cat_rower' ) : (int) fx_mod( 'guides_cat' ) ),
		);
	}
	foreach ( $gsets as $gs ) :
		$args = array(
			'post_type'           => 'post',
			'posts_per_page'      => max( 1, min( 9, (int) fx_mod( 'guides_count' ) ) ),
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
		);
		if ( $gs[1] ) {
			$args['cat'] = $gs[1];
		}
		$guides = new WP_Query( $args );
		if ( ! $guides->have_posts() ) {
			continue;
		}
		?>
		<section class="fx-section fx-wrap"<?php echo $fx_w( $gs[0] ); // phpcs:ignore ?>>
			<div class="fx-row-head">
				<h2 class="fx-h-section"><?php echo esc_html( fx_mod( 'guides_title' ) ); ?></h2>
				<?php if ( fx_mod( 'guides_btn_url' ) ) : ?>
					<a class="fx-btn fx-btn--outline fx-btn--sm" href="<?php echo esc_url( $gs[1] ? get_category_link( $gs[1] ) : fx_mod( 'guides_btn_url' ) ); ?>">Wszystkie</a>
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
	endforeach;
endif;
?>

<?php /* ---------- Najczęstsze pytania ---------- */ ?>
<?php $faq = fx_home_faq_items(); ?>
<?php if ( fx_mod( 'faq_on' ) && $faq ) : ?>
<section class="fx-section fx-wrap fx-faq" id="faq">
	<h2 class="fx-h-section"><?php echo esc_html( fx_mod( 'faq_title' ) ); ?></h2>
	<div class="fx-faq__list">
		<?php foreach ( $faq as $i => $qa ) : ?>
			<details class="fx-faq__item"<?php echo 0 === $i ? ' open' : ''; ?>>
				<summary><h3><?php echo esc_html( $qa[0] ); ?></h3></summary>
				<div class="fx-faq__a"><?php echo wp_kses_post( wpautop( $qa[1] ) ); ?></div>
			</details>
		<?php endforeach; ?>
	</div>
</section>
<?php endif; ?>

<?php /* ---------- Sklep stacjonarny (lokalne SEO) ---------- */ ?>
<?php if ( fx_mod( 'store_on' ) ) : ?>
<?php $fx_smap = fx_mod( 'store_map' ) && function_exists( 'fx_store_map' ) ? fx_store_map() : ''; ?>
<section id="salon" class="fx-store fx-wrap<?php echo $fx_smap ? ' fx-store--map' : ''; ?>">
	<div class="fx-store__main">
	<div class="fx-store__title"><?php echo nl2br( esc_html( fx_mod( 'store_title' ) ) ); ?></div>
	<div class="fx-store__info">
		<h2 class="fx-store__h">Sklep stacjonarny FOX pod Wrocławiem</h2>
		<address>
			<?php echo esc_html( trim( fx_mod( 'store_street' ) . ', ' . trim( fx_mod( 'store_postcode' ) . ' ' . fx_mod( 'store_city' ) ), ', ' ) ); ?><br>
			<?php if ( fx_mod( 'store_hours_text' ) ) : ?><?php echo esc_html( fx_mod( 'store_hours_text' ) ); ?><br><?php endif; ?>
			<?php if ( fx_mod( 'store_phone' ) ) : ?><a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', fx_mod( 'store_phone' ) ) ); ?>"><?php echo esc_html( fx_mod( 'store_phone' ) ); ?></a><?php endif; ?>
		</address>
		<?php if ( fx_mod( 'store_text' ) ) : ?>
			<p><?php echo wp_kses_post( fx_mod( 'store_text' ) ); ?></p>
		<?php endif; ?>
	</div>
	<?php if ( fx_mod( 'store_btn_label' ) && fx_mod( 'store_btn_url' ) ) : ?>
		<a class="fx-btn fx-btn--white" href="<?php echo esc_url( fx_mod( 'store_btn_url' ) ); ?>"><?php echo esc_html( fx_mod( 'store_btn_label' ) ); ?></a>
	<?php endif; ?>
	</div>
	<?php echo $fx_smap; // phpcs:ignore WordPress.Security.EscapeOutput -- zbudowane w fx_store_map() ?>
</section>
<?php endif; ?>

<?php /* ---------- O sklepie (tekst SEO) ---------- */ ?>
<?php
$seo_text = fx_mod( 'seo_text' );
if ( $seo_text && fx_mod( 'about_on' ) ) :
	$parts = explode( '<!--more-->', $seo_text, 2 );
	?>
	<section class="fx-section fx-wrap fx-about">
		<h2 class="fx-h-section"><?php echo esc_html( fx_mod( 'about_title' ) ); ?></h2>
		<div class="fx-about__body fx-prose">
			<?php echo fx_rich( $parts[0] ); // phpcs:ignore ?>
			<?php if ( isset( $parts[1] ) && trim( $parts[1] ) ) : ?>
				<details class="fx-more">
					<summary>Czytaj więcej</summary>
					<?php echo fx_rich( $parts[1] ); // phpcs:ignore ?>
				</details>
			<?php endif; ?>
		</div>
	</section>
<?php endif; ?>

<?php
get_footer();
