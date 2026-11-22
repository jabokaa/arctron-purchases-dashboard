<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\CompanyCustomer;
use App\Entity\Customer;
use App\Entity\IndividualCustomer;
use App\Entity\Purchase;
use App\Entity\SalesChannel;
use App\Enum\CustomerReportSort;
use App\Enum\SortOrder;
use App\Repository\CustomerRepository;
use App\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Testes do CustomerRepository::purchaseRanking() (ranking de clientes pelas compras).
 *
 * O que é testado: se o banco devolve, por cliente, a quantidade de compras, o valor total e o
 * valor médio (arredondado para o centavo), ordenados pelo critério e direção pedidos, com desempate
 * pelo id, paginação certa e só clientes que têm compras.
 *
 * Por que fica na pasta de integração: agrupamento, somas, médias e ordenação são feitos pelo banco
 * (SQL com GROUP BY, SUM, AVG e ORDER BY); só o banco de teste real (app_test) prova que a consulta está certa.
 *
 * Como é testado: antes de cada teste o banco é esvaziado (IntegrationTestCase) e os clientes e
 * compras abaixo são gravados direto pelo Doctrine (sem passar pela API):
 *
 *   | Cliente                  | Tipo     | Compras (valor)       | Qtd | Total | Média |
 *   |--------------------------|----------|-----------------------|-----|-------|-------|
 *   | João Silva               | física   | 1000, 2000, 3000      | 3   | 6000  | 2000  |
 *   | Maria Souza              | física   | 10000                 | 1   | 10000 | 10000 |
 *   | Loja LTDA ("Loja Bacana")| jurídica | 4000, 4001            | 2   | 8001  | 4001  | ← 4000,5 arredonda para cima
 *   | Pedro Lima               | física   | 1000                  | 1   | 1000  | 1000  |
 *   | Sem Compras              | física   | —                     | 0   | —     | —     | ← não entra no ranking
 */
