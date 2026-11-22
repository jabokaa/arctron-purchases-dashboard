<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\CustomerReportSort;
use App\Enum\SortOrder;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Parâmetros do GET /api/reports/customers (ranking de clientes), recebidos na query string:
 *
 *   /api/reports/customers?sort=count&order=desc&page=1&limit=5
 *
 * Sem parâmetros: os 20 clientes que mais compraram (maior valor total primeiro).
 * O #[MapQueryString] no controller converte a query string neste objeto e valida antes de o controller rodar.
 */
final class CustomerReportFilterInput
{
    public function __construct(
        #[Assert\Positive(message: 'A página deve ser maior que zero.')]
        public readonly int $page = 1,

        #[Assert\Range(notInRangeMessage: 'O limite deve estar entre {{ min }} e {{ max }}.', min: 1, max: 100)]
        public readonly int $limit = 20,

        /** Critério: total (valor total), average (valor médio) ou count (quantidade de compras) */
        #[Assert\Choice(callback: [CustomerReportSort::class, 'values'], message: 'A ordenação deve ser total, average ou count.')]
        public readonly string $sort = CustomerReportSort::Total->value,

        /** Direção: desc (maior primeiro) ou asc (menor primeiro) */
        #[Assert\Choice(callback: [SortOrder::class, 'values'], message: 'A direção deve ser asc ou desc.')]
        public readonly string $order = SortOrder::Desc->value,
    ) {}

    /** O critério já validado, como enum */
    public function sort(): CustomerReportSort
    {
        return CustomerReportSort::from($this->sort);
    }

    /** A direção já validada, como enum */
    public function order(): SortOrder
    {
        return SortOrder::from($this->order);
    }
}
