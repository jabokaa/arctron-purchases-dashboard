<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Dto\ProductFilterInput;
use App\Entity\Product;
use App\Entity\ProductExternalId;
use App\Entity\SalesChannel;
use App\Repository\ProductRepository;
use App\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Testes das consultas do catálogo no ProductRepository: search() (lista com busca e paginação)
 * e findWithExternalIds() (detalhe).
 *
 * O que é testado: se a busca por parte do nome ou do código interno devolve os produtos certos
 * (sem diferenciar maiúsculas, com % e _ tratados como texto), em ordem alfabética, com a paginação
 * contando produtos (e não linhas do join com os códigos dos canais) e com os códigos já carregados.
 *
 * Por que fica na pasta de integração: a consulta (LIKE, ORDER BY, paginação) é feita pelo banco;
 * só o banco de teste real (app_test) prova que ela está certa.
 *
 * Como é testado: antes de cada teste o banco é esvaziado (IntegrationTestCase) e os produtos abaixo
 * são gravados direto pelo Doctrine:
 *
 *   | Nome                         | Código interno | Códigos nos canais            |
 *   |------------------------------|----------------|-------------------------------|
 *   | Chuteira de Campo            | CHU-001        | API: CHU-001; WOOCOMMERCE: 93 |
 *   | Bola de Futebol              | BOL-001        | —                             |
 *   | Chuteira Society             | CHU_100%       | —                             |
 *   | Apito                        | (nenhum)       | —                             |
 */
final class ProductCatalogTest extends IntegrationTestCase
{
    private ProductRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = static::getContainer()->get(ProductRepository::class);
        $this->createProducts();
    }

    /**
     * Para cada caso do provider, busca e espera exatamente os produtos listados, em ordem alfabética.
     *
     * @param list<string> $expected
     */
    #[DataProvider('searchProvider')]
    public function testSearchesProducts(?string $search, array $expected): void
    {
        $result = $this->repository->search(new ProductFilterInput(search: $search));

        self::assertSame($expected, self::names($result['items']));
        self::assertSame(\count($expected), $result['total']);
    }

    /**
     * @return iterable<string, array{string|null, list<string>}>
     */
    public static function searchProvider(): iterable
    {
        // Sem busca: todos, em ordem alfabética
        yield 'sem busca' => [null, ['Apito', 'Bola de Futebol', 'Chuteira de Campo', 'Chuteira Society']];

        // Busca só com espaços equivale a sem busca
        yield 'busca vazia' => ['   ', ['Apito', 'Bola de Futebol', 'Chuteira de Campo', 'Chuteira Society']];

        // Parte do nome, em maiúsculas
        yield 'parte do nome' => ['CHUTEIRA', ['Chuteira de Campo', 'Chuteira Society']];

        // Parte do código interno, em minúsculas
        yield 'parte do código' => ['bol-', ['Bola de Futebol']];

        // "%" digitado é texto, não curinga
        yield '% literal' => ['100%', ['Chuteira Society']];

        // "_" digitado é texto, não "um caractere qualquer" (senão acharia CHU-001 também)
        yield '_ literal' => ['chu_', ['Chuteira Society']];

        // Nada encontrado
        yield 'nenhum resultado' => ['raquete', []];
    }

    /**
     * Segunda página com 2 por página.
     * Espera os produtos 3 e 4 da ordem alfabética, e o total de todos (4).
     */
    public function testPaginates(): void
    {
        $result = $this->repository->search(new ProductFilterInput(page: 2, perPage: 2));

        self::assertSame(['Chuteira de Campo', 'Chuteira Society'], self::names($result['items']));
        self::assertSame(4, $result['total']);
    }

    /**
     * Um produto com 2 códigos de canal ocupa 2 linhas no join.
     * Espera que a página conte PRODUTOS: 1 por página devolve 1 produto, com os 2 códigos carregados.
     */
    public function testPageCountsProductsNotJoinRows(): void
    {
        $result = $this->repository->search(new ProductFilterInput(search: 'campo', perPage: 1));

        self::assertCount(1, $result['items']);
        self::assertCount(2, $result['items'][0]->getExternalIds());
    }

    /**
     * Detalhe por id.
     * Espera o produto com a imagem e os códigos de cada canal; id inexistente devolve null.
     */
    public function testFindsProductWithExternalIds(): void
    {
        $id = (int) $this->connection()->fetchOne("SELECT id FROM products WHERE internal_code = 'CHU-001'");

        $product = $this->repository->findWithExternalIds($id);

        self::assertSame('https://exemplo.com/chuteira.jpg', $product->getImageUrl());
        // assertEquals: os códigos são conferidos por canal, sem depender da ordem em que o banco os devolve
        self::assertEquals(
            ['API' => 'CHU-001', 'WOOCOMMERCE' => '93'],
            array_column(array_map(static fn (ProductExternalId $externalId): array => [$externalId->getSalesChannel()->getCode(), $externalId->getExternalId()], $product->getExternalIds()->toArray()), 1, 0),
        );
        self::assertNull($this->repository->findWithExternalIds(999999));
    }

    // =========================================================================
    // Auxiliares
    // =========================================================================

    private function createProducts(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $api = $em->getRepository(SalesChannel::class)->findOneBy(['code' => SalesChannel::API]);
        $wooCommerce = $em->getRepository(SalesChannel::class)->findOneBy(['code' => SalesChannel::WOOCOMMERCE]);

        $chuteira = (new Product())->setName('Chuteira de Campo')->setInternalCode('CHU-001')->setImageUrl('https://exemplo.com/chuteira.jpg');
        $chuteira->addExternalId((new ProductExternalId())->setSalesChannel($api)->setExternalId('CHU-001'));
        $chuteira->addExternalId((new ProductExternalId())->setSalesChannel($wooCommerce)->setExternalId('93'));

        foreach ([
            $chuteira,
            (new Product())->setName('Bola de Futebol')->setInternalCode('BOL-001'),
            (new Product())->setName('Chuteira Society')->setInternalCode('CHU_100%'),
            (new Product())->setName('Apito'),
        ] as $product) {
            $em->persist($product);
        }

        $em->flush();
        $em->clear();
    }

    /**
     * @param list<Product> $products
     *
     * @return list<string>
     */
    private static function names(array $products): array
    {
        return array_map(static fn (Product $product): string => $product->getName(), $products);
    }
}
