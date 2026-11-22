<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProductExternalIdRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Identificador de um produto interno dentro de um canal de venda (ex.: ID na Shopify).
 * Cada produto tem no máximo um ID por canal, e cada ID externo de um canal
 * aponta para um único produto.
 */
#[ORM\Entity(repositoryClass: ProductExternalIdRepository::class)]
#[ORM\Table(
    name: 'product_external_ids',
    options: ['comment' => 'Identificadores do produto em cada canal de venda (ex.: ID do produto na Shopify)'],
)]
#[ORM\UniqueConstraint(name: 'uniq_product_external_ids_channel_external', columns: ['sales_channel_id', 'external_id'])]
#[ORM\UniqueConstraint(name: 'uniq_product_external_ids_product_channel', columns: ['product_id', 'sales_channel_id'])]
#[ORM\HasLifecycleCallbacks]
class ProductExternalId
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    /** Produto interno; ao remover o produto, seus IDs externos são removidos juntos */
    #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'externalIds')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Product $product;

    #[ORM\ManyToOne(targetEntity: SalesChannel::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private SalesChannel $salesChannel;

    #[ORM\Column(length: 255, options: ['comment' => 'ID do produto no sistema do canal de venda'])]
    private string $externalId;

    #[ORM\Column(options: ['comment' => 'Data de criação do registro'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProduct(): Product
    {
        return $this->product;
    }

    public function setProduct(Product $product): static
    {
        $this->product = $product;

        return $this;
    }

    public function getSalesChannel(): SalesChannel
    {
        return $this->salesChannel;
    }

    public function setSalesChannel(SalesChannel $salesChannel): static
    {
        $this->salesChannel = $salesChannel;

        return $this;
    }

    public function getExternalId(): string
    {
        return $this->externalId;
    }

    public function setExternalId(string $externalId): static
    {
        $this->externalId = $externalId;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
