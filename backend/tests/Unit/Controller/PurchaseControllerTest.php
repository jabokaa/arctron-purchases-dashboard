<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Dto\PurchaseInput;
use App\Entity\IndividualCustomer;
use App\Entity\Product;
use App\Entity\Purchase;
use App\Entity\PurchaseItem;
use App\Entity\SalesChannel;
use App\Exception\DuplicatePurchaseException;
use App\Service\PurchaseServiceInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Testes do endpoint POST /api/purchases (PurchaseController).
 *
 * O que é testado: se o controller aceita os parâmetros corretos e recusa os errados,
 * devolvendo o status HTTP e a mensagem de erro esperados para cada campo.
 *
 * Como é testado:
 * - O PurchaseService é trocado por um MOCK: nenhum dado é gravado no banco e o teste
 *   foca só no controller (leitura do JSON, validação dos DTOs e formato da resposta).
 * - É usado o WebTestCase porque a validação acontece no #[MapRequestPayload], antes
 *   do método do controller rodar. Chamar o método direto não passaria pela validação.
 * - Nos casos de erro, o mock garante que o service NUNCA é chamado.
 * - Nos casos de sucesso, o mock garante que o service é chamado UMA vez, com os dados certos.
 */
final class PurchaseControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    private PurchaseServiceInterface&MockObject $purchaseService;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        // Troca o service real pelo mock dentro do container do Symfony
        $this->purchaseService = $this->createMock(PurchaseServiceInterface::class);
        static::getContainer()->set(PurchaseServiceInterface::class, $this->purchaseService);
    }

    // =========================================================================
    // Casos de sucesso
    // =========================================================================

    /**
     * Compra válida de pessoa física (CPF).
     * Espera 201, que o service seja chamado uma vez e que o JSON recebido
     * tenha sido convertido corretamente para o PurchaseInput (snake_case → camelCase).
     */
    public function testCreatesPurchaseForIndividualCustomer(): void
    {
        $this->purchaseService->expects($this->once())
            ->method('create')
            ->with($this->callback(function (PurchaseInput $input): bool {
                self::assertSame('PED-1', $input->referenceCode);
                self::assertSame('2026-11-21T14:30:00-03:00', $input->purchasedAt->format(\DATE_ATOM));
                self::assertSame('Produto enviado para o cliente por SEDEX', $input->notes);
                self::assertSame('João Silva', $input->customer->name);
                self::assertSame('529.982.247-25', $input->customer->cpf);
                self::assertFalse($input->customer->isCompany());
                self::assertCount(1, $input->products);
                self::assertSame('CAM-001', $input->products[0]->externalId);
                self::assertSame(2, $input->products[0]->quantity);
                self::assertSame(5000, $input->products[0]->unitPrice);
                self::assertSame(500, $input->products[0]->discount);

                return true;
            }))
            ->willReturn($this->fakePurchase());

        $response = $this->post(self::validPayload());

        self::assertSame(201, $response['status']);
        self::assertSame('PED-1', $response['body']['reference_code']);
        self::assertSame('INDIVIDUAL', $response['body']['customer']['type']);
        self::assertSame(9500, $response['body']['amount']);
    }

    /**
     * Compra válida de pessoa jurídica (CNPJ + nome fantasia).
     * Espera 201 e que o service receba o cliente identificado como empresa.
     */
    public function testCreatesPurchaseForCompanyCustomer(): void
    {
        $this->purchaseService->expects($this->once())
            ->method('create')
            ->with($this->callback(function (PurchaseInput $input): bool {
                self::assertTrue($input->customer->isCompany());
                self::assertSame('11.222.333/0001-81', $input->customer->cnpj);
                self::assertSame('Loja Exemplo', $input->customer->tradeName);
                self::assertNull($input->customer->cpf);

                return true;
            }))
            ->willReturn($this->fakePurchase());

        $payload = self::validPayload();
        $payload['customer'] = [
            'name' => 'Loja Exemplo LTDA',
            'trade_name' => 'Loja Exemplo',
            'email' => 'contato@loja.com',
            'cnpj' => '11.222.333/0001-81',
        ];

        self::assertSame(201, $this->post($payload)['status']);
    }

    /**
     * CNPJ alfanumérico (formato em vigor desde julho/2026) deve ser aceito.
     * Espera 201.
     */
    public function testAcceptsAlphanumericCnpj(): void
    {
        $this->purchaseService->expects($this->once())->method('create')->willReturn($this->fakePurchase());

        $payload = self::validPayload();
        $payload['customer'] = ['name' => 'Nova Empresa SA', 'email' => 'n@empresa.com', 'cnpj' => '12.ABC.345/01DE-35'];

        self::assertSame(201, $this->post($payload)['status']);
    }

    /**
     * external_id enviado como número (123) deve ser aceito e convertido para texto ("123").
     * Espera 201 e que o service receba "123".
     */
    public function testAcceptsNumericExternalIdAndConvertsToString(): void
    {
        $this->purchaseService->expects($this->once())
            ->method('create')
            ->with($this->callback(static fn (PurchaseInput $input): bool => '123' === $input->products[0]->externalId))
            ->willReturn($this->fakePurchase());

        $payload = self::validPayload();
        $payload['products'][0]['external_id'] = 123;

        self::assertSame(201, $this->post($payload)['status']);
    }

    /**
     * Campos opcionais ausentes (notes e discount do item) não devem impedir a compra.
     * Espera 201, notes nulo e desconto igual a 0.
     */
    public function testAcceptsPayloadWithoutOptionalFields(): void
    {
        $this->purchaseService->expects($this->once())
            ->method('create')
            ->with($this->callback(static fn (PurchaseInput $input): bool => null === $input->notes && 0 === $input->products[0]->discount))
            ->willReturn($this->fakePurchase());

        $payload = self::validPayload();
        unset($payload['notes'], $payload['products'][0]['discount']);

        self::assertSame(201, $this->post($payload)['status']);
    }

    // =========================================================================
    // Erros de validação (422): um campo errado por vez
    // =========================================================================

    /**
     * Para cada caso do provider abaixo, parte de um payload válido, altera UM campo
     * e espera 422 com a mensagem certa no campo certo. O service não pode ser chamado.
     *
     * @param \Closure(array<string, mixed>): array<string, mixed> $breakPayload
     */
    #[DataProvider('invalidFieldProvider')]
    public function testRejectsInvalidField(\Closure $breakPayload, string $expectedField, string $expectedMessage): void
    {
        $this->purchaseService->expects($this->never())->method('create');

        $response = $this->post($breakPayload(self::validPayload()));

        self::assertSame(422, $response['status']);
        self::assertSame('Dados inválidos', $response['body']['title']);
        self::assertContains(
            ['field' => $expectedField, 'message' => $expectedMessage],
            $response['body']['violations'],
            'Violações recebidas: '.json_encode($response['body']['violations'], \JSON_UNESCAPED_UNICODE),
        );
    }

    /**
     * @return iterable<string, array{\Closure, string, string}>
     */
    public static function invalidFieldProvider(): iterable
    {
        // --- Compra ---

        // reference_code é obrigatório
        yield 'reference_code ausente' => [
            static function (array $p): array { unset($p['reference_code']); return $p; },
            'reference_code', 'O código de referência é obrigatório.',
        ];

        // reference_code tem no máximo 100 caracteres
        yield 'reference_code longo demais' => [
            static function (array $p): array { $p['reference_code'] = str_repeat('A', 101); return $p; },
            'reference_code', 'O código de referência deve ter no máximo 100 caracteres.',
        ];

        // purchased_at é obrigatório
        yield 'purchased_at ausente' => [
            static function (array $p): array { unset($p['purchased_at']); return $p; },
            'purchased_at', 'A data da compra é obrigatória.',
        ];

        // purchased_at precisa ser uma data válida
        yield 'purchased_at inválido' => [
            static function (array $p): array { $p['purchased_at'] = 'ontem'; return $p; },
            'purchased_at', 'Data inválida. Use o formato ISO 8601, ex.: 2026-11-21T14:30:00-03:00.',
        ];

        // --- Cliente ---

        // customer é obrigatório
        yield 'customer ausente' => [
            static function (array $p): array { unset($p['customer']); return $p; },
            'customer', 'Os dados do cliente são obrigatórios.',
        ];

        // customer.name é obrigatório
        yield 'customer.name ausente' => [
            static function (array $p): array { unset($p['customer']['name']); return $p; },
            'customer.name', 'O nome do cliente é obrigatório.',
        ];

        // customer.email é obrigatório
        yield 'customer.email ausente' => [
            static function (array $p): array { unset($p['customer']['email']); return $p; },
            'customer.email', 'O e-mail do cliente é obrigatório.',
        ];

        // customer.email precisa ter formato de e-mail
        yield 'customer.email inválido' => [
            static function (array $p): array { $p['customer']['email'] = 'nao-e-email'; return $p; },
            'customer.email', 'O e-mail "nao-e-email" não é válido.',
        ];

        // customer.cpf precisa ter dígitos verificadores válidos
        yield 'customer.cpf inválido' => [
            static function (array $p): array { $p['customer']['cpf'] = '529.982.247-24'; return $p; },
            'customer.cpf', 'O CPF "529.982.247-24" não é válido.',
        ];

        // customer.cpf com todos os dígitos iguais é recusado
        yield 'customer.cpf com dígitos repetidos' => [
            static function (array $p): array { $p['customer']['cpf'] = '111.111.111-11'; return $p; },
            'customer.cpf', 'O CPF "111.111.111-11" não é válido.',
        ];

        // customer.cnpj precisa ter dígitos verificadores válidos
        yield 'customer.cnpj inválido' => [
            static function (array $p): array { unset($p['customer']['cpf']); $p['customer']['cnpj'] = '11.222.333/0001-82'; return $p; },
            'customer.cnpj', 'O CNPJ "11.222.333/0001-82" não é válido.',
        ];

        // O cliente precisa ter um documento: CPF ou CNPJ
        yield 'customer sem CPF e sem CNPJ' => [
            static function (array $p): array { unset($p['customer']['cpf']); return $p; },
            'customer.cpf', 'Informe o CPF (pessoa física) ou o CNPJ (pessoa jurídica) do cliente.',
        ];

        // O cliente não pode ter os dois documentos
        yield 'customer com CPF e CNPJ' => [
            static function (array $p): array { $p['customer']['cnpj'] = '11.222.333/0001-81'; return $p; },
            'customer.cnpj', 'Informe apenas um documento: CPF (pessoa física) ou CNPJ (pessoa jurídica).',
        ];

        // Nome fantasia só existe para pessoa jurídica
        yield 'customer.trade_name com CPF' => [
            static function (array $p): array { $p['customer']['trade_name'] = 'Fantasia'; return $p; },
            'customer.trade_name', 'Nome fantasia só pode ser informado para pessoa jurídica (CNPJ).',
        ];

        // --- Produtos ---

        // products é obrigatório
        yield 'products ausente' => [
            static function (array $p): array { unset($p['products']); return $p; },
            'products', 'A lista de produtos é obrigatória.',
        ];

        // products precisa ter pelo menos 1 item
        yield 'products vazio' => [
            static function (array $p): array { $p['products'] = []; return $p; },
            'products', 'A compra deve ter pelo menos 1 produto.',
        ];

        // products[].external_id é obrigatório
        yield 'products[0].external_id ausente' => [
            static function (array $p): array { unset($p['products'][0]['external_id']); return $p; },
            'products[0].external_id', 'O external_id do produto é obrigatório.',
        ];

        // products[].external_id aceita só texto ou número inteiro
        yield 'products[0].external_id com tipo errado' => [
            static function (array $p): array { $p['products'][0]['external_id'] = true; return $p; },
            'products[0].external_id', 'Tipo inválido: esperado número inteiro ou texto.',
        ];

        // products[].name é obrigatório
        yield 'products[0].name ausente' => [
            static function (array $p): array { unset($p['products'][0]['name']); return $p; },
            'products[0].name', 'O nome do produto é obrigatório.',
        ];

        // products[].quantity é obrigatório
        yield 'products[0].quantity ausente' => [
            static function (array $p): array { unset($p['products'][0]['quantity']); return $p; },
            'products[0].quantity', 'A quantidade é obrigatória.',
        ];

        // products[].quantity precisa ser maior que zero
        yield 'products[0].quantity zero' => [
            static function (array $p): array { $p['products'][0]['quantity'] = 0; return $p; },
            'products[0].quantity', 'A quantidade deve ser maior que zero.',
        ];

        // products[].quantity precisa ser número inteiro (texto é recusado)
        yield 'products[0].quantity com tipo errado' => [
            static function (array $p): array { $p['products'][0]['quantity'] = 'dois'; return $p; },
            'products[0].quantity', 'Tipo inválido: esperado número inteiro.',
        ];

        // products[].unit_price é obrigatório
        yield 'products[0].unit_price ausente' => [
            static function (array $p): array { unset($p['products'][0]['unit_price']); return $p; },
            'products[0].unit_price', 'O valor unitário é obrigatório.',
        ];

        // products[].unit_price não pode ser negativo
        yield 'products[0].unit_price negativo' => [
            static function (array $p): array { $p['products'][0]['unit_price'] = -1; return $p; },
            'products[0].unit_price', 'O valor unitário não pode ser negativo.',
        ];

        // products[].discount não pode ser negativo
        yield 'products[0].discount negativo' => [
            static function (array $p): array { $p['products'][0]['discount'] = -1; return $p; },
            'products[0].discount', 'O desconto do item não pode ser negativo.',
        ];

        // A validação também vale para os itens seguintes da lista, não só para o primeiro
        yield 'products[1].quantity zero (segundo item)' => [
            static function (array $p): array {
                $p['products'][] = ['external_id' => 'CAL-001', 'name' => 'Calça', 'quantity' => 0, 'unit_price' => 100];

                return $p;
            },
            'products[1].quantity', 'A quantidade deve ser maior que zero.',
        ];
    }

    /**
     * Valores em centavos com casas decimais (ex.: 50.5) devem ser recusados com 422,
     * porque quantity, unit_price e discount são números inteiros.
     *
     * Por que este teste ignora deprecations: ao recusar o valor, o serializer do Symfony ainda
     * monta o objeto com os dados recebidos, e o PHP avisa que 50.5 vira 50 nessa conversão.
     * Esse objeto é descartado (a requisição já foi recusada e o service não é chamado),
     * então o aviso não afeta o resultado. O teste garante exatamente isso.
     *
     * @param \Closure(array<string, mixed>): array<string, mixed> $breakPayload
     */
    #[DataProvider('decimalValueProvider')]
    #[IgnoreDeprecations]
    public function testRejectsDecimalValues(\Closure $breakPayload, string $expectedField): void
    {
        $this->purchaseService->expects($this->never())->method('create');

        $response = $this->post($breakPayload(self::validPayload()));

        self::assertSame(422, $response['status']);
        self::assertContains(
            ['field' => $expectedField, 'message' => 'Tipo inválido: esperado número inteiro.'],
            $response['body']['violations'],
        );
    }

    /**
     * @return iterable<string, array{\Closure, string}>
     */
    public static function decimalValueProvider(): iterable
    {
        // quantity: 1.5 unidade não existe
        yield 'products[0].quantity com decimais' => [
            static function (array $p): array { $p['products'][0]['quantity'] = 1.5; return $p; },
            'products[0].quantity',
        ];

        // unit_price é em centavos: 50.5 centavos não existe
        yield 'products[0].unit_price com decimais' => [
            static function (array $p): array { $p['products'][0]['unit_price'] = 50.5; return $p; },
            'products[0].unit_price',
        ];

        // discount é em centavos: 5.5 centavos não existe
        yield 'products[0].discount com decimais' => [
            static function (array $p): array { $p['products'][0]['discount'] = 5.5; return $p; },
            'products[0].discount',
        ];
    }

    // =========================================================================
    // Erros de formato da requisição
    // =========================================================================

    /**
     * Corpo vazio.
     * Espera 400 com a mensagem de corpo obrigatório.
     */
    public function testRejectsEmptyBody(): void
    {
        $this->purchaseService->expects($this->never())->method('create');

        $response = $this->postRaw('');

        self::assertSame(400, $response['status']);
        self::assertSame('O corpo da requisição é obrigatório.', $response['body']['detail']);
    }

    /**
     * JSON malformado (faltando fechar o objeto).
     * Espera 400 com a mensagem de JSON inválido.
     */
    public function testRejectsMalformedJson(): void
    {
        $this->purchaseService->expects($this->never())->method('create');

        $response = $this->postRaw('{"reference_code": "PED-1",');

        self::assertSame(400, $response['status']);
        self::assertSame('O corpo da requisição não é um JSON válido.', $response['body']['detail']);
    }

    /**
     * Corpo enviado como formulário em vez de JSON.
     * Espera 415 (formato não suportado).
     */
    public function testRejectsNonJsonContentType(): void
    {
        $this->purchaseService->expects($this->never())->method('create');

        $response = $this->postRaw('reference_code=PED-1', 'application/x-www-form-urlencoded');

        self::assertSame(415, $response['status']);
        self::assertSame('Formato não suportado', $response['body']['title']);
    }

    /**
     * Método HTTP errado (GET em vez de POST).
     * Espera 405.
     */
    public function testRejectsGetMethod(): void
    {
        $this->purchaseService->expects($this->never())->method('create');

        $this->client->request('GET', '/api/purchases');

        self::assertSame(405, $this->client->getResponse()->getStatusCode());
        self::assertSame('Método HTTP não permitido para esta rota.', $this->decodeResponse()['detail']);
    }

    // =========================================================================
    // Erros vindos do service
    // =========================================================================

    /**
     * O service informa que o código de referência já existe (DuplicatePurchaseException).
     * Espera 409 com a mensagem da exceção.
     */
    public function testReturnsConflictWhenPurchaseIsDuplicated(): void
    {
        $this->purchaseService->expects($this->once())
            ->method('create')
            ->willThrowException(new DuplicatePurchaseException('PED-1'));

        $response = $this->post(self::validPayload());

        self::assertSame(409, $response['status']);
        self::assertSame('Conflito', $response['body']['title']);
        self::assertSame('Já existe uma compra com o código de referência "PED-1".', $response['body']['detail']);
    }

    /**
     * O service falha com um erro inesperado.
     * Espera 500 com mensagem genérica, sem expor a mensagem interna no "detail".
     */
    public function testReturnsGenericErrorWhenServiceFails(): void
    {
        $this->purchaseService->expects($this->once())
            ->method('create')
            ->willThrowException(new \RuntimeException('SQLSTATE[08006] conexão recusada'));

        $response = $this->post(self::validPayload());

        self::assertSame(500, $response['status']);
        self::assertSame('Ocorreu um erro inesperado. Tente novamente mais tarde.', $response['body']['detail']);
        self::assertStringNotContainsString('SQLSTATE', $response['body']['detail']);
    }

    // =========================================================================
    // Auxiliares
    // =========================================================================

    /**
     * Payload válido de referência: cada teste de erro altera só um campo dele.
     *
     * @return array<string, mixed>
     */
    private static function validPayload(): array
    {
        return [
            'reference_code' => 'PED-1',
            'purchased_at' => '2026-11-21T14:30:00-03:00',
            'notes' => 'Produto enviado para o cliente por SEDEX',
            'customer' => [
                'name' => 'João Silva',
                'email' => 'joao@email.com',
                'cpf' => '529.982.247-25',
            ],
            'products' => [
                ['external_id' => 'CAM-001', 'name' => 'Camiseta', 'quantity' => 2, 'unit_price' => 5000, 'discount' => 500],
            ],
        ];
    }

    /**
     * Compra que o mock do service devolve nos casos de sucesso.
     * Não é gravada no banco; os timestamps são preenchidos chamando o callback de criação.
     */
    private function fakePurchase(): Purchase
    {
        $customer = (new IndividualCustomer())->setFullName('João Silva')->setCpf('52998224725')->setEmail('joao@email.com');
        $product = (new Product())->setName('Camiseta');

        $purchase = (new Purchase())
            ->setReferenceCode('PED-1')
            ->setCustomer($customer)
            ->setSalesChannel((new SalesChannel())->setCode(SalesChannel::API)->setName('API'))
            ->setAmount(9500)
            ->setDiscount(500)
            ->setPurchasedAt(new \DateTimeImmutable('2026-11-21T17:30:00+00:00'))
            ->addItem(
                (new PurchaseItem())
                    ->setProduct($product)
                    ->setQuantity(2)
                    ->setUnitPrice(5000)
                    ->setDiscount(500)
                    ->setTotalPrice(9500),
            );

        $purchase->onPrePersist();

        return $purchase;
    }

    /**
     * Envia o payload como JSON para o endpoint.
     *
     * @param array<string, mixed> $payload
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private function post(array $payload): array
    {
        return $this->postRaw(json_encode($payload, \JSON_THROW_ON_ERROR));
    }

    /**
     * Envia um corpo qualquer (inclusive inválido) para o endpoint.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private function postRaw(string $content, string $contentType = 'application/json'): array
    {
        $this->client->request('POST', '/api/purchases', server: ['CONTENT_TYPE' => $contentType], content: $content);

        return [
            'status' => $this->client->getResponse()->getStatusCode(),
            'body' => $this->decodeResponse(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResponse(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }
}
