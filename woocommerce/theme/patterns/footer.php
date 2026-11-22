<?php
/**
 * Title: Rodapé da loja
 * Slug: arctron-loja/footer
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
<div class="arctron-footer">
	<div class="arctron-footer__inner">
		<div class="arctron-footer__brand">
			<a class="arctron-logo arctron-logo--light" href="<?php echo esc_url(home_url('/')); ?>"><?php echo arctron_logo(); ?><span><?php echo esc_html(get_bloginfo('name')); ?></span></a>
			<p>Chuteiras, bolas, camisas e acessórios para quem vive o futebol, do treino à final.</p>
		</div>
		<nav class="arctron-footer__col" aria-label="Categorias">
			<h2>Loja</h2>
			<ul>
				<li><a href="<?php echo esc_url(get_permalink(wc_get_page_id('shop'))); ?>">Todos os produtos</a></li>
				<?php foreach (is_array($arctron_categories) ? $arctron_categories : [] as $arctron_category) : ?>
				<li><a href="<?php echo esc_url(get_term_link($arctron_category)); ?>"><?php echo esc_html($arctron_category->name); ?></a></li>
				<?php endforeach; ?>
			</ul>
		</nav>
		<nav class="arctron-footer__col" aria-label="Sua conta">
			<h2>Sua conta</h2>
			<ul>
				<li><a href="<?php echo esc_url(get_permalink(wc_get_page_id('myaccount'))); ?>">Minha conta</a></li>
				<li><a href="<?php echo esc_url(get_permalink(wc_get_page_id('cart'))); ?>">Carrinho</a></li>
				<li><a href="<?php echo esc_url(get_permalink(wc_get_page_id('checkout'))); ?>">Finalizar compra</a></li>
			</ul>
		</nav>
		<div class="arctron-footer__col">
			<h2>Atendimento</h2>
			<ul>
				<li>Seg. a sex., das 9h às 18h</li>
				<li>atendimento@loja.test</li>
				<li>Rio de Janeiro · RJ</li>
			</ul>
		</div>
	</div>
	<div class="arctron-footer__bottom">
		<p>Loja de demonstração para testar a integração com o painel: os pedidos não são cobrados nem entregues.</p>
		<p>Fotos dos produtos: Wikimedia Commons (créditos na página de cada produto).</p>
	</div>
</div>
<!-- /wp:html -->
