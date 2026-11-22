<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Tests\Integration\IntegrationTestCase;

/**
 * Testes de integração do endpoint POST /api/purchases.
 *
 * O que é testado: o fluxo completo de uma compra, do jeito que acontece em produção:
 * requisição HTTP → validação → controller → PurchaseService → Doctrine → PostgreSQL.
 * Nada é mockado: o service, os repositórios e o banco são os reais.
 *
 * Como é testado:
 * - O banco de teste (app_test) é criado do zero com as migrations (ver IntegrationTestCase).
 * - Antes de cada teste o banco é esvaziado, ficando só o canal de venda API.
 * - Cada teste envia uma requisição real e depois confere, com SQL direto no banco,
 *   o que foi gravado (ou que nada foi gravado, nos casos de erro).
 */
final class PurchaseApiTest extends IntegrationTestCase
{
    /**
     * Compra de pessoa física com dois produtos novos.
     * Espera 201 e que o banco tenha: o cliente (customers + individual_customers),
     * os produtos com o vínculo ao canal API, a compra e os itens com os totais calculados.
     */
    public function testCreatesPurchaseForIndividualCustomerAndSavesEverything(): void
    {
        $response = $this->postPurchase(self::payload());

        self::assertSame(201, $response['status']);
        self::assertSame('PED-1', $response['body']['reference_code']);
        self::assertSame('INDIVIDUAL', $response['body']['customer']['type']);

        // Cliente: tabela base + tabela da pessoa física, ligadas pelo mesmo id
        $customer = $this->connection()->fetchAssociative(
            'SELECT c.person_type, c.email, i.full_name, i.cpf
             FROM customers c JOIN individual_customers i ON i.id = c.id',
        );
        self::assertSame(['person_type' => 'INDIVIDUAL', 'email' => 'joao@email.com', 'full_name' => 'João Silva', 'cpf' => '52998224725'], $customer);
        self::assertSame(0, $this->countRows('company_customers'));

        // Produtos criados com o vínculo ao canal API
        $products = $this->connection()->fetchAllAssociative(
            'SELECT p.name, e.external_id, s.code AS channel
             FROM products p
             JOIN product_external_ids e ON e.product_id = p.id
             JOIN sales_channels s ON s.id = e.sales_channel_id
             ORDER BY p.id',
        );
        self::assertSame([
            ['name' => 'Camiseta', 'external_id' => 'CAM-001', 'channel' => 'API'],
            ['name' => 'Calça', 'external_id' => 'CAL-001', 'channel' => 'API'],
        ], $products);

        // Compra: totais = soma dos itens (9500 + 16010 = 25510; desconto 500 + 0)
        $purchase = $this->connection()->fetchAssociative(
            'SELECT p.reference_code, p.amount, p.discount, p.notes, s.code AS channel
             FROM purchases p JOIN sales_channels s ON s.id = p.sales_channel_id',
        );
        self::assertSame([
            'reference_code' => 'PED-1',
            'amount' => 25510,
            'discount' => 500,
            'notes' => 'Produto enviado para o cliente por SEDEX',
            'channel' => 'API',
        ], $purchase);

        // Itens: total = quantidade × preço unitário − desconto
        $items = $this->connection()->fetchAllAssociative('SELECT quantity, unit_price, discount, total_price FROM purchase_items ORDER BY id');
        self::assertSame([
            ['quantity' => 2, 'unit_price' => 5000, 'discount' => 500, 'total_price' => 9500],
            ['quantity' => 1, 'unit_price' => 16010, 'discount' => 0, 'total_price' => 16010],
        ], $items);
    }

    /**
     * Compra de pessoa jurídica com CNPJ alfanumérico, em minúsculas e com máscara.
     * Espera 201 e a empresa gravada em company_customers com o CNPJ normalizado
     * (sem máscara e em maiúsculas), razão social e nome fantasia.
     */
    public function testCreatesPurchaseForCompanyCustomer(): void
    {
        $payload = self::payload();
        $payload['customer'] = [
            'name' => 'Loja Exemplo LTDA',
            'trade_name' => 'Loja Exemplo',
            'email' => 'contato@loja.com',
            'cnpj' => '12.abc.345/01de-35',
        ];

        $response = $this->postPurchase($payload);

        self::assertSame(201, $response['status']);
        self::assertSame('COMPANY', $response['body']['customer']['type']);

        $company = $this->connection()->fetchAssociative(
            'SELECT c.person_type, co.legal_name, co.trade_name, co.cnpj
             FROM customers c JOIN company_customers co ON co.id = c.id',
        );
        self::assertSame(['person_type' => 'COMPANY', 'legal_name' => 'Loja Exemplo LTDA', 'trade_name' => 'Loja Exemplo', 'cnpj' => '12ABC34501DE35'], $company);
        self::assertSame(0, $this->countRows('individual_customers'));
    }

    /**
     * Duas compras do mesmo cliente: na primeira o CPF vem com máscara, na segunda sem
     * e com nome e e-mail diferentes.
     * Espera um único cliente no banco (reaproveitado), com os dados da segunda compra,
     * e as duas compras ligadas a ele.
     */
    public function testReusesCustomerByCpfAndUpdatesData(): void
    {
        $this->postPurchase(self::payload(referenceCode: 'PED-1'));

        $second = self::payload(referenceCode: 'PED-2');
        $second['customer'] = ['name' => 'João S.', 'email' => 'novo@email.com', 'cpf' => '52998224725'];
        self::assertSame(201, $this->postPurchase($second)['status']);

        self::assertSame(1, $this->countRows('customers'));
        self::assertSame(
            ['full_name' => 'João S.', 'email' => 'novo@email.com'],
            $this->connection()->fetchAssociative('SELECT i.full_name, c.email FROM customers c JOIN individual_customers i ON i.id = c.id'),
        );
        self::assertSame(1, (int) $this->connection()->fetchOne('SELECT COUNT(DISTINCT customer_id) FROM purchases'));
        self::assertSame(2, $this->countRows('purchases'));
    }

