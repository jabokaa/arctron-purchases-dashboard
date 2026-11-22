<?php
/**
 * Title: Página inicial: categorias
 * Slug: arctron-loja/home-categories
 * Inserter: no
 */

$arctron_categories = get_terms([
    'taxonomy'   => 'product_cat',
    'hide_empty' => true,
    'exclude'    => [(int) get_option('default_product_cat')],
    'meta_key'   => 'order',
    'orderby'    => 'meta_value_num',
]);
?>
<!-- wp:html -->
<section class="arctron-section">
	<header class="arctron-section__head">
		<div>
			<span class="arctron-eyebrow arctron-eyebrow--green">Categorias</span>
			<h2>Escolha sua posição</h2>
		</div>
	</header>
	<div class="arctron-categories">
		<?php foreach (is_array($arctron_categories) ? $arctron_categories : [] as $arctron_category) :
			$arctron_image = wp_get_attachment_image_url((int) get_term_meta($arctron_category->term_id, 'thumbnail_id', true), 'large'); ?>
		<a class="arctron-category" href="<?php echo esc_url(get_term_link($arctron_category)); ?>">
			<?php if ($arctron_image) : ?><img src="<?php echo esc_url($arctron_image); ?>" alt="" loading="lazy" /><?php endif; ?>
			<span class="arctron-category__text">
				<strong><?php echo esc_html($arctron_category->name); ?></strong>
				<small><?php echo esc_html(sprintf(_n('%d produto', '%d produtos', $arctron_category->count, 'arctron-loja'), $arctron_category->count)); ?></small>
			</span>
		</a>
		<?php endforeach; ?>
	</div>
</section>
<!-- /wp:html -->
