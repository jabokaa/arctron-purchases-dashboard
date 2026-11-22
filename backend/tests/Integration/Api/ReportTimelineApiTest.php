<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Tests\Integration\IntegrationTestCase;

/**
 * Testes de integração do endpoint GET /api/reports/timeline (evolução das compras no tempo).
 *
 * O que é testado: o fluxo completo, do jeito que o gráfico da home usaria:
 * as compras são cadastradas pelo próprio POST /api/purchases e depois a linha do tempo é consultada,
 * passando por query string → validação → controller → ReportService → repositório → PostgreSQL.
 * Nada é mockado.
 *
 * Como é testado:
 * - O banco de teste (app_test) é criado do zero com as migrations (ver IntegrationTestCase).
 * - Antes de cada teste o banco é esvaziado e as 5 compras abaixo são cadastradas pelo POST
 *   (valores em centavos; datas já convertidas para UTC, que é como o banco guarda):
 *
 *   | Compra | Data enviada              | Em UTC           | Valor (já com desconto) | Desconto |
 *   |--------|---------------------------|------------------|-------------------------|----------|
 *   | P1     | 2026-10-30T10:00:00Z      | sex 30/10 10:00  | 1000                    |   0      |
 *   | P2     | 2026-11-01T06:00:00-03:00 | dom 01/11 09:00  | 4500                    | 500      |
 *   | P3     | 2026-11-01T18:00:00Z      | dom 01/11 18:00  | 2000                    |   0      |
 *   | P4     | 2026-11-04T12:00:00Z      | qua 04/11 12:00  | 2900                    | 100      |
 *   | P5     | 2026-12-01T00:00:00Z      | ter 01/12 00:00  | 7000                    |   0      |
 */
final class ReportTimelineApiTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createPurchase('P1', '2026-10-30T10:00:00Z', 1000, 0);
        $this->createPurchase('P2', '2026-11-01T06:00:00-03:00', 5000, 500);
        $this->createPurchase('P3', '2026-11-01T18:00:00Z', 2000, 0);
        $this->createPurchase('P4', '2026-11-04T12:00:00Z', 3000, 100);
        $this->createPurchase('P5', '2026-12-01T00:00:00Z', 7000, 0);
    }

    /**
     * Agrupado por mês.
     * Espera 3 meses, com rótulo, início, fim e os totais de cada um.
     */
    public function testGroupsByMonth(): void
    {
        $response = $this->timeline('month');

        self::assertSame(200, $response['status']);
        self::assertSame([
            'interval' => 'month',
            'data' => [
                ['period' => '2026-10', 'start' => '2026-10-01', 'end' => '2026-10-31', 'purchases_count' => 1, 'amount' => 1000, 'discount' => 0],
                ['period' => '2026-11', 'start' => '2026-11-01', 'end' => '2026-11-30', 'purchases_count' => 3, 'amount' => 9400, 'discount' => 600],
                ['period' => '2026-12', 'start' => '2026-12-01', 'end' => '2026-12-31', 'purchases_count' => 1, 'amount' => 7000, 'discount' => 0],
            ],
        ], $response['body']);
    }

    /**
     * Agrupado por semana (segunda a domingo).
     * Espera a semana de 26/10 com as 3 compras de sexta e domingo, as semanas sem compras zeradas
     * e a semana de 30/11 com a compra de 01/12.
     */
    public function testGroupsByWeek(): void
    {
        $data = $this->timeline('week')['body']['data'];

        self::assertSame([
            ['2026-W44', '2026-10-26', '2026-11-01', 3, 7500, 500],
            ['2026-W45', '2026-11-02', '2026-11-08', 1, 2900, 100],
            ['2026-W46', '2026-11-09', '2026-11-15', 0, 0, 0],
            ['2026-W47', '2026-11-16', '2026-11-22', 0, 0, 0],
            ['2026-W48', '2026-11-23', '2026-11-29', 0, 0, 0],
            ['2026-W49', '2026-11-30', '2026-12-06', 1, 7000, 0],
        ], array_map(array_values(...), $data));
    }

    /**
     * Agrupado por dia (também é o padrão, sem o parâmetro).
     * Espera um período para cada dia de 30/10 a 01/12 (33 dias), e as somas certas nos dias com compras.
     */
    public function testGroupsByDayByDefault(): void
    {
        $response = $this->timeline(null);

        self::assertSame('day', $response['body']['interval']);

        $data = $response['body']['data'];
        self::assertCount(33, $data);
        self::assertSame('2026-10-30', $data[0]['period']);
        self::assertSame('2026-12-01', $data[32]['period']);

        $daysWithPurchases = array_values(array_filter($data, static fn (array $day): bool => $day['purchases_count'] > 0));
        self::assertSame([
            ['2026-10-30', 1, 1000, 0],
            ['2026-11-01', 2, 6500, 500],
            ['2026-11-04', 1, 2900, 100],
            ['2026-12-01', 1, 7000, 0],
        ], array_map(static fn (array $day): array => [$day['period'], $day['purchases_count'], $day['amount'], $day['discount']], $daysWithPurchases));
    }

    /**
     * A soma de todos os períodos bate com o total geral do resumo (GET /api/reports/summary).
     */
    public function testPeriodsAddUpToTheSummary(): void
    {
        $data = $this->timeline('week')['body']['data'];

        $this->client->request('GET', '/api/reports/summary');
        $summary = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertSame($summary['purchases_count'], array_sum(array_column($data, 'purchases_count')));
        self::assertSame($summary['amount']['total'], array_sum(array_column($data, 'amount')));
        self::assertSame($summary['discount']['total'], array_sum(array_column($data, 'discount')));
    }

    /**
     * Intervalo inválido.
     * Espera 422 com a mensagem dos valores aceitos.
     */
    public function testRejectsInvalidInterval(): void
    {
        $response = $this->timeline('year');

        self::assertSame(422, $response['status']);
        self::assertSame([['field' => 'interval', 'message' => 'O intervalo deve ser day, week ou month.']], $response['body']['violations']);
    }

    // =========================================================================
    // Auxiliares
    // =========================================================================

    /**
     * Cadastra uma compra com um produto pelo POST da API, garantindo que deu certo.
     */
    private function createPurchase(string $referenceCode, string $purchasedAt, int $unitPrice, int $discount): void
    {
        $response = $this->postPurchase([
            'reference_code' => $referenceCode,
            'purchased_at' => $purchasedAt,
            'customer' => ['name' => 'João Silva', 'email' => 'joao@email.com', 'cpf' => '529.982.247-25'],
            'products' => [['external_id' => 'CAM-001', 'name' => 'Camiseta', 'quantity' => 1, 'unit_price' => $unitPrice, 'discount' => $discount]],
        ]);

        self::assertSame(201, $response['status'], 'Falha ao cadastrar a compra '.$referenceCode);
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function timeline(?string $interval): array
    {
        $this->client->request('GET', '/api/reports/timeline'.(null === $interval ? '' : '?interval='.$interval));

        return [
            'status' => $this->client->getResponse()->getStatusCode(),
            'body' => json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR),
        ];
    }
}
