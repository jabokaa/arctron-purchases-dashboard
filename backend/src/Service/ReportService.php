<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\ReportSummaryOutput;
use App\Dto\ReportTimelineOutput;
use App\Enum\TimelineInterval;
use App\Repository\PurchaseItemRepository;
use App\Repository\PurchaseRepository;

/**
 * Relatórios do dashboard.
 *
 * Totais e médias são calculados pelo banco (repositórios); aqui só é montada a resposta.
 */
final class ReportService implements ReportServiceInterface
{
    public function __construct(
        private readonly PurchaseRepository $purchases,
        private readonly PurchaseItemRepository $purchaseItems,
    ) {}

    /**
     * Indicadores gerais:
     * - compras: quantidade, valor total e valor médio por compra;
     * - descontos: total e desconto médio por compra;
     * - produtos: valor total antes do desconto (quantidade × valor unitário), unidades vendidas
     *   e preço médio por unidade.
     */
    public function summary(): ReportSummaryOutput
    {
        $purchaseTotals = $this->purchases->totals();
        $productTotals = $this->purchaseItems->productTotals();

        return new ReportSummaryOutput(
            purchasesCount: $purchaseTotals['count'],
            amount: [
                'total' => $purchaseTotals['amountTotal'],
                'average' => $purchaseTotals['amountAverage'],
            ],
            discount: [
                'total' => $purchaseTotals['discountTotal'],
                'average' => $purchaseTotals['discountAverage'],
            ],
            products: [
                'total' => $productTotals['total'],
                'average' => $productTotals['average'],
                'units_sold' => $productTotals['unitsSold'],
            ],
        );
    }

    /**
     * Evolução das compras no tempo: quantidade, valor e descontos de cada período.
     * O agrupamento é feito pelo banco; aqui cada período ganha o rótulo e as datas de início e fim.
     */
    public function timeline(TimelineInterval $interval): ReportTimelineOutput
    {
        return new ReportTimelineOutput(
            interval: $interval->value,
            data: array_map(static fn (array $period): array => [
                'period' => $interval->label($period['periodStart']),
                'start' => $period['periodStart']->format('Y-m-d'),
                'end' => $interval->end($period['periodStart'])->format('Y-m-d'),
                'purchases_count' => $period['count'],
                'amount' => $period['amount'],
                'discount' => $period['discount'],
            ], $this->purchases->timeline($interval)),
        );
    }
}
