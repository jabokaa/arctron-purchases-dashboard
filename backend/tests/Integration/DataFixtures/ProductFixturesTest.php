<?php

declare(strict_types=1);

namespace App\Tests\Integration\DataFixtures;

use App\DataFixtures\ProductFixtures;
use App\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Testes do seeder de produtos (ProductFixtures).
 *
 * O que é testado: se o seeder cadastra os 11 artigos de futebol com nome, código interno e imagem copiada do repositório para a pasta de uploads,
 * cria o código de cada um no canal API (para as compras usarem os produtos cadastrados),
 * pode rodar de novo sem duplicar nada e não altera produto que já existia.
 *
 * Como é testado: o seeder roda de verdade no banco de teste (app_test), esvaziado antes de cada teste.
 */
final class ProductFixturesTest extends IntegrationTestCase
{
    /**
     * Seeder em banco vazio.
     * Espera os 11 produtos, todos com a imagem copiada para a pasta de uploads e com o código no canal API.
     */
    public function testSeedsSportsProductsWithImages(): void
    {
        $this->runSeeder();

        $products = $this->connection()->fetchAllAssociative(
            "SELECT p.internal_code, p.name, p.image_url, e.external_id
             FROM products p
             JOIN product_external_ids e ON e.product_id = p.id
             JOIN sales_channels s ON s.id = e.sales_channel_id AND s.code = 'API'
             ORDER BY p.internal_code",
        );

        self::assertCount(11, $products);
        self::assertEqualsCanonicalizing(ProductFixtures::internalCodes(), array_column($products, 'internal_code'));

        foreach ($products as $product) {
            self::assertMatchesRegularExpression('#^/uploads/products/[a-f0-9]{32}\.jpg$#', $product['image_url'], $product['name']);
            self::assertFileExists(static::getContainer()->getParameter('app.product_images_dir').'/'.basename($product['image_url']), $product['name']);
            self::assertSame($product['internal_code'], $product['external_id'], 'O código no canal API é o próprio código interno.');
        }

        self::assertContains('Chuteira de Campo Speedster', array_column($products, 'name'));
        self::assertContains('Bola de Futebol de Campo', array_column($products, 'name'));
    }

    /**
     * Seeder rodado duas vezes.
     * Espera os mesmos 11 produtos, sem duplicar produtos, códigos nem imagens.
     */
    public function testCanRunTwiceWithoutDuplicating(): void
    {
        $this->runSeeder();
        $this->runSeeder();

        self::assertSame(11, $this->countRows('products'));
        self::assertSame(11, $this->countRows('product_external_ids'));
        self::assertSame(11, $this->countRows("products WHERE image_url LIKE '/uploads/products/%'"));
    }

    /**
     * Produto com o mesmo código interno já cadastrado (ex.: alterado pelo usuário).
     * Espera que o seeder não altere o nome e cadastre só os outros (a imagem que faltava é copiada).
     */
    public function testDoesNotChangeExistingProducts(): void
    {
        $this->connection()->executeStatement(
            "INSERT INTO products (name, internal_code, created_at, updated_at) VALUES ('Minha Chuteira', 'CHU-001', now(), now())",
        );

        $this->runSeeder();

        self::assertSame('Minha Chuteira', $this->connection()->fetchOne("SELECT name FROM products WHERE internal_code = 'CHU-001'"));
        self::assertSame(11, $this->countRows('products'));
    }

    /**
     * Produto cadastrado antes do upload, com a imagem como endereço externo.
     * Espera a imagem copiada e trocada pelo arquivo local.
     */
    public function testDownloadsImageOfProductWithExternalUrl(): void
    {
        $this->connection()->executeStatement(
            "INSERT INTO products (name, internal_code, image_url, created_at, updated_at) VALUES ('Chuteira', 'CHU-001', 'https://exemplo.com/a.jpg', now(), now())",
        );

        $this->runSeeder();

        self::assertMatchesRegularExpression('#^/uploads/products/[a-f0-9]{32}\.jpg$#', (string) $this->connection()->fetchOne("SELECT image_url FROM products WHERE internal_code = 'CHU-001'"));
    }

    /**
     * Produto que já tem arquivo local.
     * Espera que rodar de novo mantenha o mesmo arquivo (sem copiar outra vez).
     */
    public function testKeepsExistingLocalImage(): void
    {
        $this->runSeeder();
        $before = $this->connection()->fetchAllKeyValue('SELECT internal_code, image_url FROM products');

        $this->runSeeder();

        self::assertSame($before, $this->connection()->fetchAllKeyValue('SELECT internal_code, image_url FROM products'));
    }

    /**
     * Cada produto do seeder tem o seu arquivo de imagem no repositório.
     * Espera um JPG válido para cada código interno.
     */
    public function testEveryProductHasImageFileInRepository(): void
    {
        foreach (ProductFixtures::internalCodes() as $code) {
            $file = \dirname(__DIR__, 3).'/src/DataFixtures/images/products/'.$code.'.jpg';

            self::assertFileExists($file, $code);
            self::assertSame('image/jpeg', (new \finfo(\FILEINFO_MIME_TYPE))->file($file), $code);
        }
    }

    /**
     * Compra pelo POST /api/purchases usando o código de um produto do seeder.
     * Espera que a compra use o produto cadastrado (com a imagem), sem criar outro.
     */
    public function testPurchasesUseSeededProducts(): void
    {
        $this->runSeeder();

        $response = $this->postPurchase([
            'reference_code' => 'PED-1',
            'purchased_at' => '2026-11-21T12:00:00Z',
            'customer' => ['name' => 'João Silva', 'email' => 'joao@email.com', 'cpf' => '529.982.247-25'],
            'products' => [['external_id' => 'CHU-001', 'name' => 'Chuteira', 'quantity' => 1, 'unit_price' => 29990]],
        ]);

        self::assertSame(201, $response['status']);
        self::assertSame(11, $this->countRows('products'), 'Nenhum produto novo foi criado.');
        self::assertSame('Chuteira de Campo Speedster', $response['body']['items'][0]['name']);
    }

    /**
     * O seeder pertence ao grupo "products", usado no comando:
     *   php bin/console doctrine:fixtures:load --append --group=products
     */
    public function testBelongsToProductsGroup(): void
    {
        self::assertSame(['products'], ProductFixtures::getGroups());
    }

    /**
     * Banco sem o canal de venda API (migration de seed não rodou).
     * Espera um erro explicando que é preciso rodar as migrations antes, e nenhum produto cadastrado.
     */
    public function testRequiresApiSalesChannel(): void
    {
        $this->connection()->executeStatement("DELETE FROM sales_channels WHERE code = 'API'");

        try {
            $this->runSeeder();
            self::fail('Era esperado um erro pela falta do canal API.');
        } catch (\LogicException $e) {
            self::assertSame('Canal de venda "API" não cadastrado. Rode as migrations antes do seeder.', $e->getMessage());
        }

        self::assertSame(0, $this->countRows('products'));
    }

    private function runSeeder(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        static::getContainer()->get(ProductFixtures::class)->load($em);
        $em->clear();
    }
}
