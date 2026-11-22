<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\Product;
use App\Entity\ProductExternalId;

/**
 * Formato da resposta da API para um produto do catálogo.
 *
 *   {
 *     "id": 1, "name": "Chuteira de Campo", "internal_code": "CHU-001",
 *     "image_url": "https://.../chuteira.jpg",
 *     "external_ids": [ { "sales_channel": "API", "external_id": "CHU-001" } ],
 *     "created_at": "...", "updated_at": "..."
 *   }
 *
 * external_ids mostra o código do produto em cada canal de venda (somente leitura: é criado pelas compras e pelo seeder).
 */
final class ProductOutput
{
    /**
     * @param list<array{sales_channel: string, external_id: string}> $externalIds
     */
    private function __construct(
        public readonly ?int $id,
        public readonly string $name,
        public readonly ?string $internalCode,
        public readonly ?string $imageUrl,
        public readonly array $externalIds,
        public readonly \DateTimeImmutable $createdAt,
        public readonly \DateTimeImmutable $updatedAt,
    ) {}

    public static function fromEntity(Product $product): self
    {
        return new self(
            id: $product->getId(),
            name: $product->getName(),
            internalCode: $product->getInternalCode(),
            imageUrl: $product->getImageUrl(),
            externalIds: array_values(array_map(
                static fn (ProductExternalId $externalId): array => [
                    'sales_channel' => $externalId->getSalesChannel()->getCode(),
                    'external_id' => $externalId->getExternalId(),
                ],
                $product->getExternalIds()->toArray(),
            )),
            createdAt: $product->getCreatedAt(),
            updatedAt: $product->getUpdatedAt(),
        );
    }
}
