<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Dto\PurchaseFilterInput;
use App\Entity\CompanyCustomer;
use App\Entity\IndividualCustomer;
use App\Entity\Purchase;
use App\Entity\SalesChannel;
use App\Exception\PurchaseNotFoundException;
use App\Repository\PurchaseRepository;
use App\Service\PurchaseSearchService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Testes unitários do PurchaseSearchService (consulta de compras).
 *
 * O que é testado:
 * - search(): se o service repassa os filtros ao repositório e monta a resposta paginada
 *   corretamente: cada compra no formato da API e os dados de paginação (meta);
 * - find(): se a compra encontrada volta no formato da API e se a compra inexistente vira PurchaseNotFoundException.
 *
 * Como é testado:
 * - É um teste unitário puro (TestCase): o Symfony não é iniciado e o banco não é acessado.
 * - O PurchaseRepository é um MOCK (quando o teste confere a chamada) ou STUB (quando só devolve dados).
 */
final class PurchaseSearchServiceTest extends TestCase
{
    // =========================================================================
    // Listagem com filtros (search)
    // =========================================================================

    /**
     * O filtro recebido deve ser passado ao repositório sem alteração.
     * Espera uma única chamada a search() com o mesmo objeto de filtro.
     */
    public function testPassesFilterToRepository(): void
    {
        $filter = new PurchaseFilterInput(referenceCode: 'PED', page: 2, perPage: 10);

        $repository = $this->createMock(PurchaseRepository::class);
        $repository->expects($this->once())
            ->method('search')
            ->with($this->identicalTo($filter))
            ->willReturn(['items' => [], 'total' => 0]);

        (new PurchaseSearchService($repository))->search($filter);
    }

    /**
     * O repositório devolve duas compras (pessoa física e jurídica).
     * Espera cada compra convertida para o formato da API, na mesma ordem.
     */
    public function testConvertsEachPurchaseToOutput(): void
    {
        $repository = $this->createStub(PurchaseRepository::class);
        $repository->method('search')->willReturn([
            'items' => [
                self::purchase('PED-1', (new IndividualCustomer())->setFullName('João Silva')->setCpf('52998224725')),
                self::purchase('PED-2', (new CompanyCustomer())->setLegalName('Loja LTDA')->setTradeName('Loja')->setCnpj('11222333000181')),
            ],
            'total' => 2,
        ]);

        $result = (new PurchaseSearchService($repository))->search(new PurchaseFilterInput());

        self::assertCount(2, $result->data);
        self::assertSame('PED-1', $result->data[0]->referenceCode);
        self::assertSame('INDIVIDUAL', $result->data[0]->customer['type']);
        self::assertSame('PED-2', $result->data[1]->referenceCode);
        self::assertSame('COMPANY', $result->data[1]->customer['type']);
        self::assertSame('Loja', $result->data[1]->customer['trade_name']);
    }

    /**
     * Para cada caso do provider, confere os dados de paginação (meta) montados a partir
     * da página pedida, do tamanho da página e do total de compras encontradas.
     */
    #[DataProvider('paginationProvider')]
    public function testBuildsPaginationMeta(int $page, int $perPage, int $total, int $expectedTotalPages): void
    {
        $repository = $this->createStub(PurchaseRepository::class);
        $repository->method('search')->willReturn(['items' => [], 'total' => $total]);

        $result = (new PurchaseSearchService($repository))->search(new PurchaseFilterInput(page: $page, perPage: $perPage));

        self::assertSame(
            ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'total_pages' => $expectedTotalPages],
            $result->meta,
        );
    }

    /**
     * @return iterable<string, array{int, int, int, int}>
     */
    public static function paginationProvider(): iterable
    {
        // Nenhuma compra encontrada: zero páginas
        yield 'nenhum resultado' => [1, 20, 0, 0];

        // Menos compras que o tamanho da página: uma página
        yield 'cabe em uma página' => [1, 20, 5, 1];

        // Total exatamente igual a 2 páginas cheias
        yield 'páginas cheias' => [1, 20, 40, 2];

        // Sobra uma compra: arredonda para cima (41 / 20 = 3 páginas)
        yield 'última página incompleta' => [3, 20, 41, 3];

        // Página pedida além do total: devolve a página pedida, sem erro
        yield 'página além do total' => [5, 10, 12, 2];
    }

    // =========================================================================
    // Detalhe de uma compra (find)
    // =========================================================================

    /**
     * Compra encontrada pelo id.
     * Espera que o repositório seja consultado com o id e que a compra volte no formato da API.
     */
    public function testFindReturnsPurchaseDetails(): void
    {
        $repository = $this->createMock(PurchaseRepository::class);
        $repository->expects($this->once())
            ->method('findWithDetails')
            ->with(42)
            ->willReturn(self::purchase('PED-42', (new IndividualCustomer())->setFullName('João Silva')->setCpf('52998224725')));

        $result = (new PurchaseSearchService($repository))->find(42);

        self::assertSame('PED-42', $result->referenceCode);
        self::assertSame('CPF', $result->customer['document_type']);
        self::assertSame('52998224725', $result->customer['document']);
    }

    /**
     * Nenhuma compra com o id informado.
     * Espera PurchaseNotFoundException com o id e a mensagem para o cliente da API.
     */
    public function testFindThrowsWhenPurchaseDoesNotExist(): void
    {
        $repository = $this->createStub(PurchaseRepository::class);
        $repository->method('findWithDetails')->willReturn(null);

        try {
            (new PurchaseSearchService($repository))->find(999);
            self::fail('Era esperada uma PurchaseNotFoundException.');
        } catch (PurchaseNotFoundException $e) {
            self::assertSame(999, $e->purchaseId);
            self::assertSame('Compra 999 não encontrada.', $e->getMessage());
        }
    }

    private static function purchase(string $referenceCode, IndividualCustomer|CompanyCustomer $customer): Purchase
    {
        $purchase = (new Purchase())
            ->setReferenceCode($referenceCode)
            ->setCustomer($customer->setEmail('cliente@email.com'))
            ->setSalesChannel((new SalesChannel())->setCode(SalesChannel::API)->setName('API'))
            ->setAmount(1000)
            ->setDiscount(0)
            ->setPurchasedAt(new \DateTimeImmutable('2026-11-21T12:00:00+00:00'));

        $purchase->onPrePersist();

        return $purchase;
    }
}
