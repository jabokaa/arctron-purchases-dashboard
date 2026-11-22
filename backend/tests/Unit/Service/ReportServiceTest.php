<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Repository\PurchaseItemRepository;
use App\Repository\PurchaseRepository;
use App\Service\ReportService;
use PHPUnit\Framework\TestCase;

/**
 * Testes unitários do ReportService::summary() (indicadores gerais do dashboard).
 *
 * O que é testado: se o service monta a resposta com cada total e cada média vindos dos
 * repositórios no lugar certo (sem trocar um indicador pelo outro).
 *
 * Como é testado:
 * - É um teste unitário puro (TestCase): o Symfony não é iniciado e o banco não é acessado.
 * - Os repositórios são STUBS que devolvem os indicadores prontos. As somas, as médias e o
 *   arredondamento são feitos pelo banco, e por isso são testados no ReportTotalsTest (integração).
 */
final class ReportServiceTest extends TestCase
{
    /**
     * Indicadores com valores diferentes entre si, para garantir que nenhum foi trocado de lugar.
     * Espera cada valor no campo certo da resposta.
     */
    public function testBuildsSummaryFromRepositoryIndicators(): void
    {
        $summary = $this->service(
            purchaseTotals: ['count' => 3, 'amountTotal' => 29409, 'amountAverage' => 9803, 'discountTotal' => 600, 'discountAverage' => 200],
            productTotals: ['total' => 30009, 'average' => 4287, 'unitsSold' => 7],
        )->summary();

        self::assertSame(3, $summary->purchasesCount);
        self::assertSame(['total' => 29409, 'average' => 9803], $summary->amount);
        self::assertSame(['total' => 600, 'average' => 200], $summary->discount);
        self::assertSame(['total' => 30009, 'average' => 4287, 'units_sold' => 7], $summary->products);
    }

    /**
     * Nenhuma compra cadastrada (os repositórios devolvem tudo zerado).
     * Espera todos os indicadores zerados na resposta.
     */
    public function testReturnsZerosWhenThereAreNoPurchases(): void
    {
        $summary = $this->service(
            purchaseTotals: ['count' => 0, 'amountTotal' => 0, 'amountAverage' => 0, 'discountTotal' => 0, 'discountAverage' => 0],
            productTotals: ['total' => 0, 'average' => 0, 'unitsSold' => 0],
        )->summary();

        self::assertSame(0, $summary->purchasesCount);
        self::assertSame(['total' => 0, 'average' => 0], $summary->amount);
        self::assertSame(['total' => 0, 'average' => 0], $summary->discount);
        self::assertSame(['total' => 0, 'average' => 0, 'units_sold' => 0], $summary->products);
    }

    /**
     * @param array{count: int, amountTotal: int, amountAverage: int, discountTotal: int, discountAverage: int} $purchaseTotals
     * @param array{total: int, average: int, unitsSold: int}                                                  $productTotals
     */
    private function service(array $purchaseTotals, array $productTotals): ReportService
    {
        $purchases = $this->createStub(PurchaseRepository::class);
        $purchases->method('totals')->willReturn($purchaseTotals);

        $purchaseItems = $this->createStub(PurchaseItemRepository::class);
        $purchaseItems->method('productTotals')->willReturn($productTotals);

        return new ReportService($purchases, $purchaseItems);
    }
}
