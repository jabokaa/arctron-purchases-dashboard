<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Dto\ReportSummaryOutput;
use App\Service\ReportServiceInterface;
use App\Tests\Support\TestUser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Testes do endpoint GET /api/reports/summary (indicadores gerais, ReportController::summary).
 *
 * O que é testado: se o controller devolve os indicadores do service no formato JSON esperado
 * (chaves em snake_case) e se recusa métodos HTTP que não sejam GET.
 *
 * Como é testado:
 * - O ReportService é trocado por um MOCK: nenhum dado é lido do banco e o teste
 *   foca só no controller (rota e formato da resposta).
 * - Nos métodos recusados, o mock garante que o service NUNCA é chamado.
 */
final class ReportControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    private ReportServiceInterface&MockObject $reportService;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->loginUser(TestUser::create(), 'api');

        // Troca o service real pelo mock dentro do container do Symfony
        $this->reportService = $this->createMock(ReportServiceInterface::class);
        static::getContainer()->set(ReportServiceInterface::class, $this->reportService);
    }

    /**
     * Indicadores devolvidos pelo service.
     * Espera 200 e o JSON com todos os indicadores, nos campos e no formato combinados com o front.
     */
    public function testReturnsSummaryAsJson(): void
    {
        $this->reportService->expects($this->once())
            ->method('summary')
            ->willReturn(new ReportSummaryOutput(
                purchasesCount: 3,
                amount: ['total' => 29409, 'average' => 9803],
                discount: ['total' => 600, 'average' => 200],
                products: ['total' => 30009, 'average' => 4287, 'units_sold' => 7],
            ));

        $this->client->request('GET', '/api/reports/summary');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame([
            'purchases_count' => 3,
            'amount' => ['total' => 29409, 'average' => 9803],
            'discount' => ['total' => 600, 'average' => 200],
            'products' => ['total' => 30009, 'average' => 4287, 'units_sold' => 7],
        ], $this->decodeResponse());
    }

    /**
     * Para cada caso do provider, o método HTTP não é aceito pela rota (é só consulta).
     * Espera 405 e que o service NUNCA seja chamado.
     */
    #[DataProvider('unsupportedMethodProvider')]
    public function testRejectsUnsupportedMethod(string $method): void
    {
        $this->reportService->expects($this->never())->method('summary');

        $this->client->request($method, '/api/reports/summary');

        self::assertSame(405, $this->client->getResponse()->getStatusCode());
        self::assertSame('Método HTTP não permitido para esta rota.', $this->decodeResponse()['detail']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsupportedMethodProvider(): iterable
    {
        // Não é possível criar um relatório
        yield 'POST' => ['POST'];

        // Não é possível alterar um relatório
        yield 'PUT' => ['PUT'];

        // Não é possível apagar um relatório
        yield 'DELETE' => ['DELETE'];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResponse(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }
}
