<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Dto\ReportTimelineOutput;
use App\Enum\TimelineInterval;
use App\Service\ReportServiceInterface;
use App\Tests\Support\TestUser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Testes do endpoint GET /api/reports/timeline (evolução das compras no tempo, ReportController::timeline).
 *
 * O que é testado: se o controller aceita os intervalos válidos (day, week, month) e recusa
 * os inválidos com 422, se usa "day" quando o parâmetro não vem, e se a resposta do service
 * sai no formato JSON esperado.
 *
 * Como é testado:
 * - O ReportService é trocado por um MOCK: nenhum dado é lido do banco e o teste
 *   foca só no controller (leitura da query string, validação e formato da resposta).
 * - Nos casos de erro, o mock garante que o service NUNCA é chamado.
 */
final class ReportTimelineControllerTest extends WebTestCase
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
     * Sem o parâmetro interval.
     * Espera 200 e que o service receba o intervalo padrão: dia.
     */
    public function testUsesDayIntervalByDefault(): void
    {
        $this->reportService->expects($this->once())
            ->method('timeline')
            ->with(TimelineInterval::Day)
            ->willReturn(new ReportTimelineOutput('day', []));

        self::assertSame(200, $this->get('/api/reports/timeline')['status']);
    }

    /**
     * Para cada intervalo válido do provider, espera 200 e que o service receba o enum correspondente.
     */
    #[DataProvider('validIntervalProvider')]
    public function testAcceptsValidInterval(string $interval, TimelineInterval $expected): void
    {
        $this->reportService->expects($this->once())
            ->method('timeline')
            ->with($expected)
            ->willReturn(new ReportTimelineOutput($interval, []));

        self::assertSame(200, $this->get('/api/reports/timeline?interval='.$interval)['status']);
    }

    /**
     * @return iterable<string, array{string, TimelineInterval}>
     */
    public static function validIntervalProvider(): iterable
    {
        // Agrupado por dia
        yield 'day' => ['day', TimelineInterval::Day];

        // Agrupado por semana (segunda a domingo)
        yield 'week' => ['week', TimelineInterval::Week];

        // Agrupado por mês
        yield 'month' => ['month', TimelineInterval::Month];
    }

    /**
     * Para cada intervalo inválido do provider, espera 422 com a mensagem dos valores aceitos
     * e que o service NUNCA seja chamado.
     */
    #[DataProvider('invalidIntervalProvider')]
    public function testRejectsInvalidInterval(string $interval): void
    {
        $this->reportService->expects($this->never())->method('timeline');

        $response = $this->get('/api/reports/timeline?interval='.urlencode($interval));

        self::assertSame(422, $response['status']);
        self::assertSame(
            [['field' => 'interval', 'message' => 'O intervalo deve ser day, week ou month.']],
            $response['body']['violations'],
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidIntervalProvider(): iterable
    {
        // Intervalo que não existe
        yield 'year' => ['year'];

        // Em português não é aceito: os valores são os da API (em inglês)
        yield 'em português' => ['dia'];

        // Diferencia maiúsculas: só "day" em minúsculas
        yield 'maiúsculas' => ['DAY'];

        // Vazio não vale como "padrão": ou não envia o parâmetro, ou envia um valor válido
        yield 'vazio' => [''];
    }

    /**
     * Resposta do service com dois períodos.
     * Espera 200 e o JSON no formato { interval, data: [{ period, start, end, purchases_count, amount, discount }] }.
     */
    public function testReturnsTimelineAsJson(): void
    {
        $data = [
            ['period' => '2026-10', 'start' => '2026-10-01', 'end' => '2026-10-31', 'purchases_count' => 0, 'amount' => 0, 'discount' => 0],
            ['period' => '2026-11', 'start' => '2026-11-01', 'end' => '2026-11-30', 'purchases_count' => 3, 'amount' => 29409, 'discount' => 600],
        ];
        $this->reportService->expects($this->once())->method('timeline')->willReturn(new ReportTimelineOutput('month', $data));

        $response = $this->get('/api/reports/timeline?interval=month');

        self::assertSame(200, $response['status']);
        self::assertSame(['interval' => 'month', 'data' => $data], $response['body']);
    }

    /**
     * Método HTTP diferente de GET (o relatório é só consulta).
     * Espera 405 e que o service NUNCA seja chamado.
     */
    public function testRejectsPostMethod(): void
    {
        $this->reportService->expects($this->never())->method('timeline');

        $this->client->request('POST', '/api/reports/timeline');

        self::assertSame(405, $this->client->getResponse()->getStatusCode());
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function get(string $url): array
    {
        $this->client->request('GET', $url);

        return [
            'status' => $this->client->getResponse()->getStatusCode(),
            'body' => json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR),
        ];
    }
}
