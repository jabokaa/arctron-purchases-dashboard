<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\IndividualCustomer;
use App\Entity\Product;
use App\Entity\Purchase;
use App\Entity\PurchaseItem;
use App\Entity\SalesChannel;
use App\Repository\PurchaseItemRepository;
use App\Repository\PurchaseRepository;
use App\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Testes dos indicadores usados no relatório:
 * PurchaseRepository::totals() e PurchaseItemRepository::productTotals().
 *
 * O que é testado: se o banco devolve a quantidade de compras, as somas e as médias
 * (valor e desconto por compra, preço por unidade), com as médias arredondadas para o
 * centavo mais próximo e tudo zerado quando não há compras.
 *
 * Por que fica na pasta de integração: somas, médias e arredondamento são feitos pelo banco
 * (SQL com SUM, AVG e ROUND); só o banco de teste real (app_test) prova que a consulta está certa.
 *
 * Como é testado: antes de cada teste o banco é esvaziado (IntegrationTestCase) e cada teste
 * grava, direto pelo Doctrine, as compras de que precisa.
 */
final class ReportTotalsTest extends IntegrationTestCase
{
    /**
     * Banco sem compras.
     * Espera tudo zerado: sem linhas, SUM e AVG devolvem NULL e o COALESCE troca por 0
     * (e o NULLIF evita a divisão por zero no preço médio).
     */
    public function testIndicatorsAreZeroWhenThereAreNoPurchases(): void
    {
        self::assertSame(
            ['count' => 0, 'amountTotal' => 0, 'amountAverage' => 0, 'discountTotal' => 0, 'discountAverage' => 0],
            $this->purchaseRepository()->totals(),
        );
        self::assertSame(['total' => 0, 'average' => 0, 'unitsSold' => 0], $this->purchaseItemRepository()->productTotals());
    }

    /**
     * Duas compras:
     *
     *   | Compra | Itens (quantidade × valor unitário − desconto) | Valor | Desconto |
     *   |--------|------------------------------------------------|-------|----------|
     *   | PED-1  | 2 × 5000 − 500  e  1 × 16010                   | 25510 | 500      |
     *   | PED-2  | 3 × 1000 − 100                                 |  2900 | 100      |
     *
     * Espera: 2 compras; valor 28410 (média 14205); desconto 600 (média 300).
     */
    public function testCalculatesPurchaseTotalsAndAverages(): void
    {
        $this->createPurchases([
            ['PED-1', 25510, 500, [[2, 5000, 500], [1, 16010, 0]]],
            ['PED-2', 2900, 100, [[3, 1000, 100]]],
        ]);

        self::assertSame(
            ['count' => 2, 'amountTotal' => 28410, 'amountAverage' => 14205, 'discountTotal' => 600, 'discountAverage' => 300],
            $this->purchaseRepository()->totals(),
        );
    }

    /**
     * As mesmas duas compras.
     * Espera os produtos ANTES do desconto: 2×5000 + 1×16010 + 3×1000 = 29010, em 6 unidades
     * (preço médio por unidade 29010 / 6 = 4835).
     */
    public function testCalculatesProductTotalsBeforeDiscount(): void
    {
        $this->createPurchases([
            ['PED-1', 25510, 500, [[2, 5000, 500], [1, 16010, 0]]],
            ['PED-2', 2900, 100, [[3, 1000, 100]]],
        ]);

        self::assertSame(['total' => 29010, 'average' => 4835, 'unitsSold' => 6], $this->purchaseItemRepository()->productTotals());
    }

    /**
     * Para cada caso do provider, grava compras com os valores informados e confere
     * se o banco arredonda a média do valor para o centavo mais próximo.
     *
     * @param list<int> $amounts valor de cada compra
     */
    #[DataProvider('purchaseAverageRoundingProvider')]
    public function testRoundsPurchaseAverageToTheNearestCent(array $amounts, int $expectedAverage): void
    {
        $this->createPurchases(array_map(
            static fn (int $amount, int $index): array => ['PED-'.$index, $amount, 0, [[1, $amount, 0]]],
            $amounts,
            array_keys($amounts),
        ));

        self::assertSame($expectedAverage, $this->purchaseRepository()->totals()['amountAverage']);
    }

    /**
     * @return iterable<string, array{list<int>, int}>
     */
    public static function purchaseAverageRoundingProvider(): iterable
    {
        // 1000 / 4 = 250: divisão exata
        yield 'divisão exata' => [[250, 250, 250, 250], 250];

        // 1000 / 3 = 333,33: arredonda para baixo
        yield 'arredonda para baixo' => [[1000, 0, 0], 333];

        // 2000 / 3 = 666,67: arredonda para cima
        yield 'arredonda para cima' => [[2000, 0, 0], 667];

        // 5 / 2 = 2,5: meio centavo arredonda para cima
        yield 'meio centavo' => [[5, 0], 3];
    }

    /**
     * Preço médio por unidade que não dá centavo exato.
     * 1 unidade de 5 + 1 unidade de 0 = 5 em 2 unidades = 2,5: espera 3
     * (a divisão é feita com casas decimais antes de arredondar, não em inteiros, que daria 2).
     */
    public function testRoundsProductAverageToTheNearestCent(): void
    {
        $this->createPurchases([['PED-1', 5, 0, [[1, 5, 0], [1, 0, 0]]]]);

        self::assertSame(3, $this->purchaseItemRepository()->productTotals()['average']);
    }

    // =========================================================================
    // Auxiliares
    // =========================================================================

    private function purchaseRepository(): PurchaseRepository
    {
        return static::getContainer()->get(PurchaseRepository::class);
    }

    private function purchaseItemRepository(): PurchaseItemRepository
    {
        return static::getContainer()->get(PurchaseItemRepository::class);
    }

    /**
     * Grava as compras direto pelo Doctrine.
     *
     * @param list<array{string, int, int, list<array{int, int, int}>}> $purchases
     *        [código, valor, desconto, itens: [quantidade, valor unitário, desconto]]
     */
    private function createPurchases(array $purchases): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $channel = $em->getRepository(SalesChannel::class)->findOneBy(['code' => SalesChannel::API]);
        $customer = (new IndividualCustomer())->setFullName('João Silva')->setCpf('52998224725')->setEmail('joao@email.com');
        $product = (new Product())->setName('Produto Teste');
        $em->persist($customer);
        $em->persist($product);

        foreach ($purchases as [$referenceCode, $amount, $discount, $items]) {
            $purchase = (new Purchase())
                ->setReferenceCode($referenceCode)
                ->setCustomer($customer)
                ->setSalesChannel($channel)
                ->setAmount($amount)
                ->setDiscount($discount)
                ->setPurchasedAt(new \DateTimeImmutable('2026-11-21T12:00:00+00:00'));

            foreach ($items as [$quantity, $unitPrice, $itemDiscount]) {
                $purchase->addItem(
                    (new PurchaseItem())
                        ->setProduct($product)
                        ->setQuantity($quantity)
                        ->setUnitPrice($unitPrice)
                        ->setDiscount($itemDiscount)
                        ->setTotalPrice($quantity * $unitPrice - $itemDiscount),
                );
            }

            $em->persist($purchase);
        }

        $em->flush();
        $em->clear();
    }
}
