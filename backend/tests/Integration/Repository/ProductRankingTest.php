<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\IndividualCustomer;
use App\Entity\Product;
use App\Entity\Purchase;
use App\Entity\PurchaseItem;
use App\Entity\SalesChannel;
use App\Enum\ProductReportSort;
use App\Enum\SortOrder;
use App\Repository\ProductRepository;
use App\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Testes do ProductRepository::salesRanking() (ranking de produtos pelas vendas).
 *
 * O que é testado: se o banco devolve, por produto, as unidades vendidas, em quantas compras ele
 * aparece, o valor vendido (já com o desconto dos itens) e o preço médio por unidade (arredondado
 * para o centavo), ordenados pelo critério e direção pedidos, com desempate pelo id, paginação
 * certa e só produtos vendidos.
 *
 * Por que fica na pasta de integração: agrupamento, somas, médias e ordenação são feitos pelo banco;
 * só o banco de teste real (app_test) prova que a consulta está certa.
 *
 * Como é testado: antes de cada teste o banco é esvaziado (IntegrationTestCase) e as compras abaixo
 * são gravadas direto pelo Doctrine (itens: quantidade × valor unitário − desconto):
 *
 *   | Compra | Itens                                          |
 *   |--------|------------------------------------------------|
 *   | PED-1  | Camiseta 2 × 5000 − 1000;  Boné 1 × 3000       |
 *   | PED-2  | Camiseta 1 × 5000                              |
 *   | PED-3  | Meia 10 × 500;  Camiseta 1 × 4000              |
 *   | PED-4  | Meia 1 × 1                                     |
 *
 *   | Produto  | Unidades | Compras | Total | Preço médio            |
 *   |----------|----------|---------|-------|------------------------|
 *   | Camiseta | 4        | 3       | 18000 | 4500                   |
 *   | Meia     | 11       | 2       | 5001  | 455 (454,63 arredonda) |
 *   | Boné     | 1        | 1       | 3000  | 3000                   |
 *   | Chaveiro | —        | —       | —     | — (nunca vendido)      |
 */
