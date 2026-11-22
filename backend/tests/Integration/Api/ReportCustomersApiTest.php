<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Tests\Integration\IntegrationTestCase;

/**
 * Testes de integração do endpoint GET /api/reports/customers (ranking de clientes).
 *
 * O que é testado: o fluxo completo, do jeito que a aba "Clientes" e o top 5 da home usariam:
 * as compras são cadastradas pelo próprio POST /api/purchases e depois o ranking é consultado,
 * passando por query string → validação → controller → ReportService → repositório → PostgreSQL.
 * Nada é mockado.
 *
 * Como é testado:
 * - O banco de teste (app_test) é criado do zero com as migrations (ver IntegrationTestCase).
 * - Antes de cada teste o banco é esvaziado e as compras abaixo são cadastradas pelo POST:
 *
 *   | Cliente                     | Compras (valor) | Qtd | Total | Média |
 *   |-----------------------------|-----------------|-----|-------|-------|
 *   | João Silva (CPF)            | 1000, 2000, 3000| 3   | 6000  | 2000  |
 *   | Maria Souza (CPF)           | 10000           | 1   | 10000 | 10000 |
 *   | Loja LTDA (CNPJ)            | 4000, 4000      | 2   | 8000  | 4000  |
 *
 * As combinações de ordenação e paginação ficam no CustomerRankingTest; aqui o foco é confirmar
 * o fluxo de ponta a ponta e o formato da resposta.
 */
final class ReportCustomersApiTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $joao = ['name' => 'João Silva', 'email' => 'joao@email.com', 'cpf' => '529.982.247-25'];
        $maria = ['name' => 'Maria Souza', 'email' => 'maria@gmail.com', 'cpf' => '111.444.777-35'];
        $loja = ['name' => 'Loja LTDA', 'trade_name' => 'Loja Bacana', 'email' => 'c@loja.com', 'cnpj' => '11.222.333/0001-81'];

        foreach ([[$joao, 1000], [$joao, 2000], [$joao, 3000], [$maria, 10000], [$loja, 4000], [$loja, 4000]] as $index => [$customer, $amount]) {
            $this->createPurchase('PED-'.$index, $customer, $amount);
        }
    }

    /**
     * Sem parâmetros: maior valor total primeiro.
     * Espera 200, os 3 clientes com quantidade, total e média, e a paginação padrão.
     */
    public function testRanksByTotalByDefault(): void
    {
        $response = $this->ranking([]);

        self::assertSame(200, $response['status']);
        self::assertSame([
            ['Maria Souza', 1, 10000, 10000],
            ['Loja LTDA', 2, 8000, 4000],
            ['João Silva', 3, 6000, 2000],
        ], self::summary($response));
        self::assertSame(['page' => 1, 'limit' => 20, 'total' => 3, 'total_pages' => 1], $response['body']['meta']);
    }

    /**
     * Cada cliente sai no mesmo formato usado nas compras (tipo, nome, documento).
     */
    public function testReturnsCustomerInTheSameFormatAsPurchases(): void
    {
        $customer = $this->ranking([])['body']['data'][1]['customer'];

        self::assertSame(
            ['type' => 'COMPANY', 'name' => 'Loja LTDA', 'trade_name' => 'Loja Bacana', 'email' => 'c@loja.com', 'document_type' => 'CNPJ', 'document' => '11222333000181'],
            array_diff_key($customer, ['id' => true]),
        );
    }

    /**
     * Ordenado pela quantidade de compras.
     * Espera o João (3 compras) primeiro.
     */
    public function testRanksByCount(): void
    {
        self::assertSame(['João Silva', 'Loja LTDA', 'Maria Souza'], self::names($this->ranking(['sort' => 'count'])));
    }

    /**
     * Ordenado pelo valor médio, do menor para o maior.
     * Espera o João (média 2000) primeiro.
     */
    public function testRanksByAverageAscending(): void
    {
        self::assertSame(['João Silva', 'Loja LTDA', 'Maria Souza'], self::names($this->ranking(['sort' => 'average', 'order' => 'asc'])));
    }

    /**
     * Top 2, como no top 5 da home.
     * Espera só os 2 primeiros e a paginação indicando 2 páginas.
     */
    public function testLimitsResults(): void
    {
        $response = $this->ranking(['limit' => 2]);

        self::assertSame(['Maria Souza', 'Loja LTDA'], self::names($response));
        self::assertSame(['page' => 1, 'limit' => 2, 'total' => 3, 'total_pages' => 2], $response['body']['meta']);
    }

    /**
     * Os totais do ranking batem com os indicadores gerais (GET /api/reports/summary).
     */
    public function testRankingAddsUpToTheSummary(): void
    {
        $data = $this->ranking([])['body']['data'];

        $this->client->request('GET', '/api/reports/summary');
        $summary = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertSame($summary['purchases_count'], array_sum(array_column($data, 'purchases_count')));
        self::assertSame($summary['amount']['total'], array_sum(array_column($data, 'total')));
    }

    /**
     * Parâmetro inválido.
     * Espera 422 com a mensagem dos critérios aceitos.
     */
    public function testRejectsInvalidSort(): void
    {
        $response = $this->ranking(['sort' => 'name']);

        self::assertSame(422, $response['status']);
        self::assertSame([['field' => 'sort', 'message' => 'A ordenação deve ser total, average ou count.']], $response['body']['violations']);
    }

    // =========================================================================
    // Auxiliares
    // =========================================================================

    /**
     * @param array<string, string> $customer
     */
    private function createPurchase(string $referenceCode, array $customer, int $amount): void
    {
        $response = $this->postPurchase([
            'reference_code' => $referenceCode,
            'purchased_at' => '2026-11-21T12:00:00Z',
            'customer' => $customer,
            'products' => [['external_id' => 'CAM-001', 'name' => 'Camiseta', 'quantity' => 1, 'unit_price' => $amount]],
        ]);

        self::assertSame(201, $response['status'], 'Falha ao cadastrar a compra '.$referenceCode);
    }

    /**
     * @param array<string, string|int> $query
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private function ranking(array $query): array
    {
        $this->client->request('GET', '/api/reports/customers?'.http_build_query($query));

        return [
            'status' => $this->client->getResponse()->getStatusCode(),
            'body' => json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR),
        ];
    }

    /**
     * @param array{body: array<string, mixed>} $response
     *
     * @return list<string>
     */
    private static function names(array $response): array
    {
        return array_map(static fn (array $row): string => $row['customer']['name'], $response['body']['data']);
    }

    /**
     * @param array{body: array<string, mixed>} $response
     *
     * @return list<array{string, int, int, int}>
     */
    private static function summary(array $response): array
    {
        return array_map(
            static fn (array $row): array => [$row['customer']['name'], $row['purchases_count'], $row['total'], $row['average']],
            $response['body']['data'],
        );
    }
}
