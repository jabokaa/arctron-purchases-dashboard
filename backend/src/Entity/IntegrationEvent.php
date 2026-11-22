<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\IntegrationEventStatus;
use App\Repository\IntegrationEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Evento recebido de uma integração externa (ex.: webhook de pedido do WooCommerce).
 *
 * O conteúdo do payload é guardado como veio, antes de qualquer processamento. Assim é possível
 * auditar o que chegou, entender um erro e reprocessar o evento, mesmo que a conversão falhe.
 * (A coluna é JSONB: o PostgreSQL guarda o mesmo conteúdo, mas não mantém espaços nem a ordem das chaves.)
 */
#[ORM\Entity(repositoryClass: IntegrationEventRepository::class)]
#[ORM\Table(name: 'integration_events', options: ['comment' => 'Eventos recebidos de integrações externas (ex.: webhooks do WooCommerce)'])]
#[ORM\Index(name: 'idx_integration_events_status', columns: ['status'])]
#[ORM\Index(name: 'idx_integration_events_source_resource', columns: ['source', 'resource_id'])]
class IntegrationEvent
{
    public const SOURCE_WOOCOMMERCE = 'woocommerce';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(length: 50, options: ['comment' => 'Origem do evento, ex.: woocommerce'])]
    private string $source;

    #[ORM\Column(length: 100, options: ['comment' => 'Tipo do evento enviado pela origem, ex.: order.created'])]
    private string $topic;

    #[ORM\Column(length: 100, nullable: true, options: ['comment' => 'Identificador da entrega enviado pela origem (X-WC-Webhook-Delivery-ID), quando houver'])]
    private ?string $deliveryId = null;

    #[ORM\Column(length: 100, nullable: true, options: ['comment' => 'Id do recurso na origem, ex.: id do pedido no WooCommerce'])]
    private ?string $resourceId = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'comment' => 'Conteúdo do corpo recebido, sem alterações'])]
    private array $payload;

    #[ORM\Column(length: 20, enumType: IntegrationEventStatus::class, options: ['comment' => 'Situação: received, processed, ignored ou failed'])]
    private IntegrationEventStatus $status = IntegrationEventStatus::Received;

    #[ORM\Column(type: Types::TEXT, nullable: true, options: ['comment' => 'Motivo de o evento ter falhado ou sido ignorado'])]
    private ?string $error = null;

    /** Compra criada a partir do evento; se a compra for apagada, o evento continua (sem o vínculo) */
    #[ORM\ManyToOne(targetEntity: Purchase::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Purchase $purchase = null;

    #[ORM\Column(options: ['comment' => 'Quando o evento foi recebido'])]
    private \DateTimeImmutable $receivedAt;

    #[ORM\Column(nullable: true, options: ['comment' => 'Quando o worker terminou de processar o evento'])]
    private ?\DateTimeImmutable $processedAt = null;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(string $source, string $topic, array $payload, ?string $deliveryId = null, ?string $resourceId = null)
    {
        $this->source = $source;
        $this->topic = $topic;
        $this->payload = $payload;
        $this->deliveryId = $deliveryId;
        $this->resourceId = $resourceId;
        $this->receivedAt = new \DateTimeImmutable();
    }

    public function markProcessed(Purchase $purchase): void
    {
        $this->status = IntegrationEventStatus::Processed;
        $this->purchase = $purchase;
        $this->error = null;
        $this->processedAt = new \DateTimeImmutable();
    }

    public function markIgnored(string $reason): void
    {
        $this->status = IntegrationEventStatus::Ignored;
        $this->error = $reason;
        $this->processedAt = new \DateTimeImmutable();
    }

    public function markFailed(string $error): void
    {
        $this->status = IntegrationEventStatus::Failed;
        $this->error = $error;
        $this->processedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getTopic(): string
    {
        return $this->topic;
    }

    public function getDeliveryId(): ?string
    {
        return $this->deliveryId;
    }

    public function getResourceId(): ?string
    {
        return $this->resourceId;
    }

    /** @return array<string, mixed> */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getStatus(): IntegrationEventStatus
    {
        return $this->status;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getPurchase(): ?Purchase
    {
        return $this->purchase;
    }

    public function getReceivedAt(): \DateTimeImmutable
    {
        return $this->receivedAt;
    }

    public function getProcessedAt(): ?\DateTimeImmutable
    {
        return $this->processedAt;
    }
}
