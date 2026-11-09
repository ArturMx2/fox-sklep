<?php
/**
 * Kampania (np. Black Week): pasek z odliczaniem nad nagłówkiem i szablon strony „Kampania” (/black-week/).
 *
 * Wszystko według dat kampanii z modułu Przeceny (WooCommerce → Przeceny: od / do) – nic nie trzeba
 * przepinać ani cofać: przed startem zapowiedź, w trakcie promocja, po końcu tryb „po kampanii”.
 * Tryb paska liczy przeglądarka z dat (strony bywają w cache), serwer tylko podaje teksty.
 *
 * @package foxsklep
 */

defined( 'ABSPATH' ) || exit;

function fx_camp_defaults() {
	return array(
		'camp_bar_on'      => true,
		'camp_bar_days'    => 7,
		'camp_bar_before'  => 'Black Week startuje za {czas}',
		'camp_bar_during'  => 'Black Week trwa · do końca: {czas}',
		'camp_bar_after'   => 'Zamów do 18.12 – dostarczymy przed Świętami',
		'camp_bar_after_to'=> '2026-12-18',
		'camp_bar_link'    => '/black-week/',
		'camp_h_before'    => 'Black Week FOX nadchodzi',
		'camp_h_during'    => 'Black Week FOX – do −30%',
		'camp_h_after'     => 'Black Week zakończony',
		'camp_sub_before'  => 'Do −30% na moto i MTB. Startujemy {start}.',
		'camp_sub_during'  => 'Rabaty na FOX moto i MTB – tylko do {koniec}.',
		'camp_sub_after'   => 'Dziękujemy! Zajrzyj do aktualnych promocji albo wybierz prezent.',
		'camp_after_url'   => '',
		'camp_home_on'     => true,
		'camp_home_before' => true,
		'camp_home_img'    => 0,
		'camp_home_img_m'  => 0,
		'camp_home_cta'    => 'Zobacz promocje',
		'camp_home_cta_b'  => 'Zobacz zapowiedź',
	);
}

function fx_camp( $key ) {
	$d = fx_camp_defaults();
	return get_theme_mod( 'fx_' . $key, $d[ $key ] ?? '' );
}

/** Daty kampanii z Przecen: [ start_ts, end_ts, on ]. */
function fx_camp_dates() {
	if ( ! function_exists( 'fx_promo_settings' ) ) {
		return array( 0, 0, false );
	}
	$s = fx_promo_settings();
	return array( fx_promo_ts( $s['from'] ), fx_promo_ts( $s['to'] ), ! empty( $s['on'] ) );
}

/** Tryb: before | during | after | '' (brak dat albo kampania wyłączona). */
function fx_camp_mode() {
	list( $from, $to, $on ) = fx_camp_dates();
	if ( ! $on ) {
		return '';
	}
	$now = time();
	if ( $from && $now < $from ) {
		return 'before';
	}
	return ( ! $to || $now < $to ) ? 'during' : 'after'; // puste „od” = od razu, puste „do” = bez końca (jak w Przecenach)
}

function fx_camp_fill( $txt ) {
	list( $from, $to ) = fx_camp_dates();
	return strtr( (string) $txt, array(
		'{start}'  => $from ? wp_date( 'j.m', $from ) . ( '00:00' !== wp_date( 'H:i', $from ) ? ' o ' . wp_date( 'H:i', $from ) : '' ) : '',
		'{koniec}' => $to ? wp_date( 'j.m', $to - 60 ) : '',
	) );
}

/* =====================================================================
 * Pasek z odliczaniem (nad nagłówkiem)
 * ===================================================================== */
