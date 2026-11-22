<?php
/**
 * Title: Lista de produtos (loja e categorias)
 * Slug: arctron-loja/shop-archive
 * Inserter: no
 */
?>
<!-- wp:group {"className":"arctron-archive-head","layout":{"type":"default"}} -->
<div class="wp-block-group arctron-archive-head">
	<!-- wp:woocommerce/breadcrumbs /-->
	<!-- wp:query-title {"type":"archive","showPrefix":false} /-->
	<!-- wp:term-description /-->
	<!-- wp:group {"className":"arctron-archive-head__bar","layout":{"type":"flex","justifyContent":"space-between"}} -->
	<div class="wp-block-group arctron-archive-head__bar">
		<!-- wp:woocommerce/product-results-count /-->
		<!-- wp:woocommerce/catalog-sorting /-->
	</div>
	<!-- /wp:group -->
</div>
<!-- /wp:group -->
<?php
echo arctron_product_collection(10, ['inherit' => true, 'perPage' => 12, 'orderBy' => 'title', 'order' => 'asc']);