final class ProductRankingTest extends IntegrationTestCase
{
    private ProductRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = static::getContainer()->get(ProductRepository::class);
        $this->createPurchases();
    }

    /**
     * Para cada caso do provider, ordena pelo critério e direção pedidos e espera os produtos nessa ordem.
     *
     * @param list<string> $expected nomes na ordem esperada
     */
    #[DataProvider('sortProvider')]
    public function testSortsRanking(ProductReportSort $sort, SortOrder $order, array $expected): void
    {
        $result = $this->repository->salesRanking($sort, $order, page: 1, limit: 20);

        self::assertSame($expected, array_column($result['items'], 'name'));
    }

    /**
     * @return iterable<string, array{ProductReportSort, SortOrder, list<string>}>
     */
    public static function sortProvider(): iterable
    {
        // Maior valor vendido primeiro (o padrão do ranking)
        yield 'total desc' => [ProductReportSort::Total, SortOrder::Desc, ['Camiseta', 'Meia', 'Boné']];

        // Menor valor vendido primeiro
        yield 'total asc' => [ProductReportSort::Total, SortOrder::Asc, ['Boné', 'Meia', 'Camiseta']];

        // Mais unidades vendidas primeiro (Meia vendeu 11)
        yield 'quantity desc' => [ProductReportSort::Quantity, SortOrder::Desc, ['Meia', 'Camiseta', 'Boné']];

        // Maior preço médio por unidade primeiro
        yield 'average desc' => [ProductReportSort::Average, SortOrder::Desc, ['Camiseta', 'Boné', 'Meia']];

        // Menor preço médio por unidade primeiro
        yield 'average asc' => [ProductReportSort::Average, SortOrder::Asc, ['Meia', 'Boné', 'Camiseta']];
    }

    /**
     * Unidades, compras, total e preço médio calculados pelo banco para cada produto:
     * - o total já desconta o desconto do item (Camiseta: 9000 + 5000 + 4000 = 18000);
     * - o mesmo produto em duas compras conta 2 compras (Meia);
     * - o preço médio é arredondado para o centavo (Meia: 5001 / 11 = 454,63 → 455).
     */
    public function testCalculatesQuantityPurchasesTotalAndAverage(): void
    {
        $items = $this->repository->salesRanking(ProductReportSort::Total, SortOrder::Desc, page: 1, limit: 20)['items'];

        self::assertSame([
            ['Camiseta', 4, 3, 18000, 4500],
            ['Meia', 11, 2, 5001, 455],
            ['Boné', 1, 1, 3000, 3000],
        ], array_map(static fn (array $row): array => [$row['name'], $row['quantitySold'], $row['purchasesCount'], $row['total'], $row['average']], $items));
    }

    /**
     * Produto que nunca foi vendido não aparece no ranking nem conta no total.
     */
    public function testIgnoresProductsNeverSold(): void
    {
        $result = $this->repository->salesRanking(ProductReportSort::Total, SortOrder::Desc, page: 1, limit: 20);

        self::assertNotContains('Chaveiro', array_column($result['items'], 'name'));
        self::assertSame(3, $result['total']);
    }

    /**
     * Top 2 e a página seguinte.
     * Espera os 2 primeiros e depois o terceiro, com o total de produtos (3) nas duas.
     */
    public function testPaginatesRanking(): void
    {
        $firstPage = $this->repository->salesRanking(ProductReportSort::Total, SortOrder::Desc, page: 1, limit: 2);
        $secondPage = $this->repository->salesRanking(ProductReportSort::Total, SortOrder::Desc, page: 2, limit: 2);

        self::assertSame(['Camiseta', 'Meia'], array_column($firstPage['items'], 'name'));
        self::assertSame(['Boné'], array_column($secondPage['items'], 'name'));
        self::assertSame(3, $firstPage['total']);
        self::assertSame(3, $secondPage['total']);
    }

    /**
     * Dois produtos empatados no critério ficam na ordem do id (o que foi criado antes primeiro).
     */
    public function testBreaksTiesById(): void
    {
        // Boné e um novo produto, ambos com 1 unidade vendida
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $channel = $em->getRepository(SalesChannel::class)->findOneBy(['code' => SalesChannel::API]);
        $customer = $em->getRepository(IndividualCustomer::class)->findOneBy([]);
        $pulseira = (new Product())->setName('Pulseira');
        $em->persist($pulseira);
        $em->persist(self::purchase('PED-5', $customer, $channel, [[$pulseira, 1, 100, 0]]));
        $em->flush();
        $em->clear();

        $items = $this->repository->salesRanking(ProductReportSort::Quantity, SortOrder::Asc, page: 1, limit: 20)['items'];

        self::assertSame(['Boné', 'Pulseira'], \array_slice(array_column($items, 'name'), 0, 2));
    }

    // =========================================================================
    // Auxiliares
    // =========================================================================

    /**
     * Grava as compras da tabela do comentário da classe.
     */
    private function createPurchases(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $channel = $em->getRepository(SalesChannel::class)->findOneBy(['code' => SalesChannel::API]);
        $customer = (new IndividualCustomer())->setFullName('João Silva')->setCpf('52998224725')->setEmail('joao@email.com');
        $em->persist($customer);

        // Criados nesta ordem: os ids seguem a ordem (usada no desempate)
        $camiseta = (new Product())->setName('Camiseta');
        $bone = (new Product())->setName('Boné');
        $meia = (new Product())->setName('Meia');
        $chaveiro = (new Product())->setName('Chaveiro');
        foreach ([$camiseta, $bone, $meia, $chaveiro] as $product) {
            $em->persist($product);
            $em->flush();
        }

        $em->persist(self::purchase('PED-1', $customer, $channel, [[$camiseta, 2, 5000, 1000], [$bone, 1, 3000, 0]]));
        $em->persist(self::purchase('PED-2', $customer, $channel, [[$camiseta, 1, 5000, 0]]));
        $em->persist(self::purchase('PED-3', $customer, $channel, [[$meia, 10, 500, 0], [$camiseta, 1, 4000, 0]]));
        $em->persist(self::purchase('PED-4', $customer, $channel, [[$meia, 1, 1, 0]]));

        $em->flush();
        $em->clear();
    }

    /**
     * @param list<array{Product, int, int, int}> $items [produto, quantidade, valor unitário, desconto]
     */
    private static function purchase(string $referenceCode, IndividualCustomer $customer, SalesChannel $channel, array $items): Purchase
    {
        $purchase = (new Purchase())
            ->setReferenceCode($referenceCode)
            ->setCustomer($customer)
            ->setSalesChannel($channel)
            ->setPurchasedAt(new \DateTimeImmutable('2026-11-21T12:00:00+00:00'));

        $amount = 0;
        $discount = 0;
        foreach ($items as [$product, $quantity, $unitPrice, $itemDiscount]) {
            $totalPrice = $quantity * $unitPrice - $itemDiscount;
            $amount += $totalPrice;
            $discount += $itemDiscount;

            $purchase->addItem(
                (new PurchaseItem())
                    ->setProduct($product)
                    ->setQuantity($quantity)
                    ->setUnitPrice($unitPrice)
                    ->setDiscount($itemDiscount)
                    ->setTotalPrice($totalPrice),
            );
        }

        return $purchase->setAmount($amount)->setDiscount($discount);
    }
}
