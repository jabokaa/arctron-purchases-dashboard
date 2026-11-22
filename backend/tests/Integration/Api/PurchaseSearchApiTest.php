<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Tests\Integration\IntegrationTestCase;

/**
 * Testes de integração do endpoint GET /api/purchases (consulta de compras).
 *
 * O que é testado: o fluxo completo, do jeito que um cliente da API usaria:
 * as compras são cadastradas pelo próprio POST /api/purchases e depois consultadas pelo GET,
 * passando por query string → validação → controller → PurchaseSearchService → repositório → PostgreSQL.
 * Nada é mockado.
 *
 * Como é testado:
 * - O banco de teste (app_test) é criado do zero com as migrations (ver IntegrationTestCase).
 * - Antes de cada teste o banco é esvaziado e as 4 compras abaixo são cadastradas pelo POST:
 *
 *   | Código    | Cliente                              | Data enviada               | Valor (centavos) |
 *   |-----------|--------------------------------------|----------------------------|------------------|
 *   | PED-001   | João Silva (CPF 529.982.247-25)      | 2026-11-01T10:00:00-03:00  |  1000            |
 *   | PED-002   | Maria Souza (CPF 111.444.777-35)     | 2026-11-10T12:00:00-03:00  |  5000            |
 *   | VENDA-003 | Loja Exemplo LTDA (CNPJ)             | 2026-11-20T12:00:00Z       | 20000            |
 *   | PED-004   | João Silva (CPF 52998224725)         | 2026-12-02T09:00:00Z       | 50000            |
 *
 * Os testes de cada combinação de filtro ficam no PurchaseRepositoryTest; aqui o foco é
 * confirmar que cada filtro funciona de ponta a ponta e que a resposta sai no formato certo.
 */
