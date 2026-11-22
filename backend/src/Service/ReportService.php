<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\CustomerReportFilterInput;
use App\Dto\ProductReportFilterInput;
use App\Dto\ReportRankingOutput;
use App\Dto\ReportSummaryOutput;
use App\Dto\ReportTimelineOutput;
use App\Entity\CompanyCustomer;
use App\Enum\TimelineInterval;
use App\Repository\CustomerRepository;
use App\Repository\ProductRepository;
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
        private readonly CustomerRepository $customers,
        private readonly ProductRepository $products,
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

    /**
     * Ranking dos clientes pelas compras (quantidade, valor total e valor médio), paginado.
     * Os números vêm prontos do banco; aqui cada cliente ganha o mesmo formato usado nas compras
     * (tipo, nome, documento) e a paginação é montada.
     */
    public function customers(CustomerReportFilterInput $filter): ReportRankingOutput
    {
        $ranking = $this->customers->purchaseRanking($filter->sort(), $filter->order(), $filter->page, $filter->limit);

        return new ReportRankingOutput(
            data: array_map(static function (array $row): array {
                $isCompany = CompanyCustomer::TYPE === $row['personType'];

                return [
                    'customer' => [
                        'id' => $row['id'],
                        'type' => $row['personType'],
                        'name' => $isCompany ? $row['legalName'] : $row['fullName'],
                        'trade_name' => $row['tradeName'],
                        'email' => $row['email'],
                        'document_type' => $isCompany ? 'CNPJ' : 'CPF',
                        'document' => $isCompany ? $row['cnpj'] : $row['cpf'],
                    ],
                    'purchases_count' => $row['purchasesCount'],
                    'total' => $row['total'],
                    'average' => $row['average'],
                ];
            }, $ranking['items']),
            meta: self::paginationMeta($filter->page, $filter->limit, $ranking['total']),
        );
    }

    /**
     * Ranking dos produtos pelas vendas (unidades, compras em que aparece, valor total e preço médio
     * por unidade), paginado. Os números vêm prontos do banco; aqui é montada a resposta.
     */
    public function products(ProductReportFilterInput $filter): ReportRankingOutput
    {
        $ranking = $this->products->salesRanking($filter->sort(), $filter->order(), $filter->page, $filter->limit);

        return new ReportRankingOutput(
            data: array_map(static fn (array $row): array => [
                'product' => [
                    'id' => $row['id'],
                    'name' => $row['name'],
                    'internal_code' => $row['internalCode'],
                ],
                'quantity_sold' => $row['quantitySold'],
                'purchases_count' => $row['purchasesCount'],
                'total' => $row['total'],
                'average' => $row['average'],
            ], $ranking['items']),
            meta: self::paginationMeta($filter->page, $filter->limit, $ranking['total']),
        );
    }

    /**
     * Dados da paginação dos rankings.
     *
     * @return array{page: int, limit: int, total: int, total_pages: int}
     */
    private static function paginationMeta(int $page, int $limit, int $total): array
    {
        return [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'total_pages' => (int) ceil($total / $limit),
        ];
    }
}
