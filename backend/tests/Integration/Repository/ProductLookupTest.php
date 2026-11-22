<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Product;
use App\Entity\ProductExternalId;
use App\Entity\SalesChannel;
use App\Repository\ProductRepository;
use App\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Testes do ProductRepository::findByExternalIdOrInternalCode (busca do produto de um item da compra).
 *
 * O que é testado: a consulta única com as duas condições (código no canal OU código interno), e que cada produto
 * volta com TODOS os seus códigos carregados, não só o que passou no filtro (o PurchaseService decide por eles).
 *
 * Como é testado: no banco de teste (app_test), com produtos gravados pelo Doctrine antes de cada busca.
 */
final class ProductLookupTest extends IntegrationTestCase
{
    /**
     * Produto com código nos canais API e WOOCOMMERCE, buscado pelo código do WOOCOMMERCE.
     * Espera o produto, com os dois códigos na coleção.
     */
    public function testFindsByExternalIdWithAllCodesLoaded(): void
    {
        $this->product('Camisa 1962', 'CAM-001', ['API' => 'CAM-001', 'WOOCOMMERCE' => '26']);

        $found = $this->repository()->findByExternalIdOrInternalCode('WOOCOMMERCE', '26', null);

        self::assertCount(1, $found);
        self::assertSame('Camisa 1962', $found[0]->getName());
        self::assertSame(
            ['API' => 'CAM-001', 'WOOCOMMERCE' => '26'],
            self::codes($found[0]),
        );
    }

    /**
     * Código desconhecido no canal, mas com o código interno de um produto.
     * Espera o produto do código interno, com os códigos que ele já tem.
     */
    public function testFindsByInternalCode(): void
    {
        $this->product('Camisa 1962', 'CAM-001', ['API' => 'CAM-001']);

        $found = $this->repository()->findByExternalIdOrInternalCode('WOOCOMMERCE', '26', 'CAM-001');

        self::assertCount(1, $found);
        self::assertSame(['API' => 'CAM-001'], self::codes($found[0]));
    }

    /**
     * Cada condição encontra um produto diferente.
     * Espera os dois produtos numa consulta só.
     */
    public function testReturnsBothWhenEachConditionMatchesAProduct(): void
    {
        $this->product('Camisa (pedido antigo)', null, ['WOOCOMMERCE' => '26']);
        $this->product('Camisa 1962', 'CAM-001', ['API' => 'CAM-001']);

        $found = $this->repository()->findByExternalIdOrInternalCode('WOOCOMMERCE', '26', 'CAM-001');

        self::assertEqualsCanonicalizing(
            ['Camisa (pedido antigo)', 'Camisa 1962'],
            array_map(static fn (Product $product): string => $product->getName(), $found),
        );
    }

    /**
     * Nada encontrado: o mesmo código em OUTRO canal não conta, e sem código interno só vale a primeira condição.
     * Espera lista vazia.
     */
    public function testIgnoresSameCodeInOtherChannel(): void
    {
        $this->product('Camisa 1962', 'CAM-001', ['API' => '26']);

        self::assertSame([], $this->repository()->findByExternalIdOrInternalCode('WOOCOMMERCE', '26', null));
    }

    /**
     * @param array<string, string> $codes código do canal => código do produto nele
     */
    private function product(string $name, ?string $internalCode, array $codes): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $product = (new Product())->setName($name)->setInternalCode($internalCode);

        foreach ($codes as $channel => $code) {
            $product->addExternalId(
                (new ProductExternalId())
                    ->setSalesChannel($em->getRepository(SalesChannel::class)->findOneBy(['code' => $channel]))
                    ->setExternalId($code),
            );
        }

        $em->persist($product);
        $em->flush();
        $em->clear();
    }

    private function repository(): ProductRepository
    {
        return static::getContainer()->get(ProductRepository::class);
    }

    /**
     * @return array<string, string> código do canal => código do produto nele, em ordem de canal
     */
    private static function codes(Product $product): array
    {
        $codes = [];
        foreach ($product->getExternalIds() as $externalId) {
            $codes[$externalId->getSalesChannel()->getCode()] = $externalId->getExternalId();
        }
        ksort($codes);

        return $codes;
    }
}
