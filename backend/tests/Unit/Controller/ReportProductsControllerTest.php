<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Dto\ProductReportFilterInput;
use App\Dto\ReportRankingOutput;
use App\Enum\ProductReportSort;
use App\Enum\SortOrder;
use App\Service\ReportServiceInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Testes do endpoint GET /api/reports/products (ranking de produtos, ReportController::products).
 *
 * O que é testado: se o controller aceita os parâmetros corretos (page, limit, sort, order),
 * usa os valores padrão quando não vêm, recusa os errados com 422 e devolve a resposta do service
 * no formato JSON esperado.
 *
 * Como é testado:
 * - O ReportService é trocado por um MOCK: nenhum dado é lido do banco e o teste
 *   foca só no controller (leitura da query string, validação e formato da resposta).
 * - Nos casos de erro, o mock garante que o service NUNCA é chamado.
 */
final class ReportProductsControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    private ReportServiceInterface&MockObject $reportService;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        // Troca o service real pelo mock dentro do container do Symfony
        $this->reportService = $this->createMock(ReportServiceInterface::class);
        static::getContainer()->set(ReportServiceInterface::class, $this->reportService);
    }

    /**
     * Sem parâmetros.
     * Espera 200 e que o service receba os padrões: página 1, 20 por página, maior valor vendido primeiro.
     */
    public function testUsesDefaults(): void
    {
        $this->reportService->expects($this->once())
            ->method('products')
            ->with($this->callback(static fn (ProductReportFilterInput $filter): bool => 1 === $filter->page
                && 20 === $filter->limit
                && ProductReportSort::Total === $filter->sort()
                && SortOrder::Desc === $filter->order()))
            ->willReturn(self::emptyRanking());

        self::assertSame(200, $this->get('')['status']);
    }

    /**
     * Para cada combinação válida do provider, espera 200 e que o service receba os valores convertidos
     * (texto da query string → inteiro e enum).
     *
     * @param array<string, string> $query
     */
    #[DataProvider('validParametersProvider')]
    public function testAcceptsValidParameters(array $query, int $page, int $limit, ProductReportSort $sort, SortOrder $order): void
    {
        $this->reportService->expects($this->once())
            ->method('products')
            ->with($this->callback(static fn (ProductReportFilterInput $filter): bool => $page === $filter->page
                && $limit === $filter->limit
                && $sort === $filter->sort()
                && $order === $filter->order()))
            ->willReturn(self::emptyRanking());

        self::assertSame(200, $this->get('?'.http_build_query($query))['status']);
    }

    /**
     * @return iterable<string, array{array<string, string>, int, int, ProductReportSort, SortOrder}>
     */
    public static function validParametersProvider(): iterable
    {
        // Top 5 da home: só o limite
        yield 'top 5' => [['limit' => '5'], 1, 5, ProductReportSort::Total, SortOrder::Desc];

        // Mais vendidos em unidades
        yield 'sort=quantity' => [['sort' => 'quantity'], 1, 20, ProductReportSort::Quantity, SortOrder::Desc];

        // Preço médio, do menor para o maior
        yield 'sort=average&order=asc' => [['sort' => 'average', 'order' => 'asc'], 1, 20, ProductReportSort::Average, SortOrder::Asc];

        // Segunda página, 10 por página
        yield 'page=2&limit=10' => [['page' => '2', 'limit' => '10'], 2, 10, ProductReportSort::Total, SortOrder::Desc];
    }

    /**
     * Para cada caso do provider, envia UM parâmetro inválido e espera 422 com a mensagem certa
     * no campo certo. O service não pode ser chamado.
     *
     * @param array<string, string> $query
     */
    #[DataProvider('invalidParameterProvider')]
    public function testRejectsInvalidParameter(array $query, string $expectedField, string $expectedMessage): void
    {
        $this->reportService->expects($this->never())->method('products');

        $response = $this->get('?'.http_build_query($query));

        self::assertSame(422, $response['status']);
        self::assertContains(['field' => $expectedField, 'message' => $expectedMessage], $response['body']['violations']);
    }

    /**
     * @return iterable<string, array{array<string, string>, string, string}>
     */
    public static function invalidParameterProvider(): iterable
    {
        // page começa em 1
        yield 'page zero' => [['page' => '0'], 'page', 'A página deve ser maior que zero.'];

        // limit tem no máximo 100
        yield 'limit acima do máximo' => [['limit' => '101'], 'limit', 'O limite deve estar entre 1 e 100.'];

        // limit precisa ser número inteiro
        yield 'limit em texto' => [['limit' => 'cinco'], 'limit', 'Tipo inválido: esperado número inteiro.'];

        // count é critério do ranking de clientes, não de produtos
        yield 'sort de clientes' => [['sort' => 'count'], 'sort', 'A ordenação deve ser total, average ou quantity.'];

        // sort só aceita os critérios do ranking de produtos
        yield 'sort inválido' => [['sort' => 'name'], 'sort', 'A ordenação deve ser total, average ou quantity.'];

        // order só aceita asc ou desc
        yield 'order inválido' => [['order' => 'down'], 'order', 'A direção deve ser asc ou desc.'];
    }

    /**
     * Resposta do service com um produto no ranking.
     * Espera 200 e o JSON no formato { data: [{ product, quantity_sold, purchases_count, total, average }], meta }.
     */
    public function testReturnsRankingAsJson(): void
    {
        $row = [
            'product' => ['id' => 3, 'name' => 'Camiseta', 'internal_code' => null],
            'quantity_sold' => 4,
            'purchases_count' => 3,
            'total' => 18000,
            'average' => 4500,
        ];
        $meta = ['page' => 1, 'limit' => 20, 'total' => 1, 'total_pages' => 1];

        $this->reportService->expects($this->once())->method('products')->willReturn(new ReportRankingOutput([$row], $meta));

        $response = $this->get('');

        self::assertSame(200, $response['status']);
        self::assertSame(['data' => [$row], 'meta' => $meta], $response['body']);
    }

    private static function emptyRanking(): ReportRankingOutput
    {
        return new ReportRankingOutput([], ['page' => 1, 'limit' => 20, 'total' => 0, 'total_pages' => 0]);
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function get(string $queryString): array
    {
        $this->client->request('GET', '/api/reports/products'.$queryString);

        return [
            'status' => $this->client->getResponse()->getStatusCode(),
            'body' => json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR),
        ];
    }
}
