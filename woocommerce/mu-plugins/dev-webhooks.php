<?php
/**
 * Plugin Name: Dev webhooks (SÓ DESENVOLVIMENTO)
 * Description: Deixa o WooCommerce entregar webhooks para o backend dentro da rede do Docker.
 *
 * NÃO use em produção: ele desliga proteções do WordPress contra requisições a hosts internos.
 */

// O WooCommerce envia webhooks com wp_safe_remote_request, que recusa hosts com IP privado
// (como http://backend dentro da rede do Docker). Este filtro libera.
add_filter('http_request_host_is_external', '__return_true');

// A mesma função também só aceita as portas 80, 443 e 8080; o backend escuta na 8000.
add_filter('http_allowed_safe_ports', static function (array $ports): array {
    $ports[] = 8000;

    return $ports;
});

// Entrega o webhook na hora, sem esperar o Action Scheduler (que só roda quando alguém acessa o site).
add_filter('woocommerce_webhook_deliver_async', '__return_false');

// Não há servidor de e-mail no ambiente de desenvolvimento: evita o erro "sendmail: can't connect" a cada pedido.
add_filter('pre_wp_mail', '__return_true');
