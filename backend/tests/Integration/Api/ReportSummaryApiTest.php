<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Tests\Integration\IntegrationTestCase;

/**
 * Testes de integração do endpoint GET /api/reports/summary (indicadores gerais).
 *
 * O que é testado: o fluxo completo, do jeito que o dashboard usaria:
 * as compras são cadastradas pelo próprio POST /api/purchases e depois o resumo é consultado,
 * passando por rota → controller → ReportService → repositórios → PostgreSQL.
 * Nada é mockado.
 *
 * Como é testado:
 * - O banco de teste (app_test) é criado do zero com as migrations (ver IntegrationTestCase).
 * - Antes de cada teste o banco é esvaziado, ficando só o canal de venda API.
 */
final class ReportSummaryApiTest extends IntegrationTestCase
{
    /**
     * Nenhuma compra cadastrada.
     * Espera 200 com todos os indicadores zerados.
     */
    public function testReturnsZerosWhenThereAreNoPurchases(): void
    {
        $response = $this->summary();

        self::assertSame(200, $response['status']);
        self::assertSame([
            'purchases_count' => 0,
            'amount' => ['total' => 0, 'average' => 0],
            'discount' => ['total' => 0, 'average' => 0],
            'products' => ['total' => 0, 'average' => 0, 'units_sold' => 0],
        ], $response['body']);
    }

    /**
     * Três compras cadastradas pelo POST (com e sem desconto, um ou mais itens):
     *
     *   | Compra | Itens (quantidade × valor unitário − desconto) | Valor da compra |
     *   |--------|------------------------------------------------|-----------------|
     *   | P1     | 2 × 5000 − 500  e  1 × 16010                   | 25510           |
     *   | P2     | 3 × 1000 − 100                                 |  2900           |
     *   | P3     | 1 × 999                                        |   999           |
     *
     * Espera: 3 compras; valor total 29409 (média 9803); desconto total 600 (média 200);
     * produtos 30009 antes do desconto, 7 unidades (preço médio por unidade 4287).
     */
    public function testCalculatesIndicatorsFromRegisteredPurchases(): void
    {
        $this->createPurchase('P1', [['A', 2, 5000, 500], ['B', 1, 16010, 0]]);
        $this->createPurchase('P2', [['A', 3, 1000, 100]]);
        $this->createPurchase('P3', [['C', 1, 999, 0]]);

        $response = $this->summary();

        self::assertSame(200, $response['status']);
        self::assertSame([
            'purchases_count' => 3,
            'amount' => ['total' => 29409, 'average' => 9803],
            'discount' => ['total' => 600, 'average' => 200],
            'products' => ['total' => 30009, 'average' => 4287, 'units_sold' => 7],
        ], $response['body']);
    }

    /**
     * Os indicadores fecham entre si com dados reais do banco:
     * total dos produtos − total dos descontos = total das compras.
     */
    public function testProductsMinusDiscountsEqualsPurchasesAmount(): void
    {
        $this->createPurchase('P1', [['A', 4, 2500, 1000], ['B', 2, 333, 50]]);
        $this->createPurchase('P2', [['A', 1, 7777, 777]]);

        $body = $this->summary()['body'];

        self::assertSame($body['amount']['total'], $body['products']['total'] - $body['discount']['total']);
    }

    /**
     * Uma compra recusada (dados inválidos) não pode entrar nos indicadores.
     * Espera os indicadores só da compra válida.
     */
    public function testIgnoresRejectedPurchases(): void
    {
        $this->createPurchase('P1', [['A', 1, 1000, 0]]);

        $invalid = $this->postPurchase([
            'reference_code' => 'P2',
            'purchased_at' => '2026-11-21T12:00:00Z',
            'customer' => ['name' => 'João', 'email' => 'joao@email.com', 'cpf' => '111.111.111-11'],
            'products' => [['external_id' => 'A', 'name' => 'A', 'quantity' => 1, 'unit_price' => 99999]],
        ]);
        self::assertSame(422, $invalid['status']);

        $body = $this->summary()['body'];
        self::assertSame(1, $body['purchases_count']);
        self::assertSame(1000, $body['amount']['total']);
    }

    // =========================================================================
    // Auxiliares
    // =========================================================================

    /**
     * Cadastra uma compra pelo POST da API, garantindo que deu certo.
     *
     * @param list<array{string, int, int, int}> $items [external_id, quantidade, valor unitário, desconto]
     */
    private function createPurchase(string $referenceCode, array $items): void
    {
        $response = $this->postPurchase([
            'reference_code' => $referenceCode,
            'purchased_at' => '2026-11-21T12:00:00Z',
            'customer' => ['name' => 'João Silva', 'email' => 'joao@email.com', 'cpf' => '529.982.247-25'],
            'products' => array_map(
                static fn (array $item): array => [
                    'external_id' => $item[0],
                    'name' => 'Produto '.$item[0],
                    'quantity' => $item[1],
                    'unit_price' => $item[2],
                    'discount' => $item[3],
                ],
                $items,
            ),
        ]);

        self::assertSame(201, $response['status'], 'Falha ao cadastrar a compra '.$referenceCode);
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function summary(): array
    {
        $this->client->request('GET', '/api/reports/summary');

        return [
            'status' => $this->client->getResponse()->getStatusCode(),
            'body' => json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR),
        ];
    }
}
