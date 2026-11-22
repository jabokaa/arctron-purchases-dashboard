<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Tests\Integration\IntegrationTestCase;

/**
 * Testes de integração do endpoint GET /api/reports/products (ranking de produtos).
 *
 * O que é testado: o fluxo completo, do jeito que a aba "Produtos" e o top 5 da home usariam:
 * as compras são cadastradas pelo próprio POST /api/purchases e depois o ranking é consultado,
 * passando por query string → validação → controller → ReportService → repositório → PostgreSQL.
 * Nada é mockado.
 *
 * Como é testado:
 * - O banco de teste (app_test) é criado do zero com as migrations (ver IntegrationTestCase).
 * - Antes de cada teste o banco é esvaziado e as 3 compras abaixo são cadastradas pelo POST
 *   (itens: quantidade × valor unitário − desconto):
 *
 *   | Compra | Itens                                          |
 *   |--------|------------------------------------------------|
 *   | P1     | Camiseta 2 × 5000 − 1000;  Boné 1 × 3000       |
 *   | P2     | Camiseta 1 × 5000                              |
 *   | P3     | Meia 10 × 500;  Camiseta 1 × 4000              |
 *
 *   Resultado: Camiseta 4 unidades / 3 compras / 18000 / média 4500;
 *              Meia 10 / 1 / 5000 / 500;  Boné 1 / 1 / 3000 / 3000.
 *
 * As combinações de ordenação e paginação ficam no ProductRankingTest; aqui o foco é confirmar
 * o fluxo de ponta a ponta e o formato da resposta.
 */
final class ReportProductsApiTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $camiseta = static fn (int $quantity, int $unitPrice, int $discount = 0): array => ['external_id' => 'CAM', 'name' => 'Camiseta', 'quantity' => $quantity, 'unit_price' => $unitPrice, 'discount' => $discount];

        $this->createPurchase('P1', [$camiseta(2, 5000, 1000), ['external_id' => 'BON', 'name' => 'Boné', 'quantity' => 1, 'unit_price' => 3000]]);
        $this->createPurchase('P2', [$camiseta(1, 5000)]);
        $this->createPurchase('P3', [['external_id' => 'MEIA', 'name' => 'Meia', 'quantity' => 10, 'unit_price' => 500], $camiseta(1, 4000)]);
    }

    /**
     * Sem parâmetros: maior valor vendido primeiro.
     * Espera 200, os 3 produtos com unidades, compras, total e preço médio, e a paginação padrão.
     */
    public function testRanksByTotalByDefault(): void
    {
        $response = $this->ranking([]);

        self::assertSame(200, $response['status']);
        self::assertSame([
            ['Camiseta', 4, 3, 18000, 4500],
            ['Meia', 10, 1, 5000, 500],
            ['Boné', 1, 1, 3000, 3000],
        ], self::summary($response));
        self::assertSame(['page' => 1, 'limit' => 20, 'total' => 3, 'total_pages' => 1], $response['body']['meta']);
    }

    /**
     * Cada produto sai com id, nome e código interno (produtos criados pelas compras não têm código interno).
     */
    public function testReturnsProductData(): void
    {
        $product = $this->ranking([])['body']['data'][0]['product'];

        self::assertSame('Camiseta', $product['name']);
        self::assertIsInt($product['id']);
        self::assertNull($product['internal_code']);
    }

    /**
     * Mais vendidos em unidades.
     * Espera a Meia (10 unidades) primeiro.
     */
    public function testRanksByQuantity(): void
    {
        self::assertSame(['Meia', 'Camiseta', 'Boné'], self::names($this->ranking(['sort' => 'quantity'])));
    }

    /**
     * Menor preço médio por unidade primeiro.
     */
    public function testRanksByAverageAscending(): void
    {
        self::assertSame(['Meia', 'Boné', 'Camiseta'], self::names($this->ranking(['sort' => 'average', 'order' => 'asc'])));
    }

    /**
     * Top 2, como no top 5 da home.
     * Espera só os 2 primeiros e a paginação indicando 2 páginas.
     */
    public function testLimitsResults(): void
    {
        $response = $this->ranking(['limit' => 2]);

        self::assertSame(['Camiseta', 'Meia'], self::names($response));
        self::assertSame(['page' => 1, 'limit' => 2, 'total' => 3, 'total_pages' => 2], $response['body']['meta']);
    }

    /**
     * O valor vendido por produto (já com o desconto dos itens) soma o valor total das compras
     * dos indicadores gerais (GET /api/reports/summary).
     */
    public function testRankingAddsUpToTheSummary(): void
    {
        $data = $this->ranking([])['body']['data'];

        $this->client->request('GET', '/api/reports/summary');
        $summary = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertSame($summary['amount']['total'], array_sum(array_column($data, 'total')));
        self::assertSame($summary['products']['units_sold'], array_sum(array_column($data, 'quantity_sold')));
    }

    /**
     * Parâmetro inválido.
     * Espera 422 com a mensagem dos critérios aceitos.
     */
    public function testRejectsInvalidSort(): void
    {
        $response = $this->ranking(['sort' => 'count']);

        self::assertSame(422, $response['status']);
        self::assertSame([['field' => 'sort', 'message' => 'A ordenação deve ser total, average ou quantity.']], $response['body']['violations']);
    }

    // =========================================================================
    // Auxiliares
    // =========================================================================

    /**
     * @param list<array<string, mixed>> $products
     */
    private function createPurchase(string $referenceCode, array $products): void
    {
        $response = $this->postPurchase([
            'reference_code' => $referenceCode,
            'purchased_at' => '2026-11-21T12:00:00Z',
            'customer' => ['name' => 'João Silva', 'email' => 'joao@email.com', 'cpf' => '529.982.247-25'],
            'products' => $products,
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
        $this->client->request('GET', '/api/reports/products?'.http_build_query($query));

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
        return array_map(static fn (array $row): string => $row['product']['name'], $response['body']['data']);
    }

    /**
     * @param array{body: array<string, mixed>} $response
     *
     * @return list<array{string, int, int, int, int}>
     */
    private static function summary(array $response): array
    {
        return array_map(
            static fn (array $row): array => [$row['product']['name'], $row['quantity_sold'], $row['purchases_count'], $row['total'], $row['average']],
            $response['body']['data'],
        );
    }
}
