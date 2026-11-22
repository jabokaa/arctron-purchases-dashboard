<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Dto\ProductFilterInput;
use App\Dto\ProductSaveInput;
use App\Entity\Product;
use App\Exception\DuplicateProductCodeException;
use App\Exception\ProductHasSalesException;
use App\Exception\ProductNotFoundException;
use App\Repository\ProductRepository;
use App\Repository\PurchaseItemRepository;
use App\Exception\InvalidImageException;
use App\Service\ProductImageStorageInterface;
use App\Service\ProductService;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Testes unitários do ProductService (regras do CRUD de produtos).
 *
 * O que é testado:
 * - criar/atualizar: espaços em volta removidos, código interno vazio vira null, a imagem não é alterada,
 *   código interno repetido em OUTRO produto é recusado (o próprio produto pode manter o seu),
 *   e o UNIQUE do banco (duas gravações ao mesmo tempo) vira a mesma exceção;
 * - buscar/atualizar/remover produto inexistente: ProductNotFoundException;
 * - remover: produto vendido é recusado; sem vendas, é removido;
 * - listar: dados de paginação;
 * - imagem: enviar grava o arquivo novo e apaga o anterior, remover apaga o arquivo, e excluir o produto apaga a imagem.
 *
 * Como é testado:
 * - É um teste unitário puro (TestCase): o Symfony não é iniciado e o banco não é acessado.
 * - Os repositórios e o EntityManager são STUBS por padrão (setUp); cada teste troca por MOCK o que precisa conferir.
 */
final class ProductServiceTest extends TestCase
{
    private const IMAGE = '/uploads/products/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.jpg';

    private const NEW_IMAGE = '/uploads/products/bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb.png';

    private EntityManagerInterface&Stub $em;

    private ProductRepository&Stub $products;

    private PurchaseItemRepository&Stub $purchaseItems;

    private ProductImageStorageInterface&Stub $images;

    protected function setUp(): void
    {
        $this->em = $this->createStub(EntityManagerInterface::class);
        $this->products = $this->createStub(ProductRepository::class);
        $this->purchaseItems = $this->createStub(PurchaseItemRepository::class);
        $this->images = $this->createStub(ProductImageStorageInterface::class);
    }

    // =========================================================================
    // Criar
    // =========================================================================

    /**
     * Produto novo com espaços em volta dos campos.
     * Espera o produto gravado (persist + flush) com os campos sem os espaços.
     */
    public function testCreatesProductWithTrimmedFields(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->em->expects($this->once())->method('persist')->willReturnCallback(static fn (Product $product) => $product->onPrePersist());
        $this->em->expects($this->once())->method('flush');

        $output = $this->service()->create(new ProductSaveInput('  Chuteira de Campo  ', ' CHU-001 '));

        self::assertSame('Chuteira de Campo', $output->name);
        self::assertSame('CHU-001', $output->internalCode);
        self::assertNull($output->imageUrl);
    }

    /**
     * Código interno enviado vazio.
     * Espera gravado como null (e nenhuma busca de código repetido).
     */
    public function testEmptyOptionalFieldsBecomeNull(): void
    {
        $this->em->method('persist')->willReturnCallback(static fn (Product $product) => $product->onPrePersist());

        $this->products = $this->createMock(ProductRepository::class);
        $this->products->expects($this->never())->method('findOneBy');

        $output = $this->service()->create(new ProductSaveInput('Bola', '   '));

        self::assertNull($output->internalCode);
    }

    /**
     * Código interno que já pertence a outro produto.
     * Espera DuplicateProductCodeException, sem gravar nada.
     */
    public function testCreateRejectsCodeOfAnotherProduct(): void
    {
        $this->products->method('findOneBy')->willReturn((new Product())->setName('Outro'));

        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->em->expects($this->never())->method('persist');
        $this->em->expects($this->never())->method('flush');

        $this->expectException(DuplicateProductCodeException::class);
        $this->expectExceptionMessage('Já existe um produto com o código interno "CHU-001".');

        $this->service()->create(new ProductSaveInput('Chuteira', 'CHU-001'));
    }

    /**
     * Outra requisição gravou o mesmo código ao mesmo tempo: o UNIQUE do banco recusa no flush.
     * Espera a mesma DuplicateProductCodeException.
     */
    public function testConvertsUniqueViolationIntoDuplicateCode(): void
    {
        $driverException = new class('duplicate key value violates unique constraint "uniq_products_internal_code"') extends AbstractException {
        };
        $this->em->method('flush')->willThrowException(new UniqueConstraintViolationException($driverException, null));

        $this->expectException(DuplicateProductCodeException::class);

        $this->service()->create(new ProductSaveInput('Chuteira', 'CHU-001'));
    }

    // =========================================================================
    // Buscar e atualizar
    // =========================================================================