add_action( 'wp_body_open', function () {
	if ( ! fx_camp( 'camp_bar_on' ) || is_admin() ) {
		return;
	}
	list( $from, $to, $on ) = fx_camp_dates();
	if ( ! $on || ! $to ) {
		return; // bez daty końca nie ma czego odliczać
	}
	if ( ! $from ) {
		$from = (int) get_option( 'fx_camp_started', 0 ); // puste „od” = start od razu
		if ( ! $from ) {
			$from = time();
			update_option( 'fx_camp_started', $from, false );
		}
	}
	$after_to = fx_camp( 'camp_bar_after_to' ) ? strtotime( fx_camp( 'camp_bar_after_to' ) . ' 23:59:59 ' . wp_timezone_string() ) : 0;
	$show_from = $from - max( 0, (int) fx_camp( 'camp_bar_days' ) ) * DAY_IN_SECONDS;
	$show_to   = '' !== trim( (string) fx_camp( 'camp_bar_after' ) ) && $after_to > $to ? $after_to : $to;
	if ( time() >= $show_to || time() < $show_from - DAY_IN_SECONDS ) {
		return; // poza oknem paska (z zapasem dnia na cache)
	}
	$link = trim( (string) fx_camp( 'camp_bar_link' ) );
	printf(
		'<div class="fx-cbar" data-fx-cbar hidden data-from="%1$d" data-to="%2$d" data-show="%3$d" data-end="%4$d" data-before="%5$s" data-during="%6$s" data-after="%7$s"><a class="fx-cbar__txt"%8$s></a><button type="button" class="fx-cbar__x" aria-label="Zamknij pasek">×</button></div>',
		(int) $from * 1000,
		(int) $to * 1000,
		(int) $show_from * 1000,
		(int) $show_to * 1000,
		esc_attr( fx_camp_fill( fx_camp( 'camp_bar_before' ) ) ),
		esc_attr( fx_camp_fill( fx_camp( 'camp_bar_during' ) ) ),
		esc_attr( fx_camp_fill( fx_camp( 'camp_bar_after' ) ) ),
		$link ? ' href="' . esc_url( $link ) . '"' : ''
	);
	?>
	<script>
	(function(){var b=document.querySelector('[data-fx-cbar]');if(!b)return;
	var F=+b.dataset.from,T=+b.dataset.to,S=+b.dataset.show,E=+b.dataset.end,t=b.querySelector('.fx-cbar__txt'),mode='',tm;
	function two(n){return(n<10?'0':'')+n}
	function dni(d){return d===1?'1 dzień':d+' dni'}
	function left(ms,short){var s=Math.max(0,Math.floor(ms/1000)),d=Math.floor(s/86400),h=Math.floor(s%86400/3600),m=Math.floor(s%3600/60);s=s%60;
		if(short&&d>0)return dni(d)+' '+h+' godz.';return(d>0?dni(d)+' ':'')+two(h)+':'+two(m)+':'+two(s)}
	function tick(){var n=Date.now(),md=n<S?'':n<F?'before':n<T?'during':n<E?'after':'';
		if(md!==mode){mode=md;var k='fxcbar-'+md;var shut=false;try{shut=sessionStorage.getItem(k)==='1'}catch(e){}
			if(!md||shut||!b.dataset[md]){b.hidden=true;return}b.hidden=false;b.className='fx-cbar fx-cbar--'+md}
		if(!mode||b.hidden)return;var tx=b.dataset[mode];
		t.textContent=tx.replace('{czas}',mode==='before'?left(F-n,true):mode==='during'?left(T-n,false):'')}
	b.querySelector('.fx-cbar__x').addEventListener('click',function(){b.hidden=true;try{sessionStorage.setItem('fxcbar-'+mode,'1')}catch(e){}clearInterval(tm)});
	tick();tm=setInterval(tick,1000);})();
	</script>
	<?php
}, 5 );

/* =====================================================================
 * Szablon strony „Kampania”
 * ===================================================================== */
add_filter( 'theme_page_templates', function ( $t ) {
	$t['fx-campaign'] = 'Kampania (Black Week)';
	return $t;
} );

function fx_camp_is_page() {
	return is_page() && 'fx-campaign' === get_page_template_slug( get_queried_object_id() );
}

add_filter( 'template_include', function ( $tpl ) {
	return fx_camp_is_page() ? FX_DIR . '/template-parts/page-campaign.php' : $tpl;
} );

// Po kampanii: opcjonalne przekierowanie 302 (np. na /prezenty/). Strona nigdy nie zwraca 404.
add_action( 'template_redirect', function () {
	if ( fx_camp_is_page() && 'after' === fx_camp_mode() ) {
		$url = trim( (string) fx_camp( 'camp_after_url' ) );
		if ( $url ) {
			wp_safe_redirect( $url, 302 );
			exit;
		}
	}
} );

/** Produkty z największym rabatem (z mapy rabatów), najwyżej $n. */
function fx_camp_top_ids( $n = 24 ) {
	$map = function_exists( 'fx_pl_map' ) ? fx_pl_map() : null;
	if ( ! $map ) {
		return array();
	}
	arsort( $map );
	$out = array();
	foreach ( array_keys( $map ) as $id ) {
		$p = wc_get_product( $id );
		if ( $p && $p->is_visible() && $p->is_in_stock() ) {
			$out[] = (int) $id;
		}
		if ( count( $out ) >= $n ) {
			break;
		}
	}
	return $out;
}

/* =====================================================================
 * Filtr „Black Week” (?kampania=1) – produkty objęte kampanią z rabatem; tylko w trakcie kampanii
 * ===================================================================== */
