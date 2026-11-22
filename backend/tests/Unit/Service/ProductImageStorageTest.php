<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Exception\InvalidImageException;
use App\Service\ProductImageStorage;
use App\Tests\Support\TestImage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Testes do ProductImageStorage (grava e apaga as imagens dos produtos no disco).
 *
 * O que é testado: o tipo do arquivo é conferido pelo CONTEÚDO (não pelo nome nem pelo tipo informado),
 * o limite de 2 MB, o nome aleatório do arquivo gravado e a remoção, que só apaga arquivos desta pasta.
 *
 * Como é testado: unitário puro, usando uma pasta temporária que é apagada no fim de cada teste.
 */
final class ProductImageStorageTest extends TestCase
{
    private string $directory;

    private ProductImageStorage $storage;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/product-images-'.bin2hex(random_bytes(4));
        $this->storage = new ProductImageStorage($this->directory);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->directory.'/*') ?: []);
        @rmdir($this->directory);
    }

    /**
     * PNG válido.
     * Espera o arquivo gravado (a pasta é criada se não existir) e o caminho público com nome aleatório e extensão .png.
     */
    public function testStoresPng(): void
    {
        $path = $this->storage->storeContents(TestImage::pngBytes());

        self::assertMatchesRegularExpression('#^/uploads/products/[a-f0-9]{32}\.png$#', $path);
        self::assertFileExists($this->directory.'/'.basename($path));
        self::assertTrue($this->storage->isLocal($path));
    }

    /**
     * Dois envios do mesmo arquivo.
     * Espera nomes diferentes (um envio nunca sobrescreve o outro).
     */
    public function testUsesRandomNames(): void
    {
        $png = TestImage::pngBytes();

        self::assertNotSame($this->storage->storeContents($png), $this->storage->storeContents($png));
    }

    /**
     * Envio pelo UploadedFile, com nome e tipo enganosos (.php / text/html).
     * Espera o arquivo gravado com a extensão do tipo real (png), nunca a do nome enviado.
     */
    public function testStoreUploadedUsesRealType(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($tmp, TestImage::pngBytes());

        $path = $this->storage->storeUploaded(new UploadedFile($tmp, 'shell.php', 'text/html', null, true));

        self::assertStringEndsWith('.png', $path);
    }

    /**
     * Conteúdo que não é imagem (texto, HTML com script, SVG).
     * Espera InvalidImageException e nada gravado.
     */
    public function testRejectsNonImageContent(): void
    {
        foreach (['texto simples', '<?php echo 1;', '<html><script>alert(1)</script></html>', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'] as $contents) {
            try {
                $this->storage->storeContents($contents);
                self::fail('Era esperada InvalidImageException para: '.$contents);
            } catch (InvalidImageException $e) {
                self::assertSame('O arquivo deve ser uma imagem JPG, PNG, WebP ou GIF.', $e->getMessage());
            }
        }

        self::assertDirectoryDoesNotExist($this->directory);
    }

    /**
     * Arquivo vazio e arquivo maior que 2 MB.
     * Espera InvalidImageException nos dois.
     */
    public function testRejectsEmptyAndTooLargeFiles(): void
    {
        foreach (['', TestImage::pngBytes().str_repeat("\0", ProductImageStorage::MAX_SIZE)] as $contents) {
            try {
                $this->storage->storeContents($contents);
                self::fail('Era esperada InvalidImageException.');
            } catch (InvalidImageException $e) {
                self::assertSame('A imagem deve ter no máximo 2 MB.', $e->getMessage());
            }
        }
    }

    /**
     * Remoção de um arquivo gravado.
     * Espera o arquivo apagado.
     */
    public function testRemovesStoredFile(): void
    {
        $path = $this->storage->storeContents(TestImage::pngBytes());

        $this->storage->remove($path);

        self::assertFileDoesNotExist($this->directory.'/'.basename($path));
    }

    /**
     * Valores que não são arquivos desta pasta: nulo, endereço externo e tentativas de sair da pasta (../).
     * Espera que nada seja apagado e que não aconteça erro.
     */
    public function testRemoveIgnoresForeignValues(): void
    {
        $outside = tempnam(sys_get_temp_dir(), 'keep');

        foreach ([null, '', 'https://exemplo.com/a.jpg', '/uploads/products/../../'.basename($outside), '/uploads/products/nome-invalido.jpg'] as $value) {
            $this->storage->remove($value);
            self::assertFalse($this->storage->isLocal($value), (string) $value);
        }

        self::assertFileExists($outside);
        unlink($outside);
    }
}
