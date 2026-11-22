<?php
/**
 * Cria um pedido de teste (pago na entrega) com CPF ou CNPJ, sem precisar passar pelo checkout.
 * O WooCommerce dispara o webhook "Pedido criado" e o pedido chega no painel como uma compra.
 *
 * Uso (na pasta woocommerce/):
 *   docker compose run --rm wp_cli php /usr/local/bin/wp eval-file /scripts/create-test-order.php cpf
 *   docker compose run --rm wp_cli php /usr/local/bin/wp eval-file /scripts/create-test-order.php cnpj
 */

/** Dígito verificador do CPF/CNPJ pelo módulo 11, com os pesos dados */
function arctron_check_digit(array $digits, array $weights): int
{
    $sum = 0;
    foreach ($digits as $i => $digit) {
        $sum += $digit * $weights[$i];
    }
    $rest = $sum % 11;

    return $rest < 2 ? 0 : 11 - $rest;
}

function arctron_random_cpf(): string
{
    $digits = [];
    for ($i = 0; $i < 9; ++$i) {
        $digits[] = random_int(0, 9);
    }
    $digits[] = arctron_check_digit($digits, [10, 9, 8, 7, 6, 5, 4, 3, 2]);
    $digits[] = arctron_check_digit($digits, [11, 10, 9, 8, 7, 6, 5, 4, 3, 2]);

    return vsprintf('%d%d%d.%d%d%d.%d%d%d-%d%d', $digits);
}

function arctron_random_cnpj(): string
{
    $digits = [];
    for ($i = 0; $i < 8; ++$i) {
        $digits[] = random_int(0, 9);
    }
    array_push($digits, 0, 0, 0, 1);
    $digits[] = arctron_check_digit($digits, [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]);
    $digits[] = arctron_check_digit($digits, [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]);

    return vsprintf('%d%d.%d%d%d.%d%d%d/%d%d%d%d-%d%d', $digits);
}

$type = strtolower($args[0] ?? 'cpf');
if (!in_array($type, ['cpf', 'cnpj'], true)) {
    WP_CLI::error('Use "cpf" ou "cnpj".');
}

$suffix = strtolower(wp_generate_password(6, false));

// Os itens e os dados são colocados ANTES do primeiro save(): é nele que o WooCommerce dispara o webhook,
// e o pedido já precisa estar completo.
$order = new WC_Order();

foreach (['CHU-001' => 1, 'BOL-001' => 2] as $sku => $quantity) {
    $productId = wc_get_product_id_by_sku($sku);
    if (!$productId) {
        WP_CLI::error("Produto $sku não encontrado. Rode o setup antes (docker compose up -d).");
    }
    $order->add_product(wc_get_product($productId), $quantity);
}

$billing = [
    'first_name' => 'cpf' === $type ? 'Cliente' : 'Responsável',
    'last_name' => "Teste $suffix",
    'email' => "cliente.$suffix@exemplo.com",
    'phone' => '(21) 99999-0000',
    'address_1' => 'Rua das Laranjeiras, 100',
    'city' => 'Rio de Janeiro',
    'state' => 'RJ',
    'postcode' => '22240-003',
    'country' => 'BR',
];
if ('cnpj' === $type) {
    $billing['company'] = "Esportes $suffix Ltda";
}
$order->set_address($billing, 'billing');

// Mesmos campos que o plugin Brazilian Market grava no checkout
if ('cpf' === $type) {
    $order->update_meta_data('_billing_persontype', '1');
    $order->update_meta_data('_billing_cpf', arctron_random_cpf());
} else {
    $order->update_meta_data('_billing_persontype', '2');
    $order->update_meta_data('_billing_cnpj', arctron_random_cnpj());
}

$order->set_payment_method('cod');
$order->set_payment_method_title('Pagamento na entrega');
$order->set_customer_note('Pedido de teste criado pelo script create-test-order.php');
$order->calculate_totals();
$order->set_status('processing');
$order->save();

WP_CLI::success(sprintf('Pedido #%d criado (%s). Total: %s', $order->get_id(), strtoupper($type), html_entity_decode(wp_strip_all_tags(wc_price($order->get_total())))));
