<?php
/**
 * Catálogo da loja de desenvolvimento: categorias, produtos (com foto, descrição e preço) e páginas em português.
 * Roda pelo setup.sh (wp eval-file). Pode rodar de novo: atualiza o que já existe e só importa a foto que falta.
 *
 * Os produtos são os mesmos do catálogo do painel (o SKU é o código interno) e as fotos são as do seeder do backend
 * (backend/src/DataFixtures/images/products, montada em /products), com os créditos na descrição de cada produto.
 */

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

const ARCTRON_IMAGES_DIR = '/products';

/** slug => [nome, descrição, ordem] */
$categories = [
    'chuteiras'  => ['Chuteiras', 'Tração e controle para campo e society.', 1],
    'bolas'      => ['Bolas', 'Oficiais, de treino e edições históricas.', 2],
    'camisas'    => ['Camisas', 'Seleção Brasileira atual e retrô.', 3],
    'acessorios' => ['Acessórios', 'Goleiro, arbitragem e campo.', 4],
];

/**
 * sku => [nome, categoria, preço, preço promocional|null, destaque, resumo, descrição, crédito da foto]
 * Os preços são os mesmos do seeder de compras do painel.
 */
$products = [
    'CHU-001' => ['Chuteira de Campo Speedster', 'chuteiras', '299.90', null, true,
        'Leve e rápida, com travas de alta tração para gramado natural.',
        'Cabedal sintético macio que se ajusta ao pé, palmilha anatômica e solado com travas cônicas e laminadas para arrancadas e mudanças de direção em campo.',
        'Foto: Methem (Mikko J. Putkonen), CC BY 4.0, Wikimedia Commons'],
    'CHU-002' => ['Chuteira Clássica de Couro', 'chuteiras', '249.90', '219.90', false,
        'Couro legítimo e acabamento tradicional para quem gosta do toque na bola.',
        'Modelo clássico em couro, costuras reforçadas e lingueta longa. Amacia com o uso e oferece sensibilidade no passe e no chute.',
        'Foto: Andy Hay, CC BY 2.0, Wikimedia Commons'],
    'BOL-001' => ['Bola de Futebol de Campo', 'bolas', '89.90', null, false,
        'Bola tamanho 5 para treinos e peladas, com ótima durabilidade.',
        'Gomos termossoldados, câmara de butil que segura a pressão por mais tempo e superfície texturizada para melhor controle.',
        'Foto: Christopher Bruno, CC BY-SA 3.0, Wikimedia Commons'],
    'BOL-002' => ['Bola Telstar 18 – Copa do Mundo 2018', 'bolas', '129.90', null, true,
        'A bola oficial da Copa da Rússia, com o desenho em mosaico.',
        'Réplica da bola da Copa do Mundo de 2018, com painéis termossoldados e o visual pixelado que homenageia a primeira Telstar.',
        'Foto: Кирилл Венедиктов, CC BY-SA 3.0, Wikimedia Commons'],
    'BOL-003' => ['Bola Retrô Telstar 1970 – Tri do Brasil', 'bolas', '179.90', '159.90', false,
        'O clássico preto e branco da Copa do tri, em versão de coleção.',
        'Releitura da bola usada no México em 1970, com 32 gomos em preto e branco. Peça de colecionador para quem viveu (ou sonha com) o tri.',
        'Foto: Zac allan, domínio público, Wikimedia Commons'],
    'LUV-001' => ['Luvas de Goleiro', 'acessorios', '159.90', null, true,
        'Látex de alta aderência e fecho em velcro para defesas seguras.',
        'Palma em látex de 4 mm, corte negativo para mais área de contato e punho com fecho ajustável que dá firmeza ao pulso.',
        'Foto: Santeri Viinamäki, CC BY-SA 4.0, Wikimedia Commons'],
    'CAM-001' => ['Camisa Retrô Seleção Brasileira 1962', 'camisas', '399.90', null, false,
        'A amarelinha do bicampeonato no Chile, com gola careca.',
        'Réplica da camisa do bi em 1962, em algodão, com gola careca verde e o escudo bordado.',
        'Foto: Rjcastillo, CC BY-SA 4.0, Wikimedia Commons'],
    'CAM-002' => ['Camisa Retrô Seleção Brasileira 1966', 'camisas', '399.90', '349.90', false,
        'O modelo usado na Copa da Inglaterra, com gola em V.',
        'Réplica da camisa de 1966, em algodão, com gola em V verde e acabamento retrô.',
        'Foto: Rjcastillo, CC BY-SA 4.0, Wikimedia Commons'],
    'CAM-003' => ['Camisa Seleção Brasileira – Uniforme Titular', 'camisas', '349.90', null, true,
        'O uniforme titular da Seleção, em tecido leve que seca rápido.',
        'Tecido tecnológico com microfuros para ventilação, modelagem atlética e escudo aplicado.',
        'Foto: BrokenSphere, CC BY-SA 3.0, Wikimedia Commons'],
    'ARB-001' => ['Apito de Árbitro', 'acessorios', '29.90', null, false,
        'Som alto e agudo, ouvido de qualquer canto do campo.',
        'Apito metálico com cordão, som potente e sem esfera interna, que não falha em dias de chuva.',
        'Foto: Laslovarga, CC BY-SA 4.0, Wikimedia Commons'],
    'RED-001' => ['Rede de Gol para Campo', 'acessorios', '199.90', '179.90', false,
        'Par de redes em fio de nylon para traves oficiais.',
        'Fio de nylon de 4 mm resistente a sol e chuva, malha de 12 cm e medidas para traves oficiais de 7,32 x 2,44 m.',
        'Foto: domínio público, Wikimedia Commons'],
];

