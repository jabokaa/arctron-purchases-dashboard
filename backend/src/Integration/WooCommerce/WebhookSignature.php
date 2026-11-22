<?php

declare(strict_types=1);

namespace App\Integration\WooCommerce;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Validação da assinatura dos webhooks do WooCommerce.
 *
 * O WooCommerce envia no cabeçalho X-WC-Webhook-Signature o HMAC-SHA256 do corpo da requisição,
 * em base64, calculado com o "segredo" cadastrado no webhook. Recalculando com o mesmo segredo,
 * dá para garantir que a requisição veio do WooCommerce e que o corpo não foi alterado.
 */
final class WebhookSignature
{
    public function __construct(
        #[Autowire('%env(WOOCOMMERCE_WEBHOOK_SECRET)%')]
        private readonly string $secret,
    ) {}

    public function isValid(string $body, ?string $signature): bool
    {
        if (null === $signature || '' === $signature || '' === $this->secret) {
            return false;
        }

        // hash_equals compara em tempo constante, para não dar pistas da assinatura certa pelo tempo de resposta
        return hash_equals($this->sign($body), $signature);
    }

    /**
     * Assinatura esperada para o corpo: base64(HMAC-SHA256(corpo, segredo)), igual à do WooCommerce.
     */
    public function sign(string $body): string
    {
        return base64_encode(hash_hmac('sha256', $body, $this->secret, true));
    }
}