function fx_camp_filter_on() {
	return ! empty( $_GET['kampania'] ) && function_exists( 'fx_promo_active' ) && fx_promo_active(); // phpcs:ignore WordPress.Security.NonceVerification
}

/** Produkty objęte kampanią (z mapy rabatów, liczonej w tle). */
function fx_camp_ids() {
	return get_option( 'fx_pl_built' ) ? array_map( 'intval', (array) get_option( 'fx_pl_camp', array() ) ) : array();
}

add_filter( 'fx_filter_url_params', function ( $p ) {
	$p[] = 'kampania';
	return $p;
} );

add_filter( 'fx_filter_sets', function ( $sets ) {
	if ( fx_camp_filter_on() ) {
		$ids    = fx_camp_ids();
		$sets[] = $ids ? $ids : array( 0 );
	}
	return $sets;
} );

add_filter( 'fx_filter_switches', function ( $sw, $term ) {
	if ( ! function_exists( 'fx_promo_active' ) || ! fx_promo_active() ) {
		return $sw;
	}
	$ids = fx_camp_ids();
	$on  = fx_camp_filter_on();
	if ( ! $on ) {
		$have = false;
		if ( $ids ) {
			$set = array_flip( $ids );
			foreach ( fx_filter_cat_products( $term ) as $pid ) {
				if ( isset( $set[ $pid ] ) ) {
					$have = true;
					break;
				}
			}
		}
		if ( ! $have ) {
			return $sw;
		}
	}
	$label = trim( (string) fx_promo_settings()['label'] );
	$label = '' !== $label ? $label : 'Kampania';
	$base  = remove_query_arg( array( 'kampania', 'paged' ), fx_filter_base_url() );
	array_unshift( $sw, array(
		'key'   => 'kampania',
		'label' => $label,
		'short' => $label,
		'note'  => 'tylko produkty z rabatem ' . $label,
		'on'    => $on,
		'url'   => $on ? $base : add_query_arg( 'kampania', '1', $base ),
	) );
	return $sw;
}, 5, 2 );

/* =====================================================================
 * Baner kampanii na stronie głównej (nad Moto | Rower)
 * ===================================================================== */
function fx_camp_home_banner() {
	$mode = fx_camp_mode();
	if ( ! fx_camp( 'camp_home_on' ) || ! in_array( $mode, array( 'before', 'during' ), true ) ) {
		return;
	}
	list( $from, $to ) = fx_camp_dates();
	if ( 'before' === $mode && ( ! fx_camp( 'camp_home_before' ) || $from - time() > max( 0, (int) fx_camp( 'camp_bar_days' ) ) * DAY_IN_SECONDS ) ) {
		return; // zapowiedź – tyle dni przed startem, co pasek
	}
	$img   = (int) fx_camp( 'camp_home_img' );
	$img_m = (int) fx_camp( 'camp_home_img_m' );
	$link  = trim( (string) fx_camp( 'camp_bar_link' ) );
	$count = 'before' === $mode ? $from : $to;
	$title = fx_camp_fill( fx_camp( 'camp_h_' . $mode ) );
	echo '<section class="fx-chome fx-chome--' . esc_attr( $mode ) . ( $img ? ' has-img' : '' ) . '" aria-label="' . esc_attr( $title ) . '">';
	if ( $img ) {
		echo '<picture class="fx-chome__bg">';
		if ( $img_m ) {
			echo '<source media="(max-width:640px)" srcset="' . esc_url( wp_get_attachment_image_url( $img_m, 'large' ) ) . '">';
		}
		echo wp_get_attachment_image( $img, 'full', false, array( 'class' => 'fx-cover', 'loading' => 'eager', 'fetchpriority' => 'high', 'alt' => '' ) ); // phpcs:ignore
		echo '</picture>';
	}
	echo '<div class="fx-chome__in fx-wrap">';
	echo '<h2 class="fx-chome__title">' . esc_html( $title ) . '</h2>';
	echo '<p class="fx-chome__sub">' . esc_html( fx_camp_fill( fx_camp( 'camp_sub_' . $mode ) ) ) . '</p>';
	echo '<div class="fx-chome__row">';
	if ( $count ) {
		echo '<span class="fx-chome__count" data-fx-hcount data-to="' . (int) $count * 1000 . '"><small>' . esc_html( 'before' === $mode ? 'Start za' : 'Do końca' ) . '</small> <b data-v>&nbsp;</b></span>';
	}
	if ( $link ) {
		echo '<a class="fx-btn fx-btn--white" href="' . esc_url( $link ) . '">' . esc_html( fx_camp( 'before' === $mode ? 'camp_home_cta_b' : 'camp_home_cta' ) ) . '</a>';
	}
	echo '</div></div></section>';
	?>
	<script>(function(){var c=document.querySelector('[data-fx-hcount]');if(!c)return;var T=+c.dataset.to,v=c.querySelector('[data-v]');function two(n){return(n<10?'0':'')+n}
	function tick(){var s=Math.max(0,Math.floor((T-Date.now())/1000)),d=Math.floor(s/86400),h=Math.floor(s%86400/3600),m=Math.floor(s%3600/60);s=s%60;v.textContent=(d>0?(d===1?'1 dzień ':d+' dni '):'')+two(h)+':'+two(m)+':'+two(s)}tick();setInterval(tick,1000)})();</script>
	<?php
}