// Categorias (com a ordem do menu) ----------------------------------------------------------------------------
$category_ids = [];
foreach ($categories as $slug => [$name, $description, $order]) {
    $term = get_term_by('slug', $slug, 'product_cat');
    $term_id = $term
        ? wp_update_term($term->term_id, 'product_cat', ['name' => $name, 'description' => $description])['term_id']
        : wp_insert_term($name, 'product_cat', ['slug' => $slug, 'description' => $description])['term_id'];
    update_term_meta($term_id, 'order', $order);
    $category_ids[$slug] = $term_id;
}

// A categoria padrão ("Uncategorized") vira "Outros" e some do menu quando estiver vazia
$default_cat = (int) get_option('default_product_cat');
if ($default_cat) {
    wp_update_term($default_cat, 'product_cat', ['name' => 'Outros', 'slug' => 'outros']);
}

// Produtos ---------------------------------------------------------------------------------------------------
function arctron_import_image(string $sku, string $title): int
{
    $source = ARCTRON_IMAGES_DIR . '/' . $sku . '.jpg';
    if (!is_readable($source)) {
        WP_CLI::warning("Foto de {$sku} não encontrada em {$source}");

        return 0;
    }

    // media_handle_sideload move o arquivo: trabalha numa cópia
    $tmp = wp_tempnam($sku . '.jpg');
    copy($source, $tmp);
    $id = media_handle_sideload(['name' => strtolower($sku) . '.jpg', 'tmp_name' => $tmp], 0, $title);
    if (is_wp_error($id)) {
        WP_CLI::warning("Foto de {$sku}: " . $id->get_error_message());

        return 0;
    }
    update_post_meta($id, '_wp_attachment_image_alt', $title);

    return $id;
}

$first_image_of_category = [];
foreach ($products as $sku => [$name, $category, $price, $sale_price, $featured, $short, $description, $credit]) {
    $id = wc_get_product_id_by_sku($sku);
    $product = $id ? wc_get_product($id) : new WC_Product_Simple();

    $product->set_sku($sku);
    $product->set_name($name);
    $product->set_status('publish');
    $product->set_regular_price($price);
    $product->set_sale_price($sale_price ?? '');
    $product->set_featured($featured);
    $product->set_short_description($short);
    $product->set_description($description . "\n\n<small>{$credit}</small>");
    $product->set_category_ids([$category_ids[$category]]);
    $product->set_manage_stock(false);
    $product->set_stock_status('instock');

    if (!$product->get_image_id()) {
        $product->set_image_id(arctron_import_image($sku, $name));
    }

    $product->save();
    $first_image_of_category[$category] ??= $product->get_image_id();
    WP_CLI::log(($id ? 'Atualizado ' : 'Criado ') . "{$sku} {$name}");
}

// Foto da categoria = foto do primeiro produto dela (aparece nos cards da página inicial)
foreach ($first_image_of_category as $slug => $image_id) {
    update_term_meta($category_ids[$slug], 'thumbnail_id', $image_id);
}

// Páginas em português ---------------------------------------------------------------------------------------
$pages = [
    'shop'      => ['Loja', 'loja'],
    'cart'      => ['Carrinho', 'carrinho'],
    'checkout'  => ['Finalizar compra', 'finalizar-compra'],
    'myaccount' => ['Minha conta', 'minha-conta'],
];
foreach ($pages as $woo_page => [$title, $slug]) {
    $page_id = wc_get_page_id($woo_page);
    if ($page_id > 0) {
        wp_update_post(['ID' => $page_id, 'post_title' => $title, 'post_name' => $slug]);
    }
}

// Conteúdo de exemplo do WordPress não faz sentido numa loja
foreach (['hello-world' => 'post', 'sample-page' => 'page'] as $slug => $type) {
    $post = get_page_by_path($slug, OBJECT, $type);
    if ($post) {
        wp_delete_post($post->ID, true);
    }
}

WP_CLI::success('Catálogo pronto: ' . count($products) . ' produtos em ' . count($categories) . ' categorias');
