<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\ProductReportSort;
use App\Enum\SortOrder;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Parâmetros do GET /api/reports/products (ranking de produtos), recebidos na query string:
 *
 *   /api/reports/products?sort=quantity&order=desc&page=1&limit=5
 *
 * Sem parâmetros: os 20 produtos com maior valor vendido.
 * O #[MapQueryString] no controller converte a query string neste objeto e valida antes de o controller rodar.
 */
final class ProductReportFilterInput
{
    public function __construct(
        #[Assert\Positive(message: 'A página deve ser maior que zero.')]
        public readonly int $page = 1,

        #[Assert\Range(notInRangeMessage: 'O limite deve estar entre {{ min }} e {{ max }}.', min: 1, max: 100)]
        public readonly int $limit = 20,

        /** Critério: total (valor vendido), average (preço médio por unidade) ou quantity (unidades vendidas) */
        #[Assert\Choice(callback: [ProductReportSort::class, 'values'], message: 'A ordenação deve ser total, average ou quantity.')]
        public readonly string $sort = ProductReportSort::Total->value,

        /** Direção: desc (maior primeiro) ou asc (menor primeiro) */
        #[Assert\Choice(callback: [SortOrder::class, 'values'], message: 'A direção deve ser asc ou desc.')]
        public readonly string $order = SortOrder::Desc->value,
    ) {}

    /** O critério já validado, como enum */
    public function sort(): ProductReportSort
    {
        return ProductReportSort::from($this->sort);
    }

    /** A direção já validada, como enum */
    public function order(): SortOrder
    {
        return SortOrder::from($this->order);
    }
}