final class CustomerRankingTest extends IntegrationTestCase
{
    private CustomerRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = static::getContainer()->get(CustomerRepository::class);
        $this->createCustomersAndPurchases();
    }

    /**
     * Para cada caso do provider, ordena pelo critério e direção pedidos e espera os clientes nessa ordem.
     *
     * @param list<string> $expected nomes na ordem esperada
     */
    #[DataProvider('sortProvider')]
    public function testSortsRanking(CustomerReportSort $sort, SortOrder $order, array $expected): void
    {
        $result = $this->repository->purchaseRanking($sort, $order, page: 1, limit: 20);

        self::assertSame($expected, self::names($result['items']));
    }

    /**
     * @return iterable<string, array{CustomerReportSort, SortOrder, list<string>}>
     */
    public static function sortProvider(): iterable
    {
        // Maior valor total primeiro (o padrão do ranking)
        yield 'total desc' => [CustomerReportSort::Total, SortOrder::Desc, ['Maria Souza', 'Loja LTDA', 'João Silva', 'Pedro Lima']];

        // Menor valor total primeiro
        yield 'total asc' => [CustomerReportSort::Total, SortOrder::Asc, ['Pedro Lima', 'João Silva', 'Loja LTDA', 'Maria Souza']];

        // Mais compras primeiro; Maria e Pedro empatam com 1 compra e ficam na ordem do id (Maria foi criada antes)
        yield 'count desc com empate' => [CustomerReportSort::Count, SortOrder::Desc, ['João Silva', 'Loja LTDA', 'Maria Souza', 'Pedro Lima']];

        // Maior valor médio primeiro
        yield 'average desc' => [CustomerReportSort::Average, SortOrder::Desc, ['Maria Souza', 'Loja LTDA', 'João Silva', 'Pedro Lima']];

        // Menor valor médio primeiro
        yield 'average asc' => [CustomerReportSort::Average, SortOrder::Asc, ['Pedro Lima', 'João Silva', 'Loja LTDA', 'Maria Souza']];
    }

    /**
     * Quantidade, total e média calculados pelo banco para cada cliente,
     * com a média arredondada para o centavo mais próximo (8001 / 2 = 4000,5 → 4001).
     */
    public function testCalculatesCountTotalAndAverage(): void
    {
        $items = $this->repository->purchaseRanking(CustomerReportSort::Total, SortOrder::Desc, page: 1, limit: 20)['items'];

        self::assertSame([
            ['Maria Souza', 1, 10000, 10000],
            ['Loja LTDA', 2, 8001, 4001],
            ['João Silva', 3, 6000, 2000],
            ['Pedro Lima', 1, 1000, 1000],
        ], array_map(static fn (array $row): array => [$row['fullName'] ?? $row['legalName'], $row['purchasesCount'], $row['total'], $row['average']], $items));
    }

    /**
     * Cliente sem compras não aparece no ranking nem conta no total.
     */
    public function testIgnoresCustomersWithoutPurchases(): void
    {
        $result = $this->repository->purchaseRanking(CustomerReportSort::Total, SortOrder::Desc, page: 1, limit: 20);

        self::assertNotContains('Sem Compras', self::names($result['items']));
        self::assertSame(4, $result['total']);
    }

    /**
     * Cada cliente volta com os dados do seu tipo: pessoa física com nome e CPF,
     * pessoa jurídica com razão social, nome fantasia e CNPJ.
     */
    public function testReturnsDataOfEachCustomerType(): void
    {
        $items = $this->repository->purchaseRanking(CustomerReportSort::Total, SortOrder::Desc, page: 1, limit: 20)['items'];

        $company = $items[1];
        self::assertSame(['COMPANY', 'Loja LTDA', 'Loja Bacana', '11222333000181', null, null], [
            $company['personType'], $company['legalName'], $company['tradeName'], $company['cnpj'], $company['fullName'], $company['cpf'],
        ]);

        $individual = $items[0];
        self::assertSame(['INDIVIDUAL', 'Maria Souza', '11144477735', 'maria@gmail.com', null], [
            $individual['personType'], $individual['fullName'], $individual['cpf'], $individual['email'], $individual['cnpj'],
        ]);
    }

    /**
     * Top 2 (primeira página com limite 2) e a página seguinte.
     * Espera os 2 primeiros e depois os 2 seguintes, com o total de clientes (4) nas duas.
     */
    public function testPaginatesRanking(): void
    {
        $firstPage = $this->repository->purchaseRanking(CustomerReportSort::Total, SortOrder::Desc, page: 1, limit: 2);
        $secondPage = $this->repository->purchaseRanking(CustomerReportSort::Total, SortOrder::Desc, page: 2, limit: 2);

        self::assertSame(['Maria Souza', 'Loja LTDA'], self::names($firstPage['items']));
        self::assertSame(['João Silva', 'Pedro Lima'], self::names($secondPage['items']));
        self::assertSame(4, $firstPage['total']);
        self::assertSame(4, $secondPage['total']);
    }

    /**
     * Página além da última.
     * Espera lista vazia, mas com o total de clientes.
     */
    public function testPageBeyondTheLastReturnsEmpty(): void
    {
        $result = $this->repository->purchaseRanking(CustomerReportSort::Total, SortOrder::Desc, page: 10, limit: 2);

        self::assertSame([], $result['items']);
        self::assertSame(4, $result['total']);
    }

    // =========================================================================
    // Auxiliares
    // =========================================================================

    /**
     * Grava os clientes e compras da tabela do comentário da classe, nesta ordem (os ids seguem a ordem).
     */
    private function createCustomersAndPurchases(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $channel = $em->getRepository(SalesChannel::class)->findOneBy(['code' => SalesChannel::API]);

        $customers = [
            [(new IndividualCustomer())->setFullName('João Silva')->setCpf('52998224725')->setEmail('joao@email.com'), [1000, 2000, 3000]],
            [(new IndividualCustomer())->setFullName('Maria Souza')->setCpf('11144477735')->setEmail('maria@gmail.com'), [10000]],
            [(new CompanyCustomer())->setLegalName('Loja LTDA')->setTradeName('Loja Bacana')->setCnpj('11222333000181')->setEmail('c@loja.com'), [4000, 4001]],
            [(new IndividualCustomer())->setFullName('Pedro Lima')->setCpf('39053344705')->setEmail('pedro@email.com'), [1000]],
            [(new IndividualCustomer())->setFullName('Sem Compras')->setCpf('86288366757')->setEmail('sem@email.com'), []],
        ];

        $purchaseNumber = 0;
        foreach ($customers as [$customer, $amounts]) {
            // flush a cada cliente para os ids seguirem a ordem da tabela (usada no desempate)
            $em->persist($customer);
            $em->flush();

            foreach ($amounts as $amount) {
                $em->persist(self::purchase('PED-'.++$purchaseNumber, $customer, $channel, $amount));
            }
        }

        $em->flush();
        $em->clear();
    }

    private static function purchase(string $referenceCode, Customer $customer, SalesChannel $channel, int $amount): Purchase
    {
        return (new Purchase())
            ->setReferenceCode($referenceCode)
            ->setCustomer($customer)
            ->setSalesChannel($channel)
            ->setAmount($amount)
            ->setDiscount(0)
            ->setPurchasedAt(new \DateTimeImmutable('2026-11-21T12:00:00+00:00'));
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return list<string>
     */
    private static function names(array $items): array
    {
        return array_map(static fn (array $row): string => $row['fullName'] ?? $row['legalName'], $items);
    }
}
