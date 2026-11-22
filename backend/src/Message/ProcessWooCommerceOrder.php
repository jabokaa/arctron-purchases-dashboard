<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Mensagem publicada no RabbitMQ quando o webhook do WooCommerce recebe um pedido.
 *
 * Leva só o id do evento gravado em integration_events: o payload completo fica no banco,
 * e o worker busca de lá. Assim a mensagem é pequena e o evento pode ser reprocessado.
 */
final class ProcessWooCommerceOrder
{
    public function __construct(
        public readonly int $integrationEventId,
    ) {}
}
