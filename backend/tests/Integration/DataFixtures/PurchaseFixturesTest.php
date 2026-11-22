<?php

declare(strict_types=1);

namespace App\Tests\Integration\DataFixtures;

use App\DataFixtures\ProductFixtures;
use App\DataFixtures\PurchaseFixtures;
use App\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Testes do seeder de compras (PurchaseFixtures).
 *
 * O que é testado: se o seeder cadastra as 40 compras de exemplo (com clientes pessoa física e empresa, itens e
 * descontos), se os números fecham, se rodar de novo não duplica nada e se os relatórios do dashboard
 * passam a ter dados.
 *
 * Como é testado: o seeder roda de verdade no banco de teste (app_test), esvaziado antes de cada teste.
 * Os produtos são cadastrados antes pelo ProductFixtures, como na dependência real.
 */
final class PurchaseFixturesTest extends IntegrationTestCase
{
    /**
     * Seeder em banco vazio.
     * Espera 40 compras, 8 clientes (6 pessoas físicas e 2 empresas), só os produtos do catálogo e
     * o valor de cada compra igual à soma dos itens menos os descontos.
     */
    public function testSeedsPurchasesWithCustomersAndItems(): void
    {
        $this->runSeeders();

        self::assertSame(PurchaseFixtures::COUNT, $this->countRows('purchases'));
        self::assertSame(8, $this->countRows('customers'));
        self::assertSame(6, $this->countRows('individual_customers'));
        self::assertSame(2, $this->countRows('company_customers'));
        self::assertSame(11, $this->countRows('products'), 'Nenhum produto novo além do catálogo.');
        self::assertGreaterThan(PurchaseFixtures::COUNT, $this->countRows('purchase_items'));

        $mismatches = $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM purchases p
             WHERE p.amount <> (SELECT COALESCE(SUM(i.total_price), 0) FROM purchase_items i WHERE i.purchase_id = p.id)',
        );
        self::assertSame(0, (int) $mismatches);
    }

    /**
     * Seeder rodado duas vezes.
     * Espera as mesmas 40 compras, sem duplicar compras, clientes nem itens.
     */
    public function testCanRunTwiceWithoutDuplicating(): void
    {
        $this->runSeeders();
        $items = $this->countRows('purchase_items');

        $this->runSeeders();

        self::assertSame(PurchaseFixtures::COUNT, $this->countRows('purchases'));
        self::assertSame(8, $this->countRows('customers'));
        self::assertSame($items, $this->countRows('purchase_items'));
    }

    /**
     * Compras de exemplo já gravadas e depois os relatórios do dashboard.
     * Espera resumo, evolução e os dois rankings com dados.
     */
    public function testReportsHaveData(): void
    {
        $this->runSeeders();

        $summary = $this->get('/api/reports/summary');
        self::assertSame(PurchaseFixtures::COUNT, $summary['purchases_count']);
        self::assertSame($summary['products']['total'] - $summary['discount']['total'], $summary['amount']['total']);

        self::assertNotEmpty($this->get('/api/reports/timeline?interval=month')['data']);
        self::assertSame(8, $this->get('/api/reports/customers')['meta']['total']);
        self::assertGreaterThan(0, $this->get('/api/reports/products')['meta']['total']);
    }

    /**
     * O seeder pertence ao grupo "purchases", usado no comando:
     *   php bin/console doctrine:fixtures:load --append --group=purchases
     */
    public function testBelongsToPurchasesGroupAndDependsOnProducts(): void
    {
        self::assertSame(['purchases'], PurchaseFixtures::getGroups());
        self::assertSame([ProductFixtures::class], (new \ReflectionClass(PurchaseFixtures::class))->newInstanceWithoutConstructor()->getDependencies());
    }

    /**
     * @return array<string, mixed>
     */
    private function get(string $url): array
    {
        $this->client->request('GET', $url);

        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }

    private function runSeeders(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        static::getContainer()->get(ProductFixtures::class)->load($em);
        $em->clear();
        static::getContainer()->get(PurchaseFixtures::class)->load($em);
        $em->clear();
    }
}