    /**
     * Produto inexistente.
     * Espera ProductNotFoundException com o id.
     */
    public function testFindThrowsWhenProductDoesNotExist(): void
    {
        $this->products->method('findWithExternalIds')->willReturn(null);

        $this->expectException(ProductNotFoundException::class);
        $this->expectExceptionMessage('Produto 99 não encontrado.');

        $this->service()->find(99);
    }

    /**
     * Atualização de produto existente.
     * Espera nome e código substituídos, a imagem mantida e a gravação.
     */
    public function testUpdatesAllFields(): void
    {
        $product = self::product();
        $this->products->method('findWithExternalIds')->willReturn($product);

        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->em->expects($this->never())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $this->service()->update(7, new ProductSaveInput('Chuteira Nova', 'CHU-002'));

        self::assertSame('Chuteira Nova', $product->getName());
        self::assertSame('CHU-002', $product->getInternalCode());
        self::assertSame(self::IMAGE, $product->getImageUrl(), 'O PUT não mexe na imagem.');
    }

    /**
     * Atualização mantendo o próprio código interno.
     * Espera sucesso: o código "repetido" é do próprio produto.
     */
    public function testUpdateAcceptsOwnCode(): void
    {
        $product = self::product();
        $this->products->method('findWithExternalIds')->willReturn($product);
        $this->products->method('findOneBy')->willReturn($product);

        $output = $this->service()->update(7, new ProductSaveInput('Chuteira Renomeada', 'CHU-001'));

        self::assertSame('Chuteira Renomeada', $output->name);
    }

    /**
     * Atualização para o código interno de outro produto.
     * Espera DuplicateProductCodeException, sem gravar.
     */
    public function testUpdateRejectsCodeOfAnotherProduct(): void
    {
        $this->products->method('findWithExternalIds')->willReturn(self::product());
        $this->products->method('findOneBy')->willReturn((new Product())->setName('Outro'));

        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->em->expects($this->never())->method('flush');

        $this->expectException(DuplicateProductCodeException::class);

        $this->service()->update(7, new ProductSaveInput('Chuteira', 'BOL-001'));
    }

    /**
     * Atualização de produto inexistente.
     * Espera ProductNotFoundException.
     */
    public function testUpdateThrowsWhenProductDoesNotExist(): void
    {
        $this->products->method('findWithExternalIds')->willReturn(null);

        $this->expectException(ProductNotFoundException::class);

        $this->service()->update(99, new ProductSaveInput('Chuteira'));
    }

    // =========================================================================
    // Remover
    // =========================================================================

    /**
     * Produto sem vendas.
     * Espera o produto removido (remove + flush).
     */
    public function testDeletesProductWithoutSales(): void
    {
        $product = self::product();
        $this->products->method('findWithExternalIds')->willReturn($product);
        $this->purchaseItems->method('existsForProduct')->willReturn(false);

        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->em->expects($this->once())->method('remove')->with($product);
        $this->em->expects($this->once())->method('flush');

        $this->service()->delete(7);
    }

    /**
     * Produto que já foi vendido.
     * Espera ProductHasSalesException, sem remover nada.
     */
    public function testDeleteRejectsProductWithSales(): void
    {
        $this->products->method('findWithExternalIds')->willReturn(self::product());
        $this->purchaseItems->method('existsForProduct')->willReturn(true);

        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->em->expects($this->never())->method('remove');

        $this->expectException(ProductHasSalesException::class);
        $this->expectExceptionMessage('O produto 7 já foi vendido e não pode ser removido.');

        $this->service()->delete(7);
    }

    /**
     * Remoção de produto inexistente.
     * Espera ProductNotFoundException.
     */
    public function testDeleteThrowsWhenProductDoesNotExist(): void
    {
        $this->products->method('findWithExternalIds')->willReturn(null);

        $this->expectException(ProductNotFoundException::class);

        $this->service()->delete(99);
    }

    // =========================================================================
    // Listar
    // =========================================================================

    /**
     * Lista com 2 produtos de um total de 41.
     * Espera os produtos no formato da API e a paginação (41 / 20 = 3 páginas).
     */
    public function testListBuildsPageAndMeta(): void
    {
        $this->products->method('search')->willReturn(['items' => [self::product(), self::product()], 'total' => 41]);

        $result = $this->service()->list(new ProductFilterInput(page: 1, perPage: 20));

        self::assertCount(2, $result->data);
        self::assertSame('Chuteira de Campo', $result->data[0]->name);
        self::assertSame(['page' => 1, 'per_page' => 20, 'total' => 41, 'total_pages' => 3], $result->meta);
    }

    /**
     * Produto com imagem enviada, sem vendas.
     * Espera o arquivo da imagem apagado junto com o produto.
     */
    public function testDeleteRemovesImageFile(): void
    {
        $this->products->method('findWithExternalIds')->willReturn(self::product());

        $this->images = $this->createMock(ProductImageStorageInterface::class);
        $this->images->expects($this->once())->method('remove')->with(self::IMAGE);

        $this->service()->delete(7);
    }

