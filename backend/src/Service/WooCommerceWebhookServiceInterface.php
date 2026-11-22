<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\IntegrationEvent;
use App\Exception\InvalidWebhookPayloadException;
use App\Exception\InvalidWebhookSignatureException;

/**
 * Contrato do recebimento dos webhooks do WooCommerce.
 *
 * O controller depende desta interface, o que permite trocar a implementação por um mock nos testes.
 */
interface WooCommerceWebhookServiceInterface
{
    /**
     * Valida a assinatura, grava o evento em integration_events e, se for um pedido criado,
     * publica a mensagem na fila para o worker processar.
     *
     * @throws InvalidWebhookSignatureException assinatura ausente ou inválida (nada é gravado)
     * @throws InvalidWebhookPayloadException   corpo que não é um objeto JSON (nada é gravado)
     */
    public function receive(string $body, ?string $signature, ?string $topic, ?string $deliveryId): IntegrationEvent;
}
