<?php
/**
 * Title: Página inicial: vitrines (destaques, banner retrô e ofertas)
 * Slug: arctron-loja/home-products
 * Inserter: no
 */

$arctron_shop_url  = esc_url(get_permalink(wc_get_page_id('shop')));
$arctron_retro_img = arctron_product_image_url('CAM-001');
?>
<!-- wp:html -->
<section class="arctron-section">
	<header class="arctron-section__head">
		<div>
			<span class="arctron-eyebrow arctron-eyebrow--green">Destaques</span>
			<h2>Os mais pedidos da semana</h2>
		</div>
		<a class="arctron-link" href="<?php echo $arctron_shop_url; ?>">Ver todos <?php echo arctron_icon('arrow'); ?></a>
	</header>
<!-- /wp:html -->
<?php echo arctron_product_collection(1, ['featured' => true]); ?>
<!-- wp:html -->
</section>

<section class="arctron-promo">
	<div class="arctron-promo__text">
		<span class="arctron-eyebrow">Coleção retrô</span>
		<h2>Vista a história da Seleção.</h2>
		<p>As camisas do bi em 1962 e da Copa de 1966, em réplicas de algodão com o acabamento da época.</p>
		<a class="arctron-btn arctron-btn--yellow" href="<?php echo esc_url(arctron_category_url('camisas')); ?>">Conhecer a coleção <?php echo arctron_icon('arrow'); ?></a>
	</div>
	<?php if ($arctron_retro_img) : ?>
	<div class="arctron-promo__media"><img src="<?php echo esc_url($arctron_retro_img); ?>" alt="Camisa retrô da Seleção Brasileira de 1962" loading="lazy" /></div>
	<?php endif; ?>
</section>

<section class="arctron-section">
	<header class="arctron-section__head">
		<div>
			<span class="arctron-eyebrow arctron-eyebrow--green">Ofertas</span>
			<h2>Preço de final de campeonato</h2>
		</div>
		<a class="arctron-link" href="<?php echo $arctron_shop_url; ?>">Ver todos <?php echo arctron_icon('arrow'); ?></a>
	</header>
<!-- /wp:html -->
<?php echo arctron_product_collection(2, ['woocommerceOnSale' => true]); ?>
<!-- wp:html -->
</section>
<!-- /wp:html -->