    // =========================================================================
    // Imagem
    // =========================================================================

    /**
     * Envio de uma imagem nova para um produto que já tinha outra.
     * Espera a imagem nova gravada no produto e o arquivo antigo apagado.
     */
    public function testSetImageReplacesPreviousFile(): void
    {
        $product = self::product();
        $this->products->method('findWithExternalIds')->willReturn($product);
        $file = new UploadedFile(__FILE__, 'foto.png', 'image/png', null, true);

        $this->images = $this->createMock(ProductImageStorageInterface::class);
        $this->images->expects($this->once())->method('storeUploaded')->with($file)->willReturn(self::NEW_IMAGE);
        $this->images->expects($this->once())->method('remove')->with(self::IMAGE);

        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->em->expects($this->once())->method('flush');

        $output = $this->service()->setImage(7, $file);

        self::assertSame(self::NEW_IMAGE, $output->imageUrl);
        self::assertSame(self::NEW_IMAGE, $product->getImageUrl());
    }

    /**
     * Arquivo que não é uma imagem aceita: o storage lança InvalidImageException.
     * Espera a exceção, a imagem antiga mantida e nada gravado.
     */
    public function testSetImageRejectsInvalidFile(): void
    {
        $product = self::product();
        $this->products->method('findWithExternalIds')->willReturn($product);

        $this->images = $this->createMock(ProductImageStorageInterface::class);
        $this->images->method('storeUploaded')->willThrowException(new InvalidImageException('inválida'));
        $this->images->expects($this->never())->method('remove');

        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->em->expects($this->never())->method('flush');

        try {
            $this->service()->setImage(7, new UploadedFile(__FILE__, 'foto.png', 'image/png', null, true));
            self::fail('Era esperada InvalidImageException.');
        } catch (InvalidImageException) {
            self::assertSame(self::IMAGE, $product->getImageUrl());
        }
    }

    /**
     * Falha ao gravar no banco depois de o arquivo novo ser salvo.
     * Espera o arquivo novo apagado (para não sobrar lixo no disco) e a imagem antiga mantida.
     */
    public function testSetImageDiscardsNewFileWhenSaveFails(): void
    {
        $this->products->method('findWithExternalIds')->willReturn(self::product());

        $this->images = $this->createMock(ProductImageStorageInterface::class);
        $this->images->method('storeUploaded')->willReturn(self::NEW_IMAGE);
        $this->images->expects($this->once())->method('remove')->with(self::NEW_IMAGE);

        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->em->expects($this->once())->method('flush')->willThrowException(new \RuntimeException('banco fora do ar'));

        $this->expectException(\RuntimeException::class);

        $this->service()->setImage(7, new UploadedFile(__FILE__, 'foto.png', 'image/png', null, true));
    }

    /**
     * Envio de imagem para produto inexistente.
     * Espera ProductNotFoundException e nenhum arquivo gravado.
     */
    public function testSetImageThrowsWhenProductDoesNotExist(): void
    {
        $this->products->method('findWithExternalIds')->willReturn(null);

        $this->images = $this->createMock(ProductImageStorageInterface::class);
        $this->images->expects($this->never())->method('storeUploaded');

        $this->expectException(ProductNotFoundException::class);

        $this->service()->setImage(99, new UploadedFile(__FILE__, 'foto.png', 'image/png', null, true));
    }

    /**
     * Remoção da imagem de um produto que tem uma.
     * Espera a imagem limpa no produto e o arquivo apagado.
     */
    public function testRemoveImageDeletesFile(): void
    {
        $product = self::product();
        $this->products->method('findWithExternalIds')->willReturn($product);

        $this->images = $this->createMock(ProductImageStorageInterface::class);
        $this->images->expects($this->once())->method('remove')->with(self::IMAGE);

        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->em->expects($this->once())->method('flush');

        $output = $this->service()->removeImage(7);

        self::assertNull($output->imageUrl);
        self::assertNull($product->getImageUrl());
    }

    /**
     * Remoção da imagem de produto inexistente.
     * Espera ProductNotFoundException.
     */
    public function testRemoveImageThrowsWhenProductDoesNotExist(): void
    {
        $this->products->method('findWithExternalIds')->willReturn(null);

        $this->expectException(ProductNotFoundException::class);

        $this->service()->removeImage(99);
    }

    // =========================================================================
    // Auxiliares
    // =========================================================================

    private function service(): ProductService
    {
        return new ProductService($this->em, $this->products, $this->purchaseItems, $this->images);
    }

    private static function product(): Product
    {
        $product = (new Product())->setName('Chuteira de Campo')->setInternalCode('CHU-001')->setImageUrl(self::IMAGE);
        $product->onPrePersist();

        return $product;
    }
}
