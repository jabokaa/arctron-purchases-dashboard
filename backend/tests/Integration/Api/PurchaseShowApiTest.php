<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Tests\Integration\IntegrationTestCase;

/**
 * Testes de integração do endpoint GET /api/purchases/{id} (detalhe de uma compra).
 *
 * O que é testado: o fluxo completo, do jeito que o front usaria ao abrir o detalhe de uma compra:
 * a compra é cadastrada pelo próprio POST /api/purchases e depois buscada pelo id devolvido,
 * passando por rota → controller → PurchaseSearchService → repositório → PostgreSQL.
 * Nada é mockado.
 *
 * Como é testado:
 * - O banco de teste (app_test) é criado do zero com as migrations (ver IntegrationTestCase).
 * - Antes de cada teste o banco é esvaziado, ficando só o canal de venda API.
 */
final class PurchaseShowApiTest extends IntegrationTestCase
{
    /**
     * Compra de pessoa física com dois itens, cadastrada pelo POST.
     * Espera 200 e a compra completa: canal, observações, totais, cliente com documento (CPF)
     * e cada item com produto, quantidade, valor unitário, desconto e total.
     */
    public function testShowsFullPurchaseOfIndividualCustomer(): void
    {
        $id = $this->createPurchase([
            'reference_code' => 'PED-1',
            'purchased_at' => '2026-11-21T14:30:00-03:00',
            'notes' => 'Produto enviado para o cliente por SEDEX',
            'customer' => ['name' => 'João Silva', 'email' => 'joao@email.com', 'cpf' => '529.982.247-25'],
            'products' => [
                ['external_id' => 'CAM-001', 'name' => 'Camiseta', 'quantity' => 2, 'unit_price' => 5000, 'discount' => 500],
                ['external_id' => 'CAL-001', 'name' => 'Calça', 'quantity' => 1, 'unit_price' => 16010],
            ],
        ]);

        $response = $this->show($id);

        self::assertSame(200, $response['status']);

        $purchase = $response['body'];
        self::assertSame($id, $purchase['id']);
        self::assertSame('PED-1', $purchase['reference_code']);
        self::assertSame('API', $purchase['sales_channel']);
        self::assertSame('Produto enviado para o cliente por SEDEX', $purchase['notes']);
        self::assertSame('2026-11-21T17:30:00+00:00', $purchase['purchased_at']);
        self::assertSame(25510, $purchase['amount']);
        self::assertSame(500, $purchase['discount']);

        self::assertSame(
            ['type' => 'INDIVIDUAL', 'name' => 'João Silva', 'trade_name' => null, 'email' => 'joao@email.com', 'document_type' => 'CPF', 'document' => '52998224725'],
            array_diff_key($purchase['customer'], ['id' => true]),
        );

        self::assertSame([
            ['name' => 'Camiseta', 'quantity' => 2, 'unit_price' => 5000, 'discount' => 500, 'total_price' => 9500],
            ['name' => 'Calça', 'quantity' => 1, 'unit_price' => 16010, 'discount' => 0, 'total_price' => 16010],
        ], array_map(static fn (array $item): array => array_diff_key($item, ['product_id' => true]), $purchase['items']));
    }

    /**
     * Compra de pessoa jurídica, sem observações.
     * Espera o cliente com razão social, nome fantasia e documento CNPJ normalizado, e notes nulo.
     */
    public function testShowsPurchaseOfCompanyCustomer(): void
    {
        $id = $this->createPurchase([
            'reference_code' => 'PED-2',
            'purchased_at' => '2026-11-21T12:00:00Z',
            'customer' => ['name' => 'Loja Exemplo LTDA', 'trade_name' => 'Loja Bacana', 'email' => 'contato@loja.com', 'cnpj' => '12.abc.345/01de-35'],
            'products' => [['external_id' => 'CAM-001', 'name' => 'Camiseta', 'quantity' => 1, 'unit_price' => 5000]],
        ]);

        $purchase = $this->show($id)['body'];

        self::assertNull($purchase['notes']);
        self::assertSame('COMPANY', $purchase['customer']['type']);
        self::assertSame('Loja Exemplo LTDA', $purchase['customer']['name']);
        self::assertSame('Loja Bacana', $purchase['customer']['trade_name']);
        self::assertSame('CNPJ', $purchase['customer']['document_type']);
        self::assertSame('12ABC34501DE35', $purchase['customer']['document']);
    }

    /**
     * Com duas compras cadastradas, o detalhe de cada id devolve a compra certa (não a outra).
     */
    public function testReturnsThePurchaseOfTheRequestedId(): void
    {
        $first = $this->createPurchase(self::minimalPurchase('PED-A'));
        $second = $this->createPurchase(self::minimalPurchase('PED-B'));

        self::assertSame('PED-A', $this->show($first)['body']['reference_code']);
        self::assertSame('PED-B', $this->show($second)['body']['reference_code']);
    }

    /**
     * Id que não existe no banco.
     * Espera 404 com a mensagem de compra não encontrada.
     */
    public function testReturnsNotFoundForUnknownId(): void
    {
        $response = $this->show(999999);

        self::assertSame(404, $response['status']);
        self::assertSame('Compra 999999 não encontrada.', $response['body']['detail']);
    }

    /**
     * Id em texto.
     * Espera 404 de rota (a rota só aceita números).
     */
    public function testReturnsNotFoundForNonNumericId(): void
    {
        $this->client->request('GET', '/api/purchases/abc');

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    // =========================================================================
    // Auxiliares
    // =========================================================================

    /**
     * Cadastra a compra pelo POST da API e devolve o id gerado.
     *
     * @param array<string, mixed> $payload
     */
    private function createPurchase(array $payload): int
    {
        $response = $this->postPurchase($payload);
        self::assertSame(201, $response['status'], 'Falha ao cadastrar a compra '.$payload['reference_code']);

        return $response['body']['id'];
    }

    /**
     * @return array<string, mixed>
     */
    private static function minimalPurchase(string $referenceCode): array
    {
        return [
            'reference_code' => $referenceCode,
            'purchased_at' => '2026-11-21T12:00:00Z',
            'customer' => ['name' => 'João Silva', 'email' => 'joao@email.com', 'cpf' => '529.982.247-25'],
            'products' => [['external_id' => 'CAM-001', 'name' => 'Camiseta', 'quantity' => 1, 'unit_price' => 5000]],
        ];
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function show(int $id): array
    {
        $this->client->request('GET', '/api/purchases/'.$id);

        return [
            'status' => $this->client->getResponse()->getStatusCode(),
            'body' => json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR),
        ];
    }
}
