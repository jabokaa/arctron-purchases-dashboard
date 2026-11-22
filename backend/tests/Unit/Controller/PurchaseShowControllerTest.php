<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Dto\PurchaseOutput;
use App\Entity\CompanyCustomer;
use App\Entity\Product;
use App\Entity\Purchase;
use App\Entity\PurchaseItem;
use App\Entity\SalesChannel;
use App\Exception\PurchaseNotFoundException;
use App\Service\PurchaseSearchServiceInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Testes do endpoint GET /api/purchases/{id} (detalhe de uma compra, PurchaseController::show).
 *
 * O que é testado: se o controller aceita ids válidos e recusa os inválidos,
 * devolvendo 200 com a compra completa ou 404 com a mensagem certa.
 *
 * Como é testado:
 * - O PurchaseSearchService é trocado por um MOCK: nenhum dado é lido do banco e o teste
 *   foca só no controller (rota, conversão do id e formato da resposta).
 * - Nos ids inválidos (texto, negativo), o mock garante que o service NUNCA é chamado:
 *   a própria rota recusa a requisição.
 */
final class PurchaseShowControllerTest extends WebTestCase
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

    /**
     * Compra existente.
     * Espera 200, que o service receba o id convertido para inteiro e que a resposta traga
     * a compra completa: canal, observações, cliente com documento e itens com produto e valores.
     */
    public function testShowsPurchaseDetails(): void
    {
        $this->searchService->expects($this->once())
            ->method('find')
            ->with(42)
            ->willReturn(PurchaseOutput::fromEntity(self::fakePurchase()));

        $response = $this->get('/api/purchases/42');

        self::assertSame(200, $response['status']);

        $purchase = $response['body'];
        self::assertSame('PED-1', $purchase['reference_code']);
        self::assertSame('API', $purchase['sales_channel']);
        self::assertSame('Produto enviado para o cliente por SEDEX', $purchase['notes']);
        self::assertSame(25510, $purchase['amount']);
        self::assertSame(500, $purchase['discount']);

        self::assertSame('COMPANY', $purchase['customer']['type']);
        self::assertSame('Loja Exemplo LTDA', $purchase['customer']['name']);
        self::assertSame('Loja Bacana', $purchase['customer']['trade_name']);
        self::assertSame('CNPJ', $purchase['customer']['document_type']);
        self::assertSame('11222333000181', $purchase['customer']['document']);

        self::assertCount(2, $purchase['items']);
        self::assertSame(
            ['name' => 'Camiseta', 'quantity' => 2, 'unit_price' => 5000, 'discount' => 500, 'total_price' => 9500],
            array_diff_key($purchase['items'][0], ['product_id' => true]),
        );
    }

    /**
     * Compra inexistente: o service lança PurchaseNotFoundException.
     * Espera 404 com a mensagem da exceção.
     */
    public function testReturnsNotFoundWhenPurchaseDoesNotExist(): void
    {
        $this->searchService->expects($this->once())
            ->method('find')
            ->with(999)
            ->willThrowException(new PurchaseNotFoundException(999));

        $response = $this->get('/api/purchases/999');

        self::assertSame(404, $response['status']);
        self::assertSame('Não encontrado', $response['body']['title']);
        self::assertSame('Compra 999 não encontrada.', $response['body']['detail']);
    }

    /**
     * Para cada caso do provider, o id é inválido para a rota.
     * Espera 404 com a mensagem certa e que o service NUNCA seja chamado.
     */
    #[DataProvider('invalidIdProvider')]
    public function testRejectsInvalidId(string $id, string $expectedDetail): void
    {
        $this->searchService->expects($this->never())->method('find');

        $response = $this->get('/api/purchases/'.$id);

        self::assertSame(404, $response['status']);
        self::assertSame($expectedDetail, $response['body']['detail']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidIdProvider(): iterable
    {
        // O id precisa ser número: texto não bate com a rota
        yield 'id em texto' => ['abc', 'Rota não encontrada.'];

        // Sinal de menos não é aceito pela rota (só dígitos)
        yield 'id negativo' => ['-1', 'Rota não encontrada.'];

        // Número com casas decimais não bate com a rota
        yield 'id com decimais' => ['1.5', 'Rota não encontrada.'];

        // Número maior que o maior inteiro do PHP: a rota aceita os dígitos, mas não dá para converter
        yield 'id maior que o maior inteiro' => ['99999999999999999999', 'O valor informado na URL é inválido.'];
    }

    // =========================================================================
    // Auxiliares
    // =========================================================================

    /**
     * Compra usada para montar a resposta do mock. Não é gravada no banco.
     */
    private static function fakePurchase(): Purchase
    {
        $customer = (new CompanyCustomer())
            ->setLegalName('Loja Exemplo LTDA')
            ->setTradeName('Loja Bacana')
            ->setCnpj('11222333000181')
            ->setEmail('contato@loja.com');

        $purchase = (new Purchase())
            ->setReferenceCode('PED-1')
            ->setCustomer($customer)
            ->setSalesChannel((new SalesChannel())->setCode(SalesChannel::API)->setName('API'))
            ->setAmount(25510)
            ->setDiscount(500)
            ->setPurchasedAt(new \DateTimeImmutable('2026-11-21T17:30:00+00:00'))
            ->setNotes('Produto enviado para o cliente por SEDEX')
            ->addItem((new PurchaseItem())->setProduct((new Product())->setName('Camiseta'))->setQuantity(2)->setUnitPrice(5000)->setDiscount(500)->setTotalPrice(9500))
            ->addItem((new PurchaseItem())->setProduct((new Product())->setName('Calça'))->setQuantity(1)->setUnitPrice(16010)->setTotalPrice(16010));

        $purchase->onPrePersist();

        return $purchase;
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function get(string $url): array
    {
        $this->client->request('GET', $url);

        return [
            'status' => $this->client->getResponse()->getStatusCode(),
            'body' => json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR),
        ];
    }
}