    /**
     * Segunda compra com um produto que já existe (mesmo external_id no canal API), mas com outro nome.
     * Espera que o produto seja reaproveitado (nenhum produto novo) e que o nome do cadastro não mude.
     */
    public function testReusesExistingProductByExternalId(): void
    {
        $this->postPurchase(self::payload(referenceCode: 'PED-1'));

        $second = self::payload(referenceCode: 'PED-2');
        $second['products'] = [['external_id' => 'CAM-001', 'name' => 'Camiseta Azul M', 'quantity' => 1, 'unit_price' => 5000]];
        self::assertSame(201, $this->postPurchase($second)['status']);

        self::assertSame(2, $this->countRows('products'), 'Só os 2 produtos da primeira compra devem existir.');
        self::assertSame(
            'Camiseta',
            $this->connection()->fetchOne("SELECT p.name FROM products p JOIN product_external_ids e ON e.product_id = p.id WHERE e.external_id = 'CAM-001'"),
        );
    }

    /**
     * O mesmo produto novo aparece duas vezes na compra (uma com external_id texto, outra número).
     * Espera um único produto criado e dois itens apontando para ele.
     */
    public function testRepeatedProductInSamePurchaseCreatesProductOnce(): void
    {
        $payload = self::payload();
        $payload['products'] = [
            ['external_id' => '777', 'name' => 'Tênis', 'quantity' => 1, 'unit_price' => 3000],
            ['external_id' => 777, 'name' => 'Tênis', 'quantity' => 2, 'unit_price' => 3000],
        ];

        self::assertSame(201, $this->postPurchase($payload)['status']);

        self::assertSame(1, $this->countRows('products'));
        self::assertSame(2, $this->countRows('purchase_items'));
        self::assertSame(1, (int) $this->connection()->fetchOne('SELECT COUNT(DISTINCT product_id) FROM purchase_items'));
    }

    /**
     * Data da compra enviada com fuso -03:00.
     * Espera que o banco guarde o horário em UTC (14:30 -03:00 = 17:30 UTC).
     */
    public function testStoresPurchaseDateInUtc(): void
    {
        $this->postPurchase(self::payload());

        self::assertSame('2026-11-21 17:30:00', $this->connection()->fetchOne('SELECT purchased_at FROM purchases'));
    }

    /**
     * A mesma compra enviada duas vezes (mesmo reference_code).
     * Espera 409 na segunda e que o banco continue com uma compra só,
     * sem itens ou produtos a mais.
     */
    public function testRejectsDuplicatePurchase(): void
    {
        self::assertSame(201, $this->postPurchase(self::payload())['status']);

        $response = $this->postPurchase(self::payload());

        self::assertSame(409, $response['status']);
        self::assertSame('Já existe uma compra com o código de referência "PED-1".', $response['body']['detail']);
        self::assertSame(1, $this->countRows('purchases'));
        self::assertSame(2, $this->countRows('purchase_items'));
        self::assertSame(2, $this->countRows('products'));
    }

    /**
     * Payload inválido (CPF com dígito verificador errado).
     * Espera 422 e que NADA seja gravado: nem cliente, nem produto, nem compra.
     */
    public function testInvalidPayloadSavesNothing(): void
    {
        $payload = self::payload();
        $payload['customer']['cpf'] = '529.982.247-24';

        $response = $this->postPurchase($payload);

        self::assertSame(422, $response['status']);
        self::assertContains(['field' => 'customer.cpf', 'message' => 'O CPF "529.982.247-24" não é válido.'], $response['body']['violations']);
        self::assertSame(0, $this->countRows('customers'));
        self::assertSame(0, $this->countRows('products'));
        self::assertSame(0, $this->countRows('purchases'));
    }

    /**
     * O canal de venda API foi removido do banco (seed ausente).
     * Espera 500 com mensagem genérica e que nada seja gravado.
     */
    public function testMissingSalesChannelReturnsErrorAndSavesNothing(): void
    {
        $this->connection()->executeStatement("DELETE FROM sales_channels WHERE code = 'API'");

        $response = $this->postPurchase(self::payload());

        self::assertSame(500, $response['status']);
        self::assertSame('Ocorreu um erro inesperado. Tente novamente mais tarde.', $response['body']['detail']);
        self::assertSame(0, $this->countRows('customers'));
        self::assertSame(0, $this->countRows('purchases'));
    }

    /**
     * Payload válido de referência; cada teste troca só o que precisa.
     *
     * @return array<string, mixed>
     */
    private static function payload(string $referenceCode = 'PED-1'): array
    {
        return [
            'reference_code' => $referenceCode,
            'purchased_at' => '2026-11-21T14:30:00-03:00',
            'notes' => 'Produto enviado para o cliente por SEDEX',
            'customer' => [
                'name' => 'João Silva',
                'email' => 'joao@email.com',
                'cpf' => '529.982.247-25',
            ],
            'products' => [
                ['external_id' => 'CAM-001', 'name' => 'Camiseta', 'quantity' => 2, 'unit_price' => 5000, 'discount' => 500],
                ['external_id' => 'CAL-001', 'name' => 'Calça', 'quantity' => 1, 'unit_price' => 16010],
            ],
        ];
    }
}
