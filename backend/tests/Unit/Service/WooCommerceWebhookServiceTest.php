<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\IntegrationEvent;
use App\Enum\IntegrationEventStatus;
use App\Exception\InvalidWebhookPayloadException;
use App\Exception\InvalidWebhookSignatureException;
use App\Integration\WooCommerce\WebhookSignature;
use App\Message\ProcessWooCommerceOrder;
use App\Service\WooCommerceWebhookService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Testes unitários do WooCommerceWebhookService::receive() (recebimento do webhook).
 *
 * O que é testado: a ordem das etapas e o que acontece em cada caso:
 * - assinatura inválida ou corpo inválido: exceção, e NADA é gravado nem publicado;
 * - pedido criado (order.created): evento gravado como "received" e mensagem publicada com o id do evento;
 * - outro tópico: evento gravado como "ignored" e nenhuma mensagem publicada.
 *
 * Como é testado:
 * - É um teste unitário puro (TestCase): o Symfony não é iniciado e o banco não é acessado.
 * - A assinatura usa a classe real (WebhookSignature) com um segredo de teste.
 * - O EntityManager e o MessageBus são MOCKS; o persist() simula o banco gerando o id do evento.
 */
final class WooCommerceWebhookServiceTest extends TestCase
{
    private const SECRET = 'segredo-de-teste';

    private const BODY = '{"id":727,"status":"processing"}';

    /**
     * Pedido criado com assinatura válida.
     * Espera o evento gravado com origem, tópico, id da entrega, id do pedido e payload,
     * e a mensagem publicada na fila com o id do evento.
     */
    public function testStoresOrderCreatedAndPublishesMessage(): void
    {
        $em = $this->entityManagerThatGeneratesId(42, expectedPersists: 1);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(static fn (ProcessWooCommerceOrder $message): bool => 42 === $message->integrationEventId))
            ->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        $event = $this->service($em, $bus)->receive(self::BODY, self::sign(self::BODY), 'order.created', 'entrega-1');

        self::assertSame(42, $event->getId());
        self::assertSame(IntegrationEventStatus::Received, $event->getStatus());
        self::assertSame('woocommerce', $event->getSource());
        self::assertSame('order.created', $event->getTopic());
        self::assertSame('entrega-1', $event->getDeliveryId());
        self::assertSame('727', $event->getResourceId());
        self::assertSame(['id' => 727, 'status' => 'processing'], $event->getPayload());
        self::assertEqualsWithDelta(new \DateTimeImmutable(), $event->getReceivedAt(), 5, 'Recebido agora.');
        self::assertNull($event->getProcessedAt(), 'Ainda não processado pelo worker.');
    }

    /**
     * Assinatura inválida.
     * Espera InvalidWebhookSignatureException, sem gravar nem publicar nada.
     */
    public function testRejectsInvalidSignatureWithoutStoringAnything(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');

        $this->expectException(InvalidWebhookSignatureException::class);

        $this->service($em, $bus)->receive(self::BODY, 'assinatura-falsa', 'order.created', null);
    }

    /**
     * Corpo assinado corretamente, mas que não é um objeto JSON.
     * Espera InvalidWebhookPayloadException, sem gravar nem publicar nada.
     */
    public function testRejectsNonJsonBodyWithoutStoringAnything(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');

        $this->expectException(InvalidWebhookPayloadException::class);

        $this->service($em, $bus)->receive('"só um texto"', self::sign('"só um texto"'), 'order.created', null);
    }

    /**
     * Outro tópico (ex.: pedido atualizado).
     * Espera o evento gravado como "ignored", com o motivo, e nenhuma mensagem publicada.
     */
    public function testStoresOtherTopicsAsIgnoredWithoutPublishing(): void
    {
        $em = $this->entityManagerThatGeneratesId(7, expectedPersists: 1);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');

        $event = $this->service($em, $bus)->receive(self::BODY, self::sign(self::BODY), 'order.updated', null);

        self::assertSame(IntegrationEventStatus::Ignored, $event->getStatus());
        self::assertSame('Tópico "order.updated" não é processado; apenas "order.created".', $event->getError());
    }

    /**
     * Requisição sem o cabeçalho do tópico.
     * Espera o tópico gravado como "unknown" e o evento ignorado.
     */
    public function testStoresMissingTopicAsUnknown(): void
    {
        $em = $this->entityManagerThatGeneratesId(8, expectedPersists: 1);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');

        $event = $this->service($em, $bus)->receive(self::BODY, self::sign(self::BODY), null, null);

        self::assertSame('unknown', $event->getTopic());
        self::assertSame(IntegrationEventStatus::Ignored, $event->getStatus());
    }

    // =========================================================================
    // Auxiliares
    // =========================================================================

    private function service(EntityManagerInterface $em, MessageBusInterface $bus): WooCommerceWebhookService
    {
        return new WooCommerceWebhookService(new WebhookSignature(self::SECRET), $em, $bus);
    }

    private static function sign(string $body): string
    {
        return (new WebhookSignature(self::SECRET))->sign($body);
    }

    /**
     * EntityManager falso: o persist() preenche o id do evento, como o banco faria no flush.
     */
    private function entityManagerThatGeneratesId(int $id, int $expectedPersists): EntityManagerInterface
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->exactly($expectedPersists))
            ->method('persist')
            ->willReturnCallback(static function (IntegrationEvent $event) use ($id): void {
                (new \ReflectionProperty(IntegrationEvent::class, 'id'))->setValue($event, $id);
            });
        $em->expects($this->once())->method('flush');

        return $em;
    }
}
