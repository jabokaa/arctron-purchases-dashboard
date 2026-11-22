<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Dto\PurchaseInput;
use App\Entity\IntegrationEvent;
use App\Entity\Purchase;
use App\Enum\IntegrationEventStatus;
use App\Exception\DuplicatePurchaseException;
use App\Integration\WooCommerce\OrderMapper;
use App\Message\ProcessWooCommerceOrder;
use App\MessageHandler\ProcessWooCommerceOrderHandler;
use App\Service\PurchaseServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;
use Symfony\Component\Validator\Validation;

/**
 * Testes unitários do ProcessWooCommerceOrderHandler (worker que converte o pedido em compra).
 *
 * O que é testado: o status final gravado no evento em cada situação, e se o PurchaseService é
 * chamado (ou não) no canal WOOCOMMERCE:
 * - pedido válido → processed, ligado à compra;
 * - pedido inválido (validação ou valor monetário ilegível) → failed, com o motivo, sem chamar o service;
 * - pedido já importado → ignored;
 * - erro inesperado → failed e a exceção é relançada (o Messenger tenta de novo), reabrindo o
 *   EntityManager se o Doctrine o fechou;
 * - evento já processado ou inexistente → nada é feito; evento que falhou antes → é processado de novo.
 *
 * Como é testado:
 * - É um teste unitário puro (TestCase): o Symfony não é iniciado e o banco não é acessado.
 * - O conversor (OrderMapper) e o validador são os reais, com as mesmas regras do POST /api/purchases.
 * - O EntityManager, o ManagerRegistry e o PurchaseService são STUBS/MOCKS.
 */
final class ProcessWooCommerceOrderHandlerTest extends TestCase
{
    private const EVENT_ID = 10;

    /**
     * Pedido válido.
     * Espera o PurchaseService chamado uma vez no canal WOOCOMMERCE e o evento processed, ligado à compra.
     */
    public function testProcessesValidOrder(): void
    {
        $event = self::event(self::order());
        $purchase = new Purchase();

        $purchaseService = $this->createMock(PurchaseServiceInterface::class);
        $purchaseService->expects($this->once())
            ->method('create')
            ->with($this->callback(static fn (PurchaseInput $input): bool => 'WC-727' === $input->referenceCode), 'WOOCOMMERCE')
            ->willReturn($purchase);

        $this->handler($event, $purchaseService)(new ProcessWooCommerceOrder(self::EVENT_ID));

        self::assertSame(IntegrationEventStatus::Processed, $event->getStatus());
        self::assertSame($purchase, $event->getPurchase());
        self::assertNull($event->getError());
        self::assertNotNull($event->getProcessedAt());
    }

    /**
     * Pedido sem CPF/CNPJ (não passa na validação do endpoint de compras).
     * Espera o evento failed com o campo e a mensagem do erro, e o service nunca chamado.
     */
    public function testFailsInvalidOrderWithValidationMessage(): void
    {
        $order = self::order();
        unset($order['billing']['cpf']);
        $event = self::event($order);

        $purchaseService = $this->createMock(PurchaseServiceInterface::class);
        $purchaseService->expects($this->never())->method('create');

        $this->handler($event, $purchaseService)(new ProcessWooCommerceOrder(self::EVENT_ID));

        self::assertSame(IntegrationEventStatus::Failed, $event->getStatus());
        self::assertSame('Pedido inválido. customer.cpf: Informe o CPF (pessoa física) ou o CNPJ (pessoa jurídica) do cliente.', $event->getError());
    }

    /**
     * Valor monetário ilegível no pedido.
     * Espera o evento failed com o motivo, e o service nunca chamado.
     */
    public function testFailsOrderWithInvalidMoneyValue(): void
    {
        $order = self::order();
        $order['line_items'][0]['subtotal'] = 'cem reais';
        $event = self::event($order);

        $purchaseService = $this->createMock(PurchaseServiceInterface::class);
        $purchaseService->expects($this->never())->method('create');

        $this->handler($event, $purchaseService)(new ProcessWooCommerceOrder(self::EVENT_ID));

        self::assertSame(IntegrationEventStatus::Failed, $event->getStatus());
        self::assertSame('Valor monetário inválido: "cem reais".', $event->getError());
    }

    /**
     * Pedido já importado antes (o service informa compra duplicada).
     * Espera o evento ignored com o motivo, sem relançar a exceção (não é para tentar de novo).
     */
    public function testIgnoresOrderAlreadyImported(): void
    {
        $event = self::event(self::order());

        $purchaseService = $this->createStub(PurchaseServiceInterface::class);
        $purchaseService->method('create')->willThrowException(new DuplicatePurchaseException('WC-727'));

        $this->handler($event, $purchaseService)(new ProcessWooCommerceOrder(self::EVENT_ID));

        self::assertSame(IntegrationEventStatus::Ignored, $event->getStatus());
        self::assertSame('Pedido já importado: Já existe uma compra com o código de referência "WC-727".', $event->getError());
    }

