<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\CompanyCustomer;
use App\Entity\Purchase;
use App\Entity\PurchaseItem;

/**
 * Formato da resposta da API para uma compra.
 *
 * Separar a resposta da entidade evita expor campos internos e evita
 * referências circulares (Purchase → Item → Purchase...) na hora de gerar o JSON.
 * Valores monetários saem em centavos (int), igual à entrada: 26010 = R$ 260,10.
 */
final class PurchaseOutput
{
    /**
     * @param array{id: int|null, type: string, name: string, trade_name: string|null, email: string|null, document_type: string, document: string} $customer
     * @param list<array{product_id: int|null, name: string, quantity: int, unit_price: int, discount: int, total_price: int}> $items
     */
    private function __construct(
        public readonly ?int $id,
        public readonly string $referenceCode,
        public readonly string $salesChannel,
        public readonly int $amount,
        public readonly int $discount,
        public readonly \DateTimeImmutable $purchasedAt,
        public readonly ?string $notes,
        public readonly array $customer,
        public readonly array $items,
        public readonly \DateTimeImmutable $createdAt,
    ) {
    }

    public static function fromEntity(Purchase $purchase): self
    {
        $customer = $purchase->getCustomer();

        return new self(
            id: $purchase->getId(),
            referenceCode: $purchase->getReferenceCode(),
            salesChannel: $purchase->getSalesChannel()->getCode(),
            amount: $purchase->getAmount(),
            discount: $purchase->getDiscount(),
            purchasedAt: $purchase->getPurchasedAt(),
            notes: $purchase->getNotes(),
            customer: [
                'id' => $customer->getId(),
                'type' => $customer->getType(),
                'name' => $customer->getName(),
                'trade_name' => $customer instanceof CompanyCustomer ? $customer->getTradeName() : null,
                'email' => $customer->getEmail(),
                'document_type' => $customer->getDocumentType(),
                'document' => $customer->getDocument(),
            ],
            items: array_map(
                static fn (PurchaseItem $item): array => [
                    'product_id' => $item->getProduct()->getId(),
                    'name' => $item->getProduct()->getName(),
                    'quantity' => $item->getQuantity(),
                    'unit_price' => $item->getUnitPrice(),
                    'discount' => $item->getDiscount(),
                    'total_price' => $item->getTotalPrice(),
                ],
                $purchase->getItems()->toArray(),
            ),
            createdAt: $purchase->getCreatedAt(),
        );
    }
}
