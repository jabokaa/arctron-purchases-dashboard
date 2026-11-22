<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PurchaseItemRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Item de uma compra: qual produto, quantidade e preços.
 * O totalPrice é gravado (quantidade × preço unitário) para manter o histórico fiel,
 * mesmo que o preço do produto mude depois.
 */
#[ORM\Entity(repositoryClass: PurchaseItemRepository::class)]
#[ORM\Table(name: 'purchase_items', options: ['comment' => 'Itens (produtos) que compõem cada compra'])]
#[ORM\Index(name: 'idx_purchase_items_purchase', columns: ['purchase_id'])]
#[ORM\Index(name: 'idx_purchase_items_product', columns: ['product_id'])]
class PurchaseItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    /** Compra à qual o item pertence; ao remover a compra, seus itens são removidos juntos */
    #[ORM\ManyToOne(targetEntity: Purchase::class, inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Purchase $purchase;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Product $product;

    #[ORM\Column(options: ['comment' => 'Quantidade do produto na compra'])]
    private int $quantity;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, options: ['comment' => 'Preço unitário do produto no momento da compra'])]
    private string $unitPrice;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, options: ['comment' => 'Preço total do item (quantidade × preço unitário)'])]
    private string $totalPrice;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPurchase(): Purchase
    {
        return $this->purchase;
    }

    public function setPurchase(Purchase $purchase): static
    {
        $this->purchase = $purchase;

        return $this;
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

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): static
    {
        $this->quantity = $quantity;

        return $this;
    }

    public function getUnitPrice(): string
    {
        return $this->unitPrice;
    }

    public function setUnitPrice(string $unitPrice): static
    {
        $this->unitPrice = $unitPrice;

        return $this;
    }

    public function getTotalPrice(): string
    {
        return $this->totalPrice;
    }

    public function setTotalPrice(string $totalPrice): static
    {
        $this->totalPrice = $totalPrice;

        return $this;
    }
}
