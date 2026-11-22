<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Dto\PurchaseFilterInput;
use App\Dto\PurchaseListOutput;
use App\Dto\PurchaseOutput;
use App\Entity\IndividualCustomer;
use App\Entity\Product;
use App\Entity\Purchase;
use App\Entity\PurchaseItem;
use App\Entity\SalesChannel;
use App\Service\PurchaseSearchServiceInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Testes do endpoint GET /api/purchases (consulta de compras, PurchaseController::list).
 *
 * O que é testado: se o controller aceita os filtros corretos e recusa os errados,
 * devolvendo o status HTTP e a mensagem de erro esperados para cada filtro,
 * e se a resposta do service sai no formato JSON esperado.
 *
 * Como é testado:
 * - O PurchaseSearchService é trocado por um MOCK: nenhum dado é lido do banco e o teste
 *   foca só no controller (leitura da query string, validação do PurchaseFilterInput e resposta).
 * - É usado o WebTestCase porque a validação acontece no #[MapQueryString], antes
 *   do método do controller rodar. Chamar o método direto não passaria pela validação.
 * - Nos casos de erro, o mock garante que o service NUNCA é chamado.
 * - Nos casos de sucesso, o mock garante que o service é chamado UMA vez, com os filtros certos.
 */
final class PurchaseSearchControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    private PurchaseSearchServiceInterface&MockObject $searchService;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        // Troca o service real pelo mock dentro do container do Symfony
        $this->searchService = $this->createMock(PurchaseSearchServiceInterface::class);
        static::getContainer()->set(PurchaseSearchServiceInterface::class, $this->searchService);
    }

    // =========================================================================
    // Casos de sucesso
    // =========================================================================

    /**
     * Consulta sem nenhum parâmetro.
     * Espera 200 e que o service receba os filtros padrão: nenhum filtro, página 1 e 20 por página.
     */
    public function testListsWithoutFiltersUsingDefaults(): void
    {
        $this->searchService->expects($this->once())
            ->method('search')
            ->with($this->callback(function (PurchaseFilterInput $filter): bool {
                self::assertNull($filter->referenceCode);
                self::assertNull($filter->customerName);
                self::assertNull($filter->customerEmail);
                self::assertNull($filter->customerDocument);
                self::assertNull($filter->dateFrom);
                self::assertNull($filter->dateTo);
                self::assertNull($filter->amountMin);
                self::assertNull($filter->amountMax);
                self::assertSame(1, $filter->page);
                self::assertSame(20, $filter->perPage);

                return true;
            }))
            ->willReturn(self::emptyResult());

        self::assertSame(200, $this->get('')['status']);
    }

    /**
     * Consulta com todos os filtros preenchidos.
     * Espera 200 e que cada parâmetro da query string (snake_case) chegue no filtro certo (camelCase),
     * com números convertidos de texto para inteiro.
     */
    public function testMapsAllQueryParametersToFilter(): void
    {
        $this->searchService->expects($this->once())
            ->method('search')
            ->with($this->callback(function (PurchaseFilterInput $filter): bool {
                self::assertSame('PED', $filter->referenceCode);
                self::assertSame('João', $filter->customerName);
                self::assertSame('email.com', $filter->customerEmail);
                self::assertSame('529.982.247-25', $filter->customerDocument);
                self::assertSame('2026-11-01', $filter->dateFrom);
                self::assertSame('2026-11-30', $filter->dateTo);
                self::assertSame(1000, $filter->amountMin);
                self::assertSame(50000, $filter->amountMax);
                self::assertSame(2, $filter->page);
                self::assertSame(10, $filter->perPage);

                return true;
            }))
            ->willReturn(self::emptyResult());

        $response = $this->get('?'.http_build_query([
            'reference_code' => 'PED',
            'customer_name' => 'João',
            'customer_email' => 'email.com',
            'customer_document' => '529.982.247-25',
            'date_from' => '2026-11-01',
            'date_to' => '2026-11-30',
            'amount_min' => '1000',
            'amount_max' => '50000',
            'page' => '2',
            'per_page' => '10',
        ]));

        self::assertSame(200, $response['status']);
    }

    /**
     * Datas com hora e fuso horário (ISO 8601) também são aceitas.
     * Espera 200 e que o service receba os valores como vieram.
     */
    public function testAcceptsDateTimeFilters(): void
    {
        $this->searchService->expects($this->once())
            ->method('search')
            ->with($this->callback(static fn (PurchaseFilterInput $filter): bool => '2026-11-15T20:00:00-03:00' === $filter->dateFrom
                && '2026-11-15T23:59:59-03:00' === $filter->dateTo))
            ->willReturn(self::emptyResult());

        $response = $this->get('?'.http_build_query(['date_from' => '2026-11-15T20:00:00-03:00', 'date_to' => '2026-11-15T23:59:59-03:00']));

        self::assertSame(200, $response['status']);
    }

    /**
     * Resposta do service com uma compra.
     * Espera 200 e o JSON no formato { data: [...], meta: {...} }, com as chaves em snake_case.
     */
    public function testReturnsServiceResultAsJson(): void
    {
        $this->searchService->expects($this->once())->method('search')->willReturn(new PurchaseListOutput(
            data: [PurchaseOutput::fromEntity(self::fakePurchase())],
            meta: ['page' => 1, 'per_page' => 20, 'total' => 1, 'total_pages' => 1],
        ));

        $response = $this->get('');

        self::assertSame(200, $response['status']);
        self::assertSame(['page' => 1, 'per_page' => 20, 'total' => 1, 'total_pages' => 1], $response['body']['meta']);
        self::assertCount(1, $response['body']['data']);

        $purchase = $response['body']['data'][0];
        self::assertSame('PED-1', $purchase['reference_code']);
        self::assertSame(9500, $purchase['amount']);
        self::assertSame('INDIVIDUAL', $purchase['customer']['type']);
        self::assertSame('52998224725', $purchase['customer']['document']);
        self::assertSame(9500, $purchase['items'][0]['total_price']);
    }

    // =========================================================================
    // Filtros inválidos (422): um por vez
    // =========================================================================

    /**
     * Para cada caso do provider abaixo, envia UM filtro inválido e espera 422
     * com a mensagem certa no campo certo. O service não pode ser chamado.
     *
     * @param array<string, string> $query
     */
    #[DataProvider('invalidFilterProvider')]
    public function testRejectsInvalidFilter(array $query, string $expectedField, string $expectedMessage): void
    {
        $this->searchService->expects($this->never())->method('search');

        $response = $this->get('?'.http_build_query($query));

        self::assertSame(422, $response['status']);
        self::assertSame('Dados inválidos', $response['body']['title']);
        self::assertContains(
            ['field' => $expectedField, 'message' => $expectedMessage],
            $response['body']['violations'],
            'Violações recebidas: '.json_encode($response['body']['violations'], \JSON_UNESCAPED_UNICODE),
        );
    }

    /**
     * @return iterable<string, array{array<string, string>, string, string}>
     */
    public static function invalidFilterProvider(): iterable
    {
        $invalidDate = 'Data inválida. Use AAAA-MM-DD (ex.: 2026-11-21) ou ISO 8601 (ex.: 2026-11-21T14:30:00-03:00).';

        // --- Textos ---

        // reference_code tem no máximo 100 caracteres
        yield 'reference_code longo demais' => [
            ['reference_code' => str_repeat('A', 101)],
            'reference_code', 'O código de referência deve ter no máximo 100 caracteres.',
        ];

        // customer_name tem no máximo 255 caracteres
        yield 'customer_name longo demais' => [
            ['customer_name' => str_repeat('A', 256)],
            'customer_name', 'O nome do cliente deve ter no máximo 255 caracteres.',
        ];

        // customer_email tem no máximo 255 caracteres
        yield 'customer_email longo demais' => [
            ['customer_email' => str_repeat('a', 256)],
            'customer_email', 'O e-mail do cliente deve ter no máximo 255 caracteres.',
        ];

        // customer_document tem no máximo 20 caracteres (CNPJ com máscara tem 18)
        yield 'customer_document longo demais' => [
            ['customer_document' => str_repeat('1', 21)],
            'customer_document', 'O documento do cliente deve ter no máximo 20 caracteres.',
        ];

        // --- Datas ---

        // date_from precisa ser uma data no formato aceito
        yield 'date_from em texto' => [
            ['date_from' => 'ontem'],
            'date_from', $invalidDate,
        ];

        // date_from no formato brasileiro não é aceito
        yield 'date_from no formato DD/MM/AAAA' => [
            ['date_from' => '01/11/2026'],
            'date_from', $invalidDate,
        ];

        // date_from com formato certo mas que não existe (30 de fevereiro)
        yield 'date_from inexistente' => [
            ['date_from' => '2026-02-30'],
            'date_from', $invalidDate,
        ];

        // Dia inexistente também é recusado quando vem com hora
        yield 'date_from inexistente com hora' => [
            ['date_from' => '2026-02-30T10:00:00-03:00'],
            'date_from', $invalidDate,
        ];

        // Mês 13 não existe
        yield 'date_from com mês 13' => [
            ['date_from' => '2026-13-01'],
            'date_from', $invalidDate,
        ];

        // Hora 25 não existe
        yield 'date_to com hora 25' => [
            ['date_to' => '2026-11-21T25:00'],
            'date_to', $invalidDate,
        ];

        // date_to precisa ser uma data no formato aceito
        yield 'date_to em texto' => [
            ['date_to' => 'amanhã'],
            'date_to', $invalidDate,
        ];

        // A data inicial não pode ser depois da final
        yield 'date_from maior que date_to' => [
            ['date_from' => '2026-12-01', 'date_to' => '2026-11-01'],
            'date_from', 'A data inicial não pode ser maior que a data final.',
        ];

        // --- Valores ---

        // amount_min precisa ser número inteiro
        yield 'amount_min em texto' => [
            ['amount_min' => 'abc'],
            'amount_min', 'Tipo inválido: esperado número inteiro.',
        ];

        // amount_min não pode ser negativo
        yield 'amount_min negativo' => [
            ['amount_min' => '-1'],
            'amount_min', 'O valor mínimo não pode ser negativo.',
        ];

        // amount_max precisa ser número inteiro
        yield 'amount_max em texto' => [
            ['amount_max' => 'abc'],
            'amount_max', 'Tipo inválido: esperado número inteiro.',
        ];

        // amount_max não pode ser negativo
        yield 'amount_max negativo' => [
            ['amount_max' => '-1'],
            'amount_max', 'O valor máximo não pode ser negativo.',
        ];

        // O valor mínimo não pode ser maior que o máximo
        yield 'amount_min maior que amount_max' => [
            ['amount_min' => '500', 'amount_max' => '100'],
            'amount_min', 'O valor mínimo não pode ser maior que o valor máximo.',
        ];

        // --- Paginação ---

        // page começa em 1
        yield 'page zero' => [
            ['page' => '0'],
            'page', 'A página deve ser maior que zero.',
        ];

        // page precisa ser número inteiro
        yield 'page em texto' => [
            ['page' => 'primeira'],
            'page', 'Tipo inválido: esperado número inteiro.',
        ];

        // per_page tem que ser pelo menos 1
        yield 'per_page zero' => [
            ['per_page' => '0'],
            'per_page', 'A quantidade por página deve estar entre 1 e 100.',
        ];

        // per_page tem no máximo 100
        yield 'per_page acima do máximo' => [
            ['per_page' => '101'],
            'per_page', 'A quantidade por página deve estar entre 1 e 100.',
        ];
    }

    // =========================================================================
    // Auxiliares
    // =========================================================================

    private static function emptyResult(): PurchaseListOutput
    {
        return new PurchaseListOutput(data: [], meta: ['page' => 1, 'per_page' => 20, 'total' => 0, 'total_pages' => 0]);
    }

    /**
     * Compra usada para montar a resposta do mock. Não é gravada no banco.
     */
    private static function fakePurchase(): Purchase
    {
        $purchase = (new Purchase())
            ->setReferenceCode('PED-1')
            ->setCustomer((new IndividualCustomer())->setFullName('João Silva')->setCpf('52998224725')->setEmail('joao@email.com'))
            ->setSalesChannel((new SalesChannel())->setCode(SalesChannel::API)->setName('API'))
            ->setAmount(9500)
            ->setDiscount(500)
            ->setPurchasedAt(new \DateTimeImmutable('2026-11-21T17:30:00+00:00'))
            ->addItem(
                (new PurchaseItem())
                    ->setProduct((new Product())->setName('Camiseta'))
                    ->setQuantity(2)
                    ->setUnitPrice(5000)
                    ->setDiscount(500)
                    ->setTotalPrice(9500),
            );

        $purchase->onPrePersist();

        return $purchase;
    }

    /**
     * Faz a consulta no endpoint.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private function get(string $queryString): array
    {
        $this->client->request('GET', '/api/purchases'.$queryString);

        return [
            'status' => $this->client->getResponse()->getStatusCode(),
            'body' => json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR),
        ];
    }
}
