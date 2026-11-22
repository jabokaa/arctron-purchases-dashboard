<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\IntegrationEvent;
use App\Entity\SalesChannel;
use App\Enum\IntegrationEventStatus;
use App\Exception\DuplicatePurchaseException;
use App\Integration\WooCommerce\OrderMapper;
use App\Message\ProcessWooCommerceOrder;
use App\Service\PurchaseServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Serializer\NameConverter\NameConverterInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Worker do webhook do WooCommerce: converte o pedido gravado em integration_events em uma compra.
 *
 * Roda fora da requisição, no container "worker" (php bin/console messenger:consume async).
 * Usa o MESMO caminho do POST /api/purchases: o pedido vira um PurchaseInput, passa pelas mesmas
 * validações e é gravado pelo mesmo PurchaseService, só que no canal WOOCOMMERCE.
 *
 * Resultado gravado no evento:
 * - processed: compra criada (o evento fica ligado à compra);
 * - ignored:   pedido já importado antes (mesmo código de referência), ou evento já processado;
 * - failed:    pedido inválido (ex.: sem CPF) ou erro inesperado. No erro inesperado (ex.: banco fora
 *              do ar) a exceção é relançada, e o Messenger tenta de novo (ver config/packages/messenger.yaml).
 */
#[AsMessageHandler]
final class ProcessWooCommerceOrderHandler
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly OrderMapper $orderMapper,
        private readonly ValidatorInterface $validator,
        private readonly PurchaseServiceInterface $purchaseService,
        private readonly LoggerInterface $logger,
        /** Mesmo conversor do serializer: os campos dos erros ficam no formato do JSON (unitPrice → unit_price) */
        #[Autowire(service: 'serializer.name_converter.camel_case_to_snake_case')]
        private readonly NameConverterInterface $nameConverter,
    ) {}

    public function __invoke(ProcessWooCommerceOrder $message): void
    {
        $event = $this->em()->find(IntegrationEvent::class, $message->integrationEventId);

        if (null === $event) {
            $this->logger->warning('Evento de integração {id} não encontrado.', ['id' => $message->integrationEventId]);

            return;
        }

        // Mensagem repetida de um evento que já terminou (compra criada ou ignorado): nada a fazer.
        // Eventos "failed" podem ser processados de novo (nova tentativa ou reprocessamento manual).
        if (\in_array($event->getStatus(), [IntegrationEventStatus::Processed, IntegrationEventStatus::Ignored], true)) {
            return;
        }

        // 1. Converte o pedido para o mesmo formato do POST /api/purchases
        try {
            $input = $this->orderMapper->toPurchaseInput($event->getPayload());
        } catch (\InvalidArgumentException $e) {
            $this->finish($event->getId(), static fn (IntegrationEvent $event) => $event->markFailed($e->getMessage()));

            return;
        }

        // 2. Mesmas regras de validação do endpoint de compras
        $violations = $this->validator->validate($input);
        if (\count($violations) > 0) {
            $errors = [];
            foreach ($violations as $violation) {
                $errors[] = $this->nameConverter->normalize($violation->getPropertyPath()).': '.$violation->getMessage();
            }

            $this->finish($event->getId(), static fn (IntegrationEvent $event) => $event->markFailed('Pedido inválido. '.implode(' ', $errors)));

            return;
        }

        // 3. Mesmo service do endpoint de compras, no canal WOOCOMMERCE
        try {
            $purchase = $this->purchaseService->create($input, SalesChannel::WOOCOMMERCE);
        } catch (DuplicatePurchaseException $e) {
            $this->finish($event->getId(), static fn (IntegrationEvent $event) => $event->markIgnored('Pedido já importado: '.$e->getMessage()));

            return;
        } catch (\Throwable $e) {
            $this->finish($event->getId(), static fn (IntegrationEvent $event) => $event->markFailed($e->getMessage()));

            throw $e; // erro inesperado: o Messenger tenta de novo
        }

        $this->finish($event->getId(), static fn (IntegrationEvent $event) => $event->markProcessed($purchase));
    }

    /**
     * Grava o resultado no evento.
     *
     * Se a gravação da compra falhou, o Doctrine fecha o EntityManager; nesse caso ele é reaberto
     * e o evento é buscado de novo antes de atualizar o status.
     *
     * @param \Closure(IntegrationEvent): void $update
     */
    private function finish(int $eventId, \Closure $update): void
    {
        if (!$this->em()->isOpen()) {
            $this->doctrine->resetManager();
        }

        $em = $this->em();
        $event = $em->find(IntegrationEvent::class, $eventId);

        $update($event);
        $em->flush();
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface */
        return $this->doctrine->getManager();
    }
}
