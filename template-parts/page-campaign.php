<?php
/**
 * Strona kampanii (szablon „Kampania (Black Week)”): zapowiedź → promocja → po kampanii, według dat z Przecen.
 * Treść strony z edytora pokazuje się pod nagłówkiem w każdym trybie.
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

get_header();

$fx_mode = fx_camp_mode();
$fx_m    = $fx_mode ? $fx_mode : 'after';
list( $fx_from, $fx_to ) = fx_camp_dates();
$fx_worlds = array();
foreach ( array( 'moto' => 'Moto', 'rower' => 'Rower' ) as $fx_w => $fx_l ) {
	$fx_cat = fx_world_cat( $fx_w );
	$fx_url = $fx_cat ? get_term_link( $fx_cat, 'product_cat' ) : '';
	if ( $fx_url && ! is_wp_error( $fx_url ) ) {
		$fx_worlds[ $fx_l ] = 'during' === $fx_m ? add_query_arg( array( 'promocja' => '1', 'orderby' => 'discount' ), $fx_url ) : $fx_url;
	}
}
?>
<div class="fx-camp fx-camp--<?php echo esc_attr( $fx_m ); ?>">
	<section class="fx-camp__hero">
		<div class="fx-camp__in fx-wrap">
			<p class="fx-camp__kicker"><?php echo esc_html( 'before' === $fx_m ? 'Zapowiedź' : ( 'during' === $fx_m ? 'Trwa teraz' : 'Kampania zakończona' ) ); ?></p>
			<h1 class="fx-camp__title"><?php echo esc_html( fx_camp_fill( fx_camp( 'camp_h_' . $fx_m ) ) ); ?></h1>
			<p class="fx-camp__sub"><?php echo esc_html( fx_camp_fill( fx_camp( 'camp_sub_' . $fx_m ) ) ); ?></p>
			<?php if ( in_array( $fx_m, array( 'before', 'during' ), true ) ) : ?>
				<div class="fx-camp__count" data-fx-ccount data-to="<?php echo (int) ( 'before' === $fx_m ? $fx_from : $fx_to ) * 1000; ?>" aria-live="off">
					<span class="fx-camp__count-l"><?php echo esc_html( 'before' === $fx_m ? 'Start za' : 'Do końca' ); ?></span>
					<span class="fx-camp__count-v" data-v>&nbsp;</span>
				</div>
			<?php endif; ?>
			<?php if ( $fx_worlds ) : ?>
				<div class="fx-camp__worlds">
					<?php foreach ( $fx_worlds as $fx_l => $fx_u ) : ?>
						<a class="fx-btn fx-btn--ghost-white" href="<?php echo esc_url( $fx_u ); ?>"><?php echo esc_html( ( 'during' === $fx_m ? 'Promocje ' : '' ) . $fx_l ); ?></a>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
	</section>

	<?php
	while ( have_posts() ) :
		the_post();
		if ( '' !== trim( get_the_content() ) ) :
			?>
			<div class="fx-camp__content fx-wrap fx-content"><?php the_content(); ?></div>
			<?php
		endif;
	endwhile;
	?>

	<?php
	if ( 'during' === $fx_m && function_exists( 'fx_camp_top_ids' ) ) :
		$fx_ids = fx_camp_top_ids( 24 );
		if ( $fx_ids ) :
			?>
			<section class="fx-camp__products fx-wrap">
				<h2 class="fx-camp__h2">Największe promocje</h2>
				<?php echo do_shortcode( '[products ids="' . esc_attr( implode( ',', $fx_ids ) ) . '" orderby="post__in" columns="4" limit="24"]' ); // phpcs:ignore ?>
				<?php if ( $fx_worlds ) : ?>
					<div class="fx-camp__worlds fx-camp__worlds--end">
						<?php foreach ( $fx_worlds as $fx_l => $fx_u ) : ?>
							<a class="fx-btn" href="<?php echo esc_url( $fx_u ); ?>">Wszystkie promocje <?php echo esc_html( $fx_l ); ?> →</a>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</section>
			<?php
		endif;
	endif;
	?>
</div>
<script>
(function(){var c=document.querySelector('[data-fx-ccount]');if(!c)return;var T=+c.dataset.to,v=c.querySelector('[data-v]');
function two(n){return(n<10?'0':'')+n}
function tick(){var s=Math.max(0,Math.floor((T-Date.now())/1000)),d=Math.floor(s/86400),h=Math.floor(s%86400/3600),m=Math.floor(s%3600/60);s=s%60;
v.textContent=(d>0?(d===1?'1 dzień ':d+' dni '):'')+two(h)+':'+two(m)+':'+two(s);if(T-Date.now()<=0){clearInterval(t);try{if(!sessionStorage.getItem('fxcamp-r'+T)){sessionStorage.setItem('fxcamp-r'+T,'1');setTimeout(function(){location.reload()},3000)}}catch(e){}}}
var t=setInterval(tick,1000);tick();})();
</script>
<?php
get_footer();
