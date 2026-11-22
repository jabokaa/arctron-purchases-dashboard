<?php

declare(strict_types=1);

namespace App\Tests\Unit\Integration\WooCommerce;

use App\Integration\WooCommerce\OrderMapper;
use PHPUnit\Framework\TestCase;

/**
 * Testes unitários do OrderMapper (pedido do WooCommerce → PurchaseInput do endpoint de compras).
 *
 * O que é testado: o "de → para" de cada campo do pedido do WooCommerce (formato da REST API v3):
 * código de referência, data em UTC, observação, cliente pessoa física e jurídica (CPF/CNPJ do plugin
 * Brazilian Market, nos dois lugares onde ele pode vir) e itens (id da variação, preço unitário e desconto).
 * Campos ausentes viram null, para a validação do PurchaseInput apontar o erro.
 */
final class OrderMapperTest extends TestCase
{
    private OrderMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new OrderMapper();
    }

    /**
     * Pedido completo de pessoa física.
     * Espera o código "WC-" + id, a data em UTC, a observação, o cliente com CPF e os itens.
     */
    public function testMapsOrderOfIndividualCustomer(): void
    {
        $input = $this->mapper->toPurchaseInput(self::order());

        self::assertSame('WC-727', $input->referenceCode);
        self::assertSame('2026-11-21T17:30:00+00:00', $input->purchasedAt->format(\DATE_ATOM));
        self::assertSame('Entregar no portão', $input->notes);

        self::assertSame('João Silva', $input->customer->name);
        self::assertSame('joao@email.com', $input->customer->email);
        self::assertSame('529.982.247-25', $input->customer->cpf);
        self::assertNull($input->customer->cnpj);
        self::assertFalse($input->customer->isCompany());

        self::assertCount(2, $input->products);
    }

    /**
     * Item com desconto e produto com variação (ex.: tamanho M).
     * Espera o id da variação como external_id, valor unitário = subtotal ÷ quantidade
     * e desconto = subtotal − total, em centavos.
     */
    public function testMapsLineItemWithVariationAndDiscount(): void
    {
        $item = $this->mapper->toPurchaseInput(self::order())->products[0];

        self::assertSame('101', $item->externalId);
        self::assertSame('Camiseta Azul - M', $item->name);
        self::assertSame(2, $item->quantity);
        self::assertSame(5000, $item->unitPrice);   // 100.00 ÷ 2
        self::assertSame(1000, $item->discount);    // 100.00 − 90.00
    }

    /**
     * Produto simples (sem variação) e sem desconto.
     * Espera o id do produto como external_id e desconto 0.
     */
    public function testMapsSimpleProductWithoutDiscount(): void
    {
        $item = $this->mapper->toPurchaseInput(self::order())->products[1];

        self::assertSame('22', $item->externalId);
        self::assertSame(3000, $item->unitPrice);
        self::assertSame(0, $item->discount);
    }

    /**
     * Subtotal que não divide exato pela quantidade (R$ 100,00 em 3 unidades).
     * Espera o valor unitário arredondado para o centavo (3333) e o desconto exato do WooCommerce.
     */
    public function testRoundsUnitPriceWhenSubtotalIsNotDivisible(): void
    {
        $order = self::order();
        $order['line_items'] = [['name' => 'Kit', 'product_id' => 5, 'quantity' => 3, 'subtotal' => '100.00', 'total' => '100.00']];

        $item = $this->mapper->toPurchaseInput($order)->products[0];

        self::assertSame(3333, $item->unitPrice);
        self::assertSame(0, $item->discount);
    }

    /**
     * Pessoa jurídica com o CNPJ no meta_data (outra forma de o plugin enviar) e tipo de pessoa 2.
     * Espera o nome da empresa (billing.company) como nome do cliente e o CNPJ, sem CPF.
     */
    public function testMapsCompanyCustomerFromMetaData(): void
    {
        $order = self::order();
        $order['billing'] = ['first_name' => 'Maria', 'last_name' => 'Souza', 'company' => 'Loja Exemplo LTDA', 'email' => 'c@loja.com'];
        $order['meta_data'] = [
            ['id' => 1, 'key' => '_billing_persontype', 'value' => '2'],
            ['id' => 2, 'key' => '_billing_cnpj', 'value' => '11.222.333/0001-81'],
            ['id' => 3, 'key' => '_billing_cpf', 'value' => '111.444.777-35'],
        ];

        $customer = $this->mapper->toPurchaseInput($order)->customer;

        self::assertTrue($customer->isCompany());
        self::assertSame('Loja Exemplo LTDA', $customer->name);
        self::assertSame('11.222.333/0001-81', $customer->cnpj);
        self::assertNull($customer->cpf);
    }

    /**
     * Pessoa jurídica sem o nome da empresa preenchido.
     * Espera o nome da pessoa de contato como nome do cliente.
     */
    public function testUsesContactNameWhenCompanyNameIsMissing(): void
    {
        $order = self::order();
        $order['billing'] = ['first_name' => 'Maria', 'last_name' => 'Souza', 'company' => '', 'email' => 'c@loja.com', 'cnpj' => '11.222.333/0001-81'];

        self::assertSame('Maria Souza', $this->mapper->toPurchaseInput($order)->customer->name);
    }

    /**
     * Pedido sem CPF e sem CNPJ (loja sem o plugin brasileiro).
     * Espera os dois documentos nulos, para a validação apontar que falta o documento.
     */
    public function testLeavesDocumentsNullWhenMissing(): void
    {
        $order = self::order();
        $order['billing'] = ['first_name' => 'João', 'last_name' => 'Silva', 'email' => 'joao@email.com'];

        $customer = $this->mapper->toPurchaseInput($order)->customer;

        self::assertNull($customer->cpf);
        self::assertNull($customer->cnpj);
    }

    /**
     * Pedido quase vazio (só o id).
     * Espera os campos obrigatórios nulos (a validação aponta cada um) e observação vazia como null.
     */
    public function testLeavesMissingFieldsNull(): void
    {
        $input = $this->mapper->toPurchaseInput(['id' => 1, 'customer_note' => '']);

        self::assertSame('WC-1', $input->referenceCode);
        self::assertNull($input->purchasedAt);
        self::assertNull($input->notes);
        self::assertNull($input->customer->name);
        self::assertNull($input->customer->email);
        self::assertSame([], $input->products);
    }

    /**
     * Item de produto apagado no WooCommerce (product_id 0) e sem quantidade.
     * Espera external_id e valor unitário nulos, para a validação apontar o erro.
     */
    public function testLeavesInvalidLineItemFieldsNull(): void
    {
        $order = self::order();
        $order['line_items'] = [['name' => 'Produto apagado', 'product_id' => 0, 'variation_id' => 0, 'subtotal' => '10.00', 'total' => '10.00']];

        $item = $this->mapper->toPurchaseInput($order)->products[0];

        self::assertNull($item->externalId);
        self::assertNull($item->quantity);
        self::assertNull($item->unitPrice);
    }

    /**
     * Data do pedido ilegível.
     * Espera a data nula, para a validação apontar "data da compra obrigatória".
     */
    public function testLeavesUnreadableDateNull(): void
    {
        $order = self::order();
        $order['date_created_gmt'] = 'data-invalida';

        self::assertNull($this->mapper->toPurchaseInput($order)->purchasedAt);
    }

    /**
     * Valor do item que não é número.
     * Espera InvalidArgumentException (o worker grava o evento como failed).
     */
    public function testRejectsInvalidMoneyValue(): void
    {
        $order = self::order();
        $order['line_items'][0]['subtotal'] = 'cem reais';

        $this->expectException(\InvalidArgumentException::class);

        $this->mapper->toPurchaseInput($order);
    }

    /**
     * Pedido de exemplo no formato da REST API v3 do WooCommerce (pessoa física, 2 itens).
     *
     * @return array<string, mixed>
     */
    private static function order(): array
    {
        return [
            'id' => 727,
            'number' => '727',
            'status' => 'processing',
            'date_created' => '2026-11-21T14:30:00',
            'date_created_gmt' => '2026-11-21T17:30:00',
            'customer_note' => 'Entregar no portão',
            'billing' => [
                'first_name' => 'João',
                'last_name' => 'Silva',
                'company' => '',
                'email' => 'joao@email.com',
                'persontype' => '1',
                'cpf' => '529.982.247-25',
                'cnpj' => '',
            ],
            'meta_data' => [],
            'line_items' => [
                ['id' => 315, 'name' => 'Camiseta Azul - M', 'product_id' => 93, 'variation_id' => 101, 'quantity' => 2, 'subtotal' => '100.00', 'total' => '90.00'],
                ['id' => 316, 'name' => 'Boné', 'product_id' => 22, 'variation_id' => 0, 'quantity' => 1, 'subtotal' => '30.00', 'total' => '30.00'],
            ],
        ];
    }
}