    /**
     * Erro inesperado ao gravar a compra (ex.: banco fora do ar), com o EntityManager fechado pelo Doctrine.
     * Espera o EntityManager reaberto, o evento failed com a mensagem do erro e a exceção relançada.
     */
    public function testFailsAndRethrowsUnexpectedErrors(): void
    {
        $event = self::event(self::order());
        $error = new \RuntimeException('Conexão com o banco perdida');

        $purchaseService = $this->createStub(PurchaseServiceInterface::class);
        $purchaseService->method('create')->willThrowException($error);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturn($event);
        $em->method('isOpen')->willReturn(false); // o Doctrine fecha o EntityManager quando a transação falha

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($em);
        $registry->expects($this->once())->method('resetManager');

        try {
            $this->handler($event, $purchaseService, $registry)(new ProcessWooCommerceOrder(self::EVENT_ID));
            self::fail('A exceção deveria ser relançada para o Messenger tentar de novo.');
        } catch (\RuntimeException $e) {
            self::assertSame($error, $e);
        }

        self::assertSame(IntegrationEventStatus::Failed, $event->getStatus());
        self::assertSame('Conexão com o banco perdida', $event->getError());
    }

    /**
     * Mensagem repetida de um evento já processado.
     * Espera que o service nunca seja chamado e o evento continue como estava.
     */
    public function testSkipsEventAlreadyProcessed(): void
    {
        $event = self::event(self::order());
        $event->markProcessed(new Purchase());

        $purchaseService = $this->createMock(PurchaseServiceInterface::class);
        $purchaseService->expects($this->never())->method('create');

        $this->handler($event, $purchaseService)(new ProcessWooCommerceOrder(self::EVENT_ID));

        self::assertSame(IntegrationEventStatus::Processed, $event->getStatus());
    }

    /**
     * Evento que falhou antes (ex.: erro temporário) sendo processado de novo.
     * Espera que o service seja chamado e o evento fique processed.
     */
    public function testReprocessesFailedEvent(): void
    {
        $event = self::event(self::order());
        $event->markFailed('Erro anterior');

        $purchaseService = $this->createMock(PurchaseServiceInterface::class);
        $purchaseService->expects($this->once())->method('create')->willReturn(new Purchase());

        $this->handler($event, $purchaseService)(new ProcessWooCommerceOrder(self::EVENT_ID));

        self::assertSame(IntegrationEventStatus::Processed, $event->getStatus());
        self::assertNull($event->getError());
    }

    /**
     * Mensagem de um evento que não existe mais no banco.
     * Espera que nada seja feito (o service nunca é chamado) e nenhuma exceção.
     */
    public function testDoesNothingWhenEventDoesNotExist(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturn(null);

        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($em);

        $purchaseService = $this->createMock(PurchaseServiceInterface::class);
        $purchaseService->expects($this->never())->method('create');

        $this->handler(null, $purchaseService, $registry)(new ProcessWooCommerceOrder(self::EVENT_ID));
    }

    // =========================================================================
    // Auxiliares
    // =========================================================================

    /**
     * Cria o handler. Sem registry informado, usa um EntityManager aberto que devolve o evento.
     */
    private function handler(?IntegrationEvent $event, PurchaseServiceInterface $purchaseService, ?ManagerRegistry $registry = null): ProcessWooCommerceOrderHandler
    {
        if (null === $registry) {
            $em = $this->createStub(EntityManagerInterface::class);
            $em->method('find')->willReturn($event);
            $em->method('isOpen')->willReturn(true);

            $registry = $this->createStub(ManagerRegistry::class);
            $registry->method('getManager')->willReturn($em);
        }

        return new ProcessWooCommerceOrderHandler(
            $registry,
            new OrderMapper(),
            Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator(),
            $purchaseService,
            new NullLogger(),
            new CamelCaseToSnakeCaseNameConverter(),
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function event(array $payload): IntegrationEvent
    {
        $event = new IntegrationEvent(IntegrationEvent::SOURCE_WOOCOMMERCE, 'order.created', $payload);
        (new \ReflectionProperty(IntegrationEvent::class, 'id'))->setValue($event, self::EVENT_ID);

        return $event;
    }

    /**
     * Pedido válido no formato do WooCommerce.
     *
     * @return array<string, mixed>
     */
    private static function order(): array
    {
        return [
            'id' => 727,
            'date_created_gmt' => '2026-11-21T17:30:00',
            'billing' => ['first_name' => 'João', 'last_name' => 'Silva', 'email' => 'joao@email.com', 'cpf' => '529.982.247-25'],
            'line_items' => [
                ['name' => 'Camiseta', 'product_id' => 93, 'quantity' => 2, 'subtotal' => '100.00', 'total' => '90.00'],
            ],
        ];
    }
}
