<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PurchaseRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Compra (pedido) feita por um cliente através de um canal de venda.
 * O referenceCode é o código único do pedido, usado para evitar duplicidade.
 *
 * Valores monetários são guardados em CENTAVOS como inteiro (BIGINT): 25010 = R$ 250,10.
 * amount e discount são calculados pelo PurchaseService a partir dos itens (o payload não os envia).
 * Assim não há erro de arredondamento (float não é seguro para dinheiro).
 */
#[ORM\Entity(repositoryClass: PurchaseRepository::class)]
#[ORM\Table(
    name: 'purchases',
    options: ['comment' => 'Compras (pedidos) realizadas por clientes em um canal de venda'],
)]
#[ORM\UniqueConstraint(name: 'uniq_purchases_reference_code', columns: ['reference_code'])]
#[ORM\Index(name: 'idx_purchases_customer', columns: ['customer_id'])]
#[ORM\Index(name: 'idx_purchases_sales_channel', columns: ['sales_channel_id'])]
#[ORM\Index(name: 'idx_purchases_purchased_at', columns: ['purchased_at'])]
#[ORM\HasLifecycleCallbacks]
class Purchase
{
    use Timestampable;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(length: 100, options: ['comment' => 'Código único de referência do pedido'])]
    private string $referenceCode;

    #[ORM\ManyToOne(targetEntity: Customer::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Customer $customer;

    #[ORM\ManyToOne(targetEntity: SalesChannel::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private SalesChannel $salesChannel;

    #[ORM\Column(type: Types::BIGINT, options: ['comment' => 'Valor total da compra (soma do total dos itens), em centavos'])]
    private int $amount;

    #[ORM\Column(type: Types::BIGINT, options: ['default' => 0, 'comment' => 'Desconto total da compra (soma dos descontos dos itens), em centavos'])]
    private int $discount = 0;

    #[ORM\Column(options: ['comment' => 'Data e hora em que a compra foi realizada'])]
    private \DateTimeImmutable $purchasedAt;

    #[ORM\Column(type: Types::TEXT, nullable: true, options: ['comment' => 'Observações livres sobre a compra'])]
    private ?string $notes = null;

    /** @var Collection<int, PurchaseItem> */
    #[ORM\OneToMany(targetEntity: PurchaseItem::class, mappedBy: 'purchase', cascade: ['persist'], orphanRemoval: true)]
    private Collection $items;

    public function __construct()
    {
        $this->items = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReferenceCode(): string
    {
        return $this->referenceCode;
    }

    public function setReferenceCode(string $referenceCode): static
    {
        $this->referenceCode = $referenceCode;

        return $this;
    }

    public function getCustomer(): Customer
    {
        return $this->customer;
    }

    public function setCustomer(Customer $customer): static
    {
        $this->customer = $customer;

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

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function setAmount(int $amount): static
    {
        $this->amount = $amount;

        return $this;
    }

    public function getDiscount(): int
    {
        return $this->discount;
    }

    public function setDiscount(int $discount): static
    {
        $this->discount = $discount;

        return $this;
    }

    public function getPurchasedAt(): \DateTimeImmutable
    {
        return $this->purchasedAt;
    }

    public function setPurchasedAt(\DateTimeImmutable $purchasedAt): static
    {
        $this->purchasedAt = $purchasedAt;

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;

        return $this;
    }

    /** @return Collection<int, PurchaseItem> */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function addItem(PurchaseItem $item): static
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
            $item->setPurchase($this);
        }

        return $this;
    }

    public function removeItem(PurchaseItem $item): static
    {
        $this->items->removeElement($item);

        return $this;
    }
}