final class PurchaseSearchApiTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $joao = ['name' => 'João Silva', 'email' => 'joao@email.com', 'cpf' => '529.982.247-25'];
        $maria = ['name' => 'Maria Souza', 'email' => 'maria@gmail.com', 'cpf' => '111.444.777-35'];
        $loja = ['name' => 'Loja Exemplo LTDA', 'trade_name' => 'Loja Bacana', 'email' => 'contato@loja.com', 'cnpj' => '11.222.333/0001-81'];

        $this->createPurchase('PED-001', $joao, '2026-11-01T10:00:00-03:00', 1000);
        $this->createPurchase('PED-002', $maria, '2026-11-10T12:00:00-03:00', 5000);
        $this->createPurchase('VENDA-003', $loja, '2026-11-20T12:00:00Z', 20000);
        $this->createPurchase('PED-004', ['name' => 'João Silva', 'email' => 'joao@email.com', 'cpf' => '52998224725'], '2026-12-02T09:00:00Z', 50000);
    }

    /**
     * Consulta sem filtros.
     * Espera 200, as 4 compras da mais recente para a mais antiga e os dados de paginação padrão.
     */
    public function testListsAllPurchasesWithDefaultPagination(): void
    {
        $response = $this->search([]);

        self::assertSame(200, $response['status']);
        self::assertSame(['PED-004', 'VENDA-003', 'PED-002', 'PED-001'], self::referenceCodes($response));
        self::assertSame(['page' => 1, 'per_page' => 20, 'total' => 4, 'total_pages' => 1], $response['body']['meta']);
    }

    /**
     * Cada compra da lista sai no mesmo formato da resposta do POST,
     * com os dados gravados no banco (cliente, itens e totais calculados).
     */
    public function testReturnsPurchaseInTheSameFormatAsCreation(): void
    {
        $purchase = $this->search(['reference_code' => 'VENDA-003'])['body']['data'][0];

        self::assertSame('VENDA-003', $purchase['reference_code']);
        self::assertSame('API', $purchase['sales_channel']);
        self::assertSame(20000, $purchase['amount']);
        self::assertSame('2026-11-20T12:00:00+00:00', $purchase['purchased_at']);
        self::assertSame(
            ['type' => 'COMPANY', 'name' => 'Loja Exemplo LTDA', 'trade_name' => 'Loja Bacana', 'email' => 'contato@loja.com', 'document' => '11222333000181'],
            array_diff_key($purchase['customer'], ['id' => true]),
        );
        self::assertSame(
            ['name' => 'Camiseta', 'quantity' => 1, 'unit_price' => 20000, 'discount' => 0, 'total_price' => 20000],
            array_diff_key($purchase['items'][0], ['product_id' => true]),
        );
    }

    /**
     * Filtro por parte do código de referência, em minúsculas.
     * Espera as 3 compras com "PED" no código.
     */
    public function testFiltersByReferenceCode(): void
    {
        self::assertSame(['PED-004', 'PED-002', 'PED-001'], self::referenceCodes($this->search(['reference_code' => 'ped'])));
    }

    /**
     * Filtro por nome do cliente, com acento (enviado codificado na URL, como faria um navegador).
     * Espera as 2 compras do João.
     */
    public function testFiltersByCustomerName(): void
    {
        self::assertSame(['PED-004', 'PED-001'], self::referenceCodes($this->search(['customer_name' => 'joão'])));
    }

    /**
     * Filtro por nome fantasia de pessoa jurídica.
     * Espera a compra da loja.
     */
    public function testFiltersByCompanyTradeName(): void
    {
        self::assertSame(['VENDA-003'], self::referenceCodes($this->search(['customer_name' => 'bacana'])));
    }

    /**
     * Filtro por parte do e-mail.
     * Espera a compra da Maria.
     */
    public function testFiltersByCustomerEmail(): void
    {
        self::assertSame(['PED-002'], self::referenceCodes($this->search(['customer_email' => 'gmail'])));
    }

    /**
     * Filtro por CPF com máscara: as compras do João foram cadastradas uma com e outra sem máscara.
     * Espera as duas, porque o CPF é gravado e buscado sem máscara.
     */
    public function testFiltersByCpf(): void
    {
        self::assertSame(['PED-004', 'PED-001'], self::referenceCodes($this->search(['customer_document' => '529.982.247-25'])));
    }

    /**
     * Filtro por CNPJ sem máscara (foi cadastrado com máscara).
     * Espera a compra da loja.
     */
    public function testFiltersByCnpj(): void
    {
        self::assertSame(['VENDA-003'], self::referenceCodes($this->search(['customer_document' => '11222333000181'])));
    }

    /**
     * Filtro por intervalo de datas (dias inteiros, limites incluídos).
     * Espera as compras de 10/11 e 20/11.
     */
    public function testFiltersByDateRange(): void
    {
        self::assertSame(['VENDA-003', 'PED-002'], self::referenceCodes($this->search(['date_from' => '2026-11-10', 'date_to' => '2026-11-20'])));
    }

    /**
     * Filtro por intervalo de valores (em centavos, limites incluídos).
     * Espera as compras de 5000 e 20000.
     */
    public function testFiltersByAmountRange(): void
    {
        self::assertSame(['VENDA-003', 'PED-002'], self::referenceCodes($this->search(['amount_min' => 5000, 'amount_max' => 20000])));
    }

    /**
     * Vários filtros juntos: todos precisam bater.
     * Espera só a compra do João acima de 100,00.
     */
    public function testCombinesFilters(): void
    {
        self::assertSame(['PED-004'], self::referenceCodes($this->search(['customer_name' => 'joão', 'amount_min' => 10000])));
    }

    /**
     * Segunda página com 3 compras por página.
     * Espera a 4ª compra e os dados de paginação (4 compras, 2 páginas).
     */
    public function testPaginates(): void
    {
        $response = $this->search(['page' => 2, 'per_page' => 3]);

        self::assertSame(['PED-001'], self::referenceCodes($response));
        self::assertSame(['page' => 2, 'per_page' => 3, 'total' => 4, 'total_pages' => 2], $response['body']['meta']);
    }

    /**
     * Filtro sem nenhuma compra correspondente.
     * Espera 200 com lista vazia (não é erro).
     */
    public function testReturnsEmptyListWhenNothingMatches(): void
    {
        $response = $this->search(['customer_name' => 'ninguém']);

        self::assertSame(200, $response['status']);
        self::assertSame([], $response['body']['data']);
        self::assertSame(0, $response['body']['meta']['total']);
    }

    /**
     * Filtro inválido (data em formato errado).
     * Espera 422 com a mensagem em português no campo date_from.
     */
    public function testRejectsInvalidFilter(): void
    {
        $response = $this->search(['date_from' => '01/11/2026']);

        self::assertSame(422, $response['status']);
        self::assertSame(
            [['field' => 'date_from', 'message' => 'Data inválida. Use AAAA-MM-DD (ex.: 2026-11-21) ou ISO 8601 (ex.: 2026-11-21T14:30:00-03:00).']],
            $response['body']['violations'],
        );
    }

    // =========================================================================
    // Auxiliares
    // =========================================================================

    /**
     * Cadastra uma compra pelo POST da API (com um produto), garantindo que deu certo.
     *
     * @param array<string, string> $customer
     */
    private function createPurchase(string $referenceCode, array $customer, string $purchasedAt, int $amount): void
    {
        $response = $this->postPurchase([
            'reference_code' => $referenceCode,
            'purchased_at' => $purchasedAt,
            'customer' => $customer,
            'products' => [['external_id' => 'CAM-001', 'name' => 'Camiseta', 'quantity' => 1, 'unit_price' => $amount]],
        ]);

        self::assertSame(201, $response['status'], 'Falha ao cadastrar a compra '.$referenceCode);
    }

    /**
     * Faz a consulta com os filtros na query string (codificados como um navegador faria).
     *
     * @param array<string, string|int> $query
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private function search(array $query): array
    {
        $this->client->request('GET', '/api/purchases?'.http_build_query($query));

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
    private static function referenceCodes(array $response): array
    {
        return array_column($response['body']['data'], 'reference_code');
    }
}
