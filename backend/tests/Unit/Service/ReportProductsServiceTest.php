<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Dto\ProductReportFilterInput;
use App\Enum\ProductReportSort;
use App\Enum\SortOrder;
use App\Repository\CustomerRepository;
use App\Repository\ProductRepository;
use App\Repository\PurchaseItemRepository;
use App\Repository\PurchaseRepository;
use App\Service\ReportService;
use PHPUnit\Framework\TestCase;

/**
 * Testes unitários do ReportService::products() (ranking de produtos).
 *
 * O que é testado: se o service repassa ordenação e paginação ao repositório e monta a resposta:
 * cada produto com unidades vendidas, compras em que aparece, valor total e preço médio,
 * na ordem do repositório, e os dados de paginação.
 *
 * Como é testado:
 * - É um teste unitário puro (TestCase): o Symfony não é iniciado e o banco não é acessado.
 * - O ProductRepository é um MOCK (quando o teste confere a chamada) ou STUB (quando só devolve dados).
 *   As somas, médias e a ordenação em si são feitas pelo banco e testadas no ProductRankingTest (integração).
 */
final class ReportProductsServiceTest extends TestCase
{
    /**
     * Ordenação e paginação pedidas devem chegar ao repositório como enums e números.
     */
    public function testPassesSortAndPaginationToRepository(): void
    {
        $products = $this->createMock(ProductRepository::class);
        $products->expects($this->once())
            ->method('salesRanking')
            ->with(ProductReportSort::Quantity, SortOrder::Asc, 2, 5)
            ->willReturn(['items' => [], 'total' => 0]);

        $this->service($products)->products(new ProductReportFilterInput(page: 2, limit: 5, sort: 'quantity', order: 'asc'));
    }

    /**
     * Dois produtos no ranking.
     * Espera cada produto no formato da API, na mesma ordem do repositório, e a paginação.
     */
    public function testBuildsRankingInTheApiFormat(): void
    {
        $products = $this->createStub(ProductRepository::class);
        $products->method('salesRanking')->willReturn([
            'items' => [
                ['id' => 3, 'name' => 'Camiseta', 'internalCode' => 'SKU-CAM', 'quantitySold' => 4, 'purchasesCount' => 3, 'total' => 18000, 'average' => 4500],
                ['id' => 7, 'name' => 'Boné', 'internalCode' => null, 'quantitySold' => 1, 'purchasesCount' => 1, 'total' => 3000, 'average' => 3000],
            ],
            'total' => 12,
        ]);

        $result = $this->service($products)->products(new ProductReportFilterInput(limit: 2));

        self::assertSame([
            ['product' => ['id' => 3, 'name' => 'Camiseta', 'internal_code' => 'SKU-CAM'], 'quantity_sold' => 4, 'purchases_count' => 3, 'total' => 18000, 'average' => 4500],
            ['product' => ['id' => 7, 'name' => 'Boné', 'internal_code' => null], 'quantity_sold' => 1, 'purchases_count' => 1, 'total' => 3000, 'average' => 3000],
        ], $result->data);
        self::assertSame(['page' => 1, 'limit' => 2, 'total' => 12, 'total_pages' => 6], $result->meta);
    }

    /**
     * Nenhum produto vendido.
     * Espera lista vazia e zero páginas.
     */
    public function testReturnsEmptyRanking(): void
    {
        $products = $this->createStub(ProductRepository::class);
        $products->method('salesRanking')->willReturn(['items' => [], 'total' => 0]);

        $result = $this->service($products)->products(new ProductReportFilterInput());

        self::assertSame([], $result->data);
        self::assertSame(['page' => 1, 'limit' => 20, 'total' => 0, 'total_pages' => 0], $result->meta);
    }

    private function service(ProductRepository $products): ReportService
    {
        return new ReportService(
            $this->createStub(PurchaseRepository::class),
            $this->createStub(PurchaseItemRepository::class),
            $this->createStub(CustomerRepository::class),
            $products,
        );
    }
}
