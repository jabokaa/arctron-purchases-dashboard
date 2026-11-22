<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Resposta do GET /api/reports/summary: indicadores gerais das compras.
 * Todos os valores em centavos (25010 = R$ 250,10); médias arredondadas para o centavo mais próximo.
 *
 *   {
 *     "purchases_count": 4,
 *     "amount":   { "total": 76000, "average": 19000 },              ← valor das compras (já com desconto)
 *     "discount": { "total": 500,   "average": 125 },                ← descontos (média por compra)
 *     "products": { "total": 76500, "average": 10929, "units_sold": 7 } ← produtos antes do desconto (média por unidade)
 *   }
 *
 * Os números fecham entre si: products.total − discount.total = amount.total.
 */
final class ReportSummaryOutput
{
    /**
     * @param array{total: int, average: int}                  $amount
     * @param array{total: int, average: int}                  $discount
     * @param array{total: int, average: int, units_sold: int} $products
     */
    public function __construct(
        public readonly int $purchasesCount,
        public readonly array $amount,
        public readonly array $discount,
        public readonly array $products,
    ) {}
}