/* =====================================================================
 * Ustawienia: Dostosuj → FOX.SKLEP.PL → Kampania (pasek i podstrona)
 * ===================================================================== */
add_action( 'customize_register', function ( $wp ) {
	$wp->add_section( 'fx_campaign', array(
		'title'       => 'Kampania (pasek i podstrona)',
		'panel'       => 'fx_panel',
		'description' => 'Daty kampanii ustawiasz w WooCommerce → Przeceny (od / do, włącznik). Pasek i strona z szablonem „Kampania (Black Week)” same przełączają się: zapowiedź → promocja → po kampanii. {czas} = odliczanie, {start} / {koniec} = daty.',
	) );
	$f = array(
		'camp_bar_on'       => array( 'checkbox', 'Pasek z odliczaniem nad nagłówkiem' ),
		'camp_bar_days'     => array( 'number', 'Pasek zapowiedzi – ile dni przed startem' ),
		'camp_bar_before'   => array( 'text', 'Pasek przed startem' ),
		'camp_bar_during'   => array( 'text', 'Pasek w trakcie' ),
		'camp_bar_after'    => array( 'text', 'Pasek po kampanii (puste = bez paska)' ),
		'camp_bar_after_to' => array( 'text', 'Pasek po kampanii – do dnia (RRRR-MM-DD)' ),
		'camp_bar_link'     => array( 'text', 'Link paska' ),
		'camp_h_before'     => array( 'text', 'Strona – nagłówek przed startem' ),
		'camp_sub_before'   => array( 'text', 'Strona – podtytuł przed startem' ),
		'camp_h_during'     => array( 'text', 'Strona – nagłówek w trakcie' ),
		'camp_sub_during'   => array( 'text', 'Strona – podtytuł w trakcie' ),
		'camp_h_after'      => array( 'text', 'Strona – nagłówek po kampanii' ),
		'camp_sub_after'    => array( 'text', 'Strona – podtytuł po kampanii' ),
		'camp_after_url'    => array( 'text', 'Po kampanii przekieruj (302) na adres – puste = strona „zakończony”' ),
		'camp_home_on'      => array( 'checkbox', 'Baner kampanii na stronie głównej (nad Moto | Rower) – teksty jak na stronie kampanii, przycisk prowadzi pod link paska' ),
		'camp_home_before'  => array( 'checkbox', 'Baner także jako zapowiedź przed startem (tyle dni, co pasek)' ),
		'camp_home_cta'     => array( 'text', 'Baner – przycisk w trakcie' ),
		'camp_home_cta_b'   => array( 'text', 'Baner – przycisk przed startem' ),
	);
	$d = fx_camp_defaults();
	foreach ( $f as $k => $c ) {
		$san = 'checkbox' === $c[0] ? 'fx_sanitize_bool' : ( 'number' === $c[0] ? 'absint' : 'sanitize_text_field' );
		$wp->add_setting( 'fx_' . $k, array( 'default' => $d[ $k ], 'sanitize_callback' => $san ) );
		$wp->add_control( 'fx_' . $k, array( 'section' => 'fx_campaign', 'label' => $c[1], 'type' => $c[0] ) );
	}
	foreach ( array( 'camp_home_img' => 'Baner – zdjęcie w tle (opcjonalnie, szerokie np. 2400×800)', 'camp_home_img_m' => 'Baner – zdjęcie na telefon (opcjonalnie, np. 1080×1080)' ) as $k => $label ) {
		$wp->add_setting( 'fx_' . $k, array( 'default' => 0, 'sanitize_callback' => 'absint' ) );
		$wp->add_control( new WP_Customize_Media_Control( $wp, 'fx_' . $k, array( 'section' => 'fx_campaign', 'label' => $label, 'mime_type' => 'image' ) ) );
	}
}, 40 );
