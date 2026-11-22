<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Dto\CustomerReportFilterInput;
use App\Dto\ReportRankingOutput;
use App\Enum\CustomerReportSort;
use App\Enum\SortOrder;
use App\Repository\CustomerRepository;
use App\Repository\ProductRepository;
use App\Repository\PurchaseItemRepository;
use App\Repository\PurchaseRepository;
use App\Service\ReportService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Testes unitários do ReportService::customers() (ranking de clientes).
 *
 * O que é testado: se o service repassa ordenação e paginação ao repositório e monta a resposta:
 * cada cliente no mesmo formato usado nas compras (tipo, nome, documento) e os dados de paginação.
 *
 * Como é testado:
 * - É um teste unitário puro (TestCase): o Symfony não é iniciado e o banco não é acessado.
 * - O CustomerRepository é um MOCK (quando o teste confere a chamada) ou STUB (quando só devolve dados).
 *   As somas, médias e a ordenação em si são feitas pelo banco e testadas no CustomerRankingTest (integração).
 */
final class ReportCustomersServiceTest extends TestCase
{
    /**
     * Ordenação e paginação pedidas devem chegar ao repositório como enums e números.
     */
    public function testPassesSortAndPaginationToRepository(): void
    {
        $customers = $this->createMock(CustomerRepository::class);
        $customers->expects($this->once())
            ->method('purchaseRanking')
            ->with(CustomerReportSort::Count, SortOrder::Asc, 3, 5)
            ->willReturn(['items' => [], 'total' => 0]);

        $this->service($customers)->customers(new CustomerReportFilterInput(page: 3, limit: 5, sort: 'count', order: 'asc'));
    }

    /**
     * Pessoa física no ranking.
     * Espera nome completo, documento CPF e sem nome fantasia.
     */
    public function testFormatsIndividualCustomer(): void
    {
        $row = $this->ranking([self::row(id: 1, personType: 'INDIVIDUAL', fullName: 'João Silva', cpf: '52998224725', count: 3, total: 6000, average: 2000)])->data[0];

        self::assertSame([
            'customer' => ['id' => 1, 'type' => 'INDIVIDUAL', 'name' => 'João Silva', 'trade_name' => null, 'email' => 'cliente@email.com', 'document_type' => 'CPF', 'document' => '52998224725'],
            'purchases_count' => 3,
            'total' => 6000,
            'average' => 2000,
        ], $row);
    }

    /**
     * Pessoa jurídica no ranking.
     * Espera a razão social como nome, o nome fantasia e o documento CNPJ.
     */
    public function testFormatsCompanyCustomer(): void
    {
        $row = $this->ranking([self::row(id: 2, personType: 'COMPANY', legalName: 'Loja LTDA', tradeName: 'Loja Bacana', cnpj: '11222333000181')])->data[0];

        self::assertSame(
            ['id' => 2, 'type' => 'COMPANY', 'name' => 'Loja LTDA', 'trade_name' => 'Loja Bacana', 'email' => 'cliente@email.com', 'document_type' => 'CNPJ', 'document' => '11222333000181'],
            $row['customer'],
        );
    }

    /**
     * A ordem do ranking vem do banco e não pode ser alterada pelo service.
     */
    public function testKeepsRepositoryOrder(): void
    {
        $data = $this->ranking([
            self::row(id: 5, fullName: 'Primeiro'),
            self::row(id: 2, fullName: 'Segundo'),
            self::row(id: 9, fullName: 'Terceiro'),
        ])->data;

        self::assertSame(['Primeiro', 'Segundo', 'Terceiro'], array_map(static fn (array $row): string => $row['customer']['name'], $data));
    }

    /**
     * Para cada caso do provider, confere os dados de paginação montados a partir da página,
     * do limite e do total de clientes no ranking.
     */
    #[DataProvider('paginationProvider')]
    public function testBuildsPaginationMeta(int $page, int $limit, int $total, int $expectedTotalPages): void
    {
        $customers = $this->createStub(CustomerRepository::class);
        $customers->method('purchaseRanking')->willReturn(['items' => [], 'total' => $total]);

        $result = $this->service($customers)->customers(new CustomerReportFilterInput(page: $page, limit: $limit));

        self::assertSame(['page' => $page, 'limit' => $limit, 'total' => $total, 'total_pages' => $expectedTotalPages], $result->meta);
    }

    /**
     * @return iterable<string, array{int, int, int, int}>
     */
    public static function paginationProvider(): iterable
    {
        // Nenhum cliente com compras: zero páginas
        yield 'nenhum cliente' => [1, 20, 0, 0];

        // Top 5 com mais de 5 clientes: várias páginas de 5
        yield 'top 5' => [1, 5, 12, 3];

        // Total exato de páginas cheias
        yield 'páginas cheias' => [2, 10, 20, 2];
    }

    // =========================================================================
    // Auxiliares
    // =========================================================================

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function ranking(array $rows): ReportRankingOutput
    {
        $customers = $this->createStub(CustomerRepository::class);
        $customers->method('purchaseRanking')->willReturn(['items' => $rows, 'total' => \count($rows)]);

        return $this->service($customers)->customers(new CustomerReportFilterInput());
    }

    private function service(CustomerRepository $customers): ReportService
    {
        return new ReportService(
            $this->createStub(PurchaseRepository::class),
            $this->createStub(PurchaseItemRepository::class),
            $customers,
            $this->createStub(ProductRepository::class),
        );
    }

    /**
     * Linha no formato devolvido pelo CustomerRepository::purchaseRanking().
     *
     * @return array<string, mixed>
     */
    private static function row(
        int $id,
        string $personType = 'INDIVIDUAL',
        ?string $fullName = null,
        ?string $cpf = '52998224725',
        ?string $legalName = null,
        ?string $tradeName = null,
        ?string $cnpj = null,
        int $count = 1,
        int $total = 1000,
        int $average = 1000,
    ): array {
        return [
            'id' => $id,
            'personType' => $personType,
            'email' => 'cliente@email.com',
            'fullName' => $fullName,
            'cpf' => 'COMPANY' === $personType ? null : $cpf,
            'legalName' => $legalName,
            'tradeName' => $tradeName,
            'cnpj' => $cnpj,
            'purchasesCount' => $count,
            'total' => $total,
            'average' => $average,
        ];
    }
}
