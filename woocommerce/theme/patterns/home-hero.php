<?php
/**
 * Title: Página inicial: destaque principal
 * Slug: arctron-loja/home-hero
 * Inserter: no
 */

$arctron_hero_image = arctron_product_image_url('BOL-002');
?>
<!-- wp:html -->
<section class="arctron-hero">
	<div class="arctron-hero__inner">
		<div class="arctron-hero__text">
			<span class="arctron-eyebrow">Temporada 2026</span>
			<h1>Equipamento de campeão para o seu jogo.</h1>
			<p>Chuteiras, bolas oficiais, camisas da Seleção e tudo o que você precisa para entrar em campo, com entrega para todo o Brasil.</p>
			<div class="arctron-hero__actions">
				<a class="arctron-btn arctron-btn--yellow" href="<?php echo esc_url(get_permalink(wc_get_page_id('shop'))); ?>">Ver todos os produtos <?php echo arctron_icon('arrow'); ?></a>
				<a class="arctron-btn arctron-btn--ghost" href="<?php echo esc_url(arctron_category_url('camisas')); ?>">Camisas da Seleção</a>
			</div>
		</div>
		<?php if ($arctron_hero_image) : ?>
		<div class="arctron-hero__media">
			<img src="<?php echo esc_url($arctron_hero_image); ?>" alt="Bola Telstar 18" />
			<a class="arctron-hero__tag" href="<?php echo esc_url(get_permalink(wc_get_product_id_by_sku('BOL-002'))); ?>">
				<small>Bola oficial da Copa 2018</small>
				<strong>Telstar 18 · R$ 129,90</strong>
			</a>
		</div>
		<?php endif; ?>
	</div>
</section>

<!-- /wp:html -->
