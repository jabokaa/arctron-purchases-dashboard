<?php
/**
 * Tema da loja de desenvolvimento (filho do Twenty Twenty-Five).
 * O visual fica no theme.json (cores, fonte, botões), no style.css e nos padrões de patterns/.
 */

// Tema de bloco não carrega o style.css do tema filho sozinho
add_action('wp_enqueue_scripts', static function (): void {
    wp_enqueue_style('arctron-loja', get_stylesheet_uri(), [], wp_get_theme()->get('Version'));
});

/** Ícones (SVG inline, traço) usados nos padrões */
function arctron_icon(string $name): string
{
    $paths = [
        'truck'  => '<path d="M3 6h11v9H3zM14 9h4l3 3v3h-7"/><circle cx="7" cy="17" r="2"/><circle cx="17" cy="17" r="2"/>',
        'card'   => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M7 15h3"/>',
        'return' => '<path d="M4 12a8 8 0 1 0 2.3-5.6"/><path d="M4 4v4h4"/>',
        'shield' => '<path d="M12 3l8 3v6c0 4.5-3.4 8.3-8 9-4.6-.7-8-4.5-8-9V6z"/><path d="M9 12l2 2 4-4"/>',
        'arrow'  => '<path d="M5 12h14M13 6l6 6-6 6"/>',
    ];

    return '<svg class="arctron-icon" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths[$name] . '</svg>';
}

/** Marca da loja: escudo verde com uma bola */
function arctron_logo(): string
{
    return '<svg class="arctron-logo__mark" viewBox="0 0 40 40" width="36" height="36" aria-hidden="true">'
        . '<rect width="40" height="40" rx="11" fill="#0B6B43"/>'
        . '<circle cx="20" cy="20" r="11" fill="#F7C600"/>'
        . '<path d="M20 14.5l5.2 3.8-2 6.1h-6.4l-2-6.1z" fill="#06311F"/>'
        . '<path d="M20 9v5.5M25.2 18.3l5.3-1.7M23.2 24.4l3.3 4.5M16.8 24.4l-3.3 4.5M14.8 18.3l-5.3-1.7" stroke="#06311F" stroke-width="1.6"/>'
        . '</svg>';
}

/** URL da foto de um produto pelo SKU (vazio se o produto ainda não existir) */
function arctron_product_image_url(string $sku, string $size = 'large'): string
{
    $product = wc_get_product(wc_get_product_id_by_sku($sku));

    return $product ? (string) wp_get_attachment_image_url($product->get_image_id(), $size) : '';
}

/** Link de uma categoria de produto pelo slug (cai na loja se ela não existir) */
function arctron_category_url(string $slug): string
{
    $term = get_term_by('slug', $slug, 'product_cat');

    return $term ? (string) get_term_link($term) : (string) get_permalink(wc_get_page_id('shop'));
}

/**
 * Vitrine de produtos (bloco Product Collection) em 4 colunas.
 *
 * @param array<string, mixed> $query filtros do bloco, ex.: ['featured' => true] ou ['woocommerceOnSale' => true]
 */
function arctron_product_collection(int $query_id, array $query): string
{
    $attributes = [
        'queryId'     => $query_id,
        'query'       => array_merge([
            'perPage'                      => 4,
            'pages'                        => 1,
            'offset'                       => 0,
            'postType'                     => 'product',
            'order'                        => 'desc',
            'orderBy'                      => 'date',
            'search'                       => '',
            'exclude'                      => [],
            'inherit'                      => false,
            'taxQuery'                     => (object) [],
            'isProductCollectionBlock'     => true,
            'featured'                     => false,
            'woocommerceOnSale'            => false,
            'woocommerceStockStatus'       => ['instock', 'outofstock', 'onbackorder'],
            'woocommerceAttributes'        => [],
            'woocommerceHandPickedProducts' => [],
        ], $query),
        'tagName'       => 'div',
        'displayLayout' => ['type' => 'flex', 'columns' => 4, 'shrinkColumns' => true],
        'className'     => 'arctron-products',
    ];

    return '<!-- wp:woocommerce/product-collection ' . wp_json_encode($attributes) . ' -->
<div class="wp-block-woocommerce-product-collection arctron-products">
<!-- wp:woocommerce/product-template -->
<!-- wp:woocommerce/product-image {"showSaleBadge":true,"saleBadgeAlign":"left","imageSizing":"thumbnail","isDescendentOfQueryLoop":true} /-->
<!-- wp:post-terms {"term":"product_cat","className":"arctron-card__category"} /-->
<!-- wp:post-title {"level":3,"isLink":true,"className":"arctron-card__title","__woocommerceNamespace":"woocommerce/product-collection/product-title"} /-->
<!-- wp:woocommerce/product-price {"isDescendentOfQueryLoop":true} /-->
<!-- wp:woocommerce/product-button {"isDescendentOfQueryLoop":true} /-->
<!-- /wp:woocommerce/product-template -->
</div>
<!-- /wp:woocommerce/product-collection -->';
}
