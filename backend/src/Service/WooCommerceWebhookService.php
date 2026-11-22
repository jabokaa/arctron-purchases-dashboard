<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\IntegrationEvent;
use App\Exception\InvalidWebhookPayloadException;
use App\Exception\InvalidWebhookSignatureException;
use App\Integration\WooCommerce\WebhookSignature;
use App\Message\ProcessWooCommerceOrder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Recebimento dos webhooks do WooCommerce: valida, grava e publica na fila.
 *
 * O processamento do pedido (criar cliente, produtos e compra) NÃO acontece aqui: fica com o worker
 * (ProcessWooCommerceOrderHandler). Assim o webhook responde na hora (202), e uma demora ou erro
 * no processamento não faz o WooCommerce reenviar o pedido.
 */
final class WooCommerceWebhookService implements WooCommerceWebhookServiceInterface
{
    /** Único tópico que vira compra; os outros são gravados como "ignored" */
    public const ORDER_CREATED_TOPIC = 'order.created';

    public function __construct(
        private readonly WebhookSignature $signature,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
    ) {}

    public function receive(string $body, ?string $signature, ?string $topic, ?string $deliveryId): IntegrationEvent
    {
        // 1. Só aceita o que veio mesmo do WooCommerce, com o corpo intacto
        if (!$this->signature->isValid($body, $signature)) {
            throw new InvalidWebhookSignatureException();
        }

        // 2. O corpo precisa ser um objeto JSON para ser gravado
        $payload = json_decode($body, true);
        if (!\is_array($payload)) {
            throw new InvalidWebhookPayloadException();
        }

        // 3. Grava o payload original antes de qualquer processamento
        $event = new IntegrationEvent(
            source: IntegrationEvent::SOURCE_WOOCOMMERCE,
            topic: $topic ?? 'unknown',
            payload: $payload,
            deliveryId: $deliveryId,
            resourceId: isset($payload['id']) ? (string) $payload['id'] : null,
        );

        $isOrderCreated = self::ORDER_CREATED_TOPIC === $topic;
        if (!$isOrderCreated) {
            $event->markIgnored(\sprintf('Tópico "%s" não é processado; apenas "%s".', $event->getTopic(), self::ORDER_CREATED_TOPIC));
        }

        $this->em->persist($event);
        $this->em->flush();

        // 4. Publica na fila (RabbitMQ) para o worker converter o pedido em compra
        if ($isOrderCreated) {
            $this->bus->dispatch(new ProcessWooCommerceOrder($event->getId()));
        }

        return $event;
    }
}
