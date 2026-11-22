<?php
/**
 * Title: Cabeçalho da loja
 * Slug: arctron-loja/header
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
<!-- /wp:html -->

<!-- wp:group {"className":"arctron-header","layout":{"type":"constrained"}} -->
<div class="wp-block-group arctron-header">
	<!-- wp:group {"align":"wide","className":"arctron-header__bar","layout":{"type":"flex","flexWrap":"nowrap","justifyContent":"space-between"}} -->
	<div class="wp-block-group alignwide arctron-header__bar">
		<!-- wp:html -->
		<a class="arctron-logo" href="<?php echo esc_url(home_url('/')); ?>"><?php echo arctron_logo(); ?><span><?php echo esc_html(get_bloginfo('name')); ?></span></a>
		<!-- /wp:html -->

		<!-- wp:navigation {"overlayMenu":"mobile","className":"arctron-nav","layout":{"type":"flex","justifyContent":"center"}} -->
		<!-- wp:navigation-link {"label":"Início","url":"<?php echo esc_url(home_url('/')); ?>","kind":"custom"} /-->
		<!-- wp:navigation-link {"label":"Todos os produtos","url":"<?php echo esc_url(get_permalink(wc_get_page_id('shop'))); ?>","kind":"custom"} /-->
		<?php foreach (is_array($arctron_categories) ? $arctron_categories : [] as $arctron_category) : ?>
		<!-- wp:navigation-link {"label":"<?php echo esc_attr($arctron_category->name); ?>","url":"<?php echo esc_url(get_term_link($arctron_category)); ?>","kind":"custom"} /-->
		<?php endforeach; ?>
		<!-- /wp:navigation -->

		<!-- wp:group {"className":"arctron-header__actions","layout":{"type":"flex","flexWrap":"nowrap"}} -->
		<div class="wp-block-group arctron-header__actions">
			<!-- wp:woocommerce/customer-account {"displayStyle":"icon_only","iconStyle":"line","iconClass":"wc-block-customer-account__account-icon"} /-->
			<!-- wp:woocommerce/mini-cart /-->
		</div>
		<!-- /wp:group -->
	</div>
	<!-- /wp:group -->
</div>
<!-- /wp:group -->
