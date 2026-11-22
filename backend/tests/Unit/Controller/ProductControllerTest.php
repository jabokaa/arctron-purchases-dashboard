<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Dto\ProductFilterInput;
use App\Dto\ProductListOutput;
use App\Dto\ProductOutput;
use App\Dto\ProductSaveInput;
use App\Entity\Product;
use App\Exception\DuplicateProductCodeException;
use App\Exception\ProductHasSalesException;
use App\Exception\ProductNotFoundException;
use App\Service\ProductServiceInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Testes do CRUD de produtos (ProductController): GET/POST /api/products e GET/PUT/DELETE /api/products/{id}.
 *
 * O que é testado: se cada rota aceita os dados corretos, recusa os errados com 422 (um campo por vez),
 * chama o service com os valores certos e converte as respostas e os erros do service no status HTTP esperado
 * (201, 204, 404, 409).
 *
 * Como é testado:
 * - O ProductService é trocado por um MOCK: nada é gravado no banco e o teste foca só no controller.
 * - Nos casos de erro de validação, o mock garante que o service NUNCA é chamado.
 */
final class ProductControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    private ProductServiceInterface&MockObject $productService;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        // Troca o service real pelo mock dentro do container do Symfony
        $this->productService = $this->createMock(ProductServiceInterface::class);
        static::getContainer()->set(ProductServiceInterface::class, $this->productService);
    }

    // =========================================================================
    // GET /api/products (lista)
    // =========================================================================

    /**
     * Lista sem parâmetros.
     * Espera 200, os filtros padrão (sem busca, página 1, 20 por página) e a resposta do service em JSON.
     */
    public function testListsWithDefaults(): void
    {
        $this->productService->expects($this->once())
            ->method('list')
            ->with($this->callback(static fn (ProductFilterInput $filter): bool => null === $filter->search && 1 === $filter->page && 20 === $filter->perPage))
            ->willReturn(new ProductListOutput(
                [ProductOutput::fromEntity(self::product())],
                ['page' => 1, 'per_page' => 20, 'total' => 1, 'total_pages' => 1],
            ));

        $response = $this->request('GET', '/api/products');

        self::assertSame(200, $response['status']);
        self::assertSame(['page' => 1, 'per_page' => 20, 'total' => 1, 'total_pages' => 1], $response['body']['meta']);
        self::assertSame('Chuteira de Campo', $response['body']['data'][0]['name']);
        self::assertSame('https://exemplo.com/chuteira.jpg', $response['body']['data'][0]['image_url']);
    }

    /**
     * Lista com busca e paginação.
     * Espera que os parâmetros (snake_case) cheguem no filtro (camelCase), com números convertidos.
     */
    public function testMapsListParameters(): void
    {
        $this->productService->expects($this->once())
            ->method('list')
            ->with($this->callback(static fn (ProductFilterInput $filter): bool => 'chuteira' === $filter->search && 2 === $filter->page && 5 === $filter->perPage))
            ->willReturn(new ProductListOutput([], ['page' => 2, 'per_page' => 5, 'total' => 0, 'total_pages' => 0]));

        self::assertSame(200, $this->request('GET', '/api/products?search=chuteira&page=2&per_page=5')['status']);
    }

    /**
     * Para cada parâmetro inválido da lista, espera 422 com a mensagem certa e o service nunca chamado.
     */
    #[DataProvider('invalidListParameterProvider')]
    public function testRejectsInvalidListParameter(string $queryString, string $field, string $message): void
    {
        $this->productService->expects($this->never())->method('list');

        $response = $this->request('GET', '/api/products?'.$queryString);

        self::assertSame(422, $response['status']);
        self::assertContains(['field' => $field, 'message' => $message], $response['body']['violations']);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function invalidListParameterProvider(): iterable
    {
        // page começa em 1
        yield 'page zero' => ['page=0', 'page', 'A página deve ser maior que zero.'];

        // per_page tem no máximo 100
        yield 'per_page acima do máximo' => ['per_page=101', 'per_page', 'A quantidade por página deve estar entre 1 e 100.'];

        // busca tem no máximo 255 caracteres
        yield 'busca longa demais' => ['search='.str_repeat('a', 256), 'search', 'A busca deve ter no máximo 255 caracteres.'];
    }

    // =========================================================================
    // GET /api/products/{id} (detalhe)
    // =========================================================================

    /**
     * Produto existente.
     * Espera 200 com todos os campos, inclusive a imagem e os códigos nos canais.
     */
    public function testShowsProduct(): void
    {
        $this->productService->expects($this->once())->method('find')->with(7)->willReturn(ProductOutput::fromEntity(self::product()));

        $response = $this->request('GET', '/api/products/7');

        self::assertSame(200, $response['status']);
        self::assertSame(
            ['name' => 'Chuteira de Campo', 'internal_code' => 'CHU-001', 'image_url' => 'https://exemplo.com/chuteira.jpg', 'external_ids' => []],
            array_intersect_key($response['body'], ['name' => 1, 'internal_code' => 1, 'image_url' => 1, 'external_ids' => 1]),
        );
    }

    /**
     * Produto inexistente: o service lança ProductNotFoundException.
     * Espera 404 com a mensagem da exceção.
     */
    public function testShowReturnsNotFound(): void
    {
        $this->productService->expects($this->once())->method('find')->with(99)->willThrowException(new ProductNotFoundException(99));

        $response = $this->request('GET', '/api/products/99');

        self::assertSame(404, $response['status']);
        self::assertSame('Produto 99 não encontrado.', $response['body']['detail']);
    }

    /**
     * Id em texto: não bate com a rota.
     * Espera 404 de rota e o service nunca chamado.
     */
    public function testShowRejectsNonNumericId(): void
    {
        $this->productService->expects($this->never())->method('find');

        self::assertSame(404, $this->request('GET', '/api/products/abc')['status']);
    }

    // =========================================================================
    // POST /api/products (criar)
    // =========================================================================

    /**
     * Produto válido.
     * Espera 201, o service chamado com os campos convertidos (snake_case → camelCase) e o produto criado na resposta.
     */
    public function testCreatesProduct(): void
    {
        $this->productService->expects($this->once())
            ->method('create')
            ->with($this->callback(static fn (ProductSaveInput $input): bool => 'Chuteira de Campo' === $input->name
                && 'CHU-001' === $input->internalCode
                && 'https://exemplo.com/chuteira.jpg' === $input->imageUrl))
            ->willReturn(ProductOutput::fromEntity(self::product()));

        $response = $this->request('POST', '/api/products', self::validPayload());

        self::assertSame(201, $response['status']);
        self::assertSame('CHU-001', $response['body']['internal_code']);
    }

    /**
     * Só o nome (código interno e imagem são opcionais).
     * Espera 201 e os campos opcionais nulos.
     */
    public function testCreatesProductWithOnlyName(): void
    {
        $this->productService->expects($this->once())
            ->method('create')
            ->with($this->callback(static fn (ProductSaveInput $input): bool => null === $input->internalCode && null === $input->imageUrl))
            ->willReturn(ProductOutput::fromEntity(self::product()));

        self::assertSame(201, $this->request('POST', '/api/products', ['name' => 'Bola'])['status']);
    }

    /**
     * Para cada caso do provider, estraga UM campo de um produto válido e espera 422
     * com a mensagem certa no campo certo. O service não pode ser chamado.
     *
     * @param array<string, mixed> $changes
     */
    #[DataProvider('invalidFieldProvider')]
    public function testCreateRejectsInvalidField(array $changes, string $field, string $message): void
    {
        $this->productService->expects($this->never())->method('create');

        $response = $this->request('POST', '/api/products', array_merge(self::validPayload(), $changes));

        self::assertSame(422, $response['status']);
        self::assertContains(['field' => $field, 'message' => $message], $response['body']['violations']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string}>
     */
    public static function invalidFieldProvider(): iterable
    {
        $invalidUrl = 'A imagem deve ser um endereço válido, começando com http:// ou https://.';

        // name é obrigatório
        yield 'nome vazio' => [['name' => ''], 'name', 'O nome do produto é obrigatório.'];

        // name ausente
        yield 'nome ausente' => [['name' => null], 'name', 'O nome do produto é obrigatório.'];

        // name tem no máximo 255 caracteres
        yield 'nome longo demais' => [['name' => str_repeat('a', 256)], 'name', 'O nome do produto deve ter no máximo 255 caracteres.'];

        // internal_code tem no máximo 100 caracteres
        yield 'código longo demais' => [['internal_code' => str_repeat('A', 101)], 'internal_code', 'O código interno deve ter no máximo 100 caracteres.'];

        // image_url precisa ser um endereço
        yield 'imagem que não é endereço' => [['image_url' => 'chuteira.jpg'], 'image_url', $invalidUrl];

        // image_url só aceita http e https
        yield 'imagem com outro protocolo' => [['image_url' => 'ftp://exemplo.com/chuteira.jpg'], 'image_url', $invalidUrl];

        // image_url precisa de domínio completo (com .com, .br...)
        yield 'imagem sem domínio' => [['image_url' => 'http://localhost/chuteira.jpg'], 'image_url', 'A imagem deve ter um domínio completo (ex.: exemplo.com.br).'];

        // nome com tipo errado
        yield 'nome numérico' => [['name' => 123], 'name', 'Tipo inválido: esperado texto.'];
    }

    /**
     * Código interno já usado por outro produto: o service lança DuplicateProductCodeException.
     * Espera 409 com a mensagem da exceção.
     */
    public function testCreateReturnsConflictForDuplicateCode(): void
    {
        $this->productService->expects($this->once())->method('create')->willThrowException(new DuplicateProductCodeException('CHU-001'));

        $response = $this->request('POST', '/api/products', self::validPayload());

        self::assertSame(409, $response['status']);
        self::assertSame('Já existe um produto com o código interno "CHU-001".', $response['body']['detail']);
    }

    // =========================================================================
    // PUT /api/products/{id} (atualizar)
    // =========================================================================

    /**
     * Atualização válida.
     * Espera 200 e o service chamado com o id e os dados.
     */
    public function testUpdatesProduct(): void
    {
        $this->productService->expects($this->once())
            ->method('update')
            ->with(7, $this->callback(static fn (ProductSaveInput $input): bool => 'Chuteira de Campo' === $input->name))
            ->willReturn(ProductOutput::fromEntity(self::product()));

        self::assertSame(200, $this->request('PUT', '/api/products/7', self::validPayload())['status']);
    }

    /**
     * Atualização com dado inválido.
     * Espera 422 e o service nunca chamado.
     */
    public function testUpdateRejectsInvalidData(): void
    {
        $this->productService->expects($this->never())->method('update');

        self::assertSame(422, $this->request('PUT', '/api/products/7', ['name' => ''])['status']);
    }

    /**
     * Atualização de produto inexistente.
     * Espera 404.
     */
    public function testUpdateReturnsNotFound(): void
    {
        $this->productService->expects($this->once())->method('update')->willThrowException(new ProductNotFoundException(99));

        self::assertSame(404, $this->request('PUT', '/api/products/99', self::validPayload())['status']);
    }

    /**
     * Atualização para um código interno de outro produto.
     * Espera 409.
     */
    public function testUpdateReturnsConflictForDuplicateCode(): void
    {
        $this->productService->expects($this->once())->method('update')->willThrowException(new DuplicateProductCodeException('CHU-001'));

        self::assertSame(409, $this->request('PUT', '/api/products/7', self::validPayload())['status']);
    }

    // =========================================================================
    // DELETE /api/products/{id} (remover)
    // =========================================================================

    /**
     * Remoção de produto sem vendas.
     * Espera 204 sem corpo e o service chamado com o id.
     */
    public function testDeletesProduct(): void
    {
        $this->productService->expects($this->once())->method('delete')->with(7);

        $this->client->request('DELETE', '/api/products/7');

        self::assertSame(204, $this->client->getResponse()->getStatusCode());
        self::assertSame('', $this->client->getResponse()->getContent());
    }

    /**
     * Remoção de produto inexistente.
     * Espera 404.
     */
    public function testDeleteReturnsNotFound(): void
    {
        $this->productService->expects($this->once())->method('delete')->willThrowException(new ProductNotFoundException(99));

        self::assertSame(404, $this->request('DELETE', '/api/products/99')['status']);
    }

    /**
     * Remoção de produto que já foi vendido: o service lança ProductHasSalesException.
     * Espera 409 com a mensagem da exceção.
     */
    public function testDeleteReturnsConflictForProductWithSales(): void
    {
        $this->productService->expects($this->once())->method('delete')->willThrowException(new ProductHasSalesException(7));

        $response = $this->request('DELETE', '/api/products/7');

        self::assertSame(409, $response['status']);
        self::assertSame('O produto 7 já foi vendido e não pode ser removido.', $response['body']['detail']);
    }

    // =========================================================================
    // Auxiliares
    // =========================================================================

    /**
     * @return array<string, mixed>
     */
    private static function validPayload(): array
    {
        return ['name' => 'Chuteira de Campo', 'internal_code' => 'CHU-001', 'image_url' => 'https://exemplo.com/chuteira.jpg'];
    }

    private static function product(): Product
    {
        $product = (new Product())->setName('Chuteira de Campo')->setInternalCode('CHU-001')->setImageUrl('https://exemplo.com/chuteira.jpg');
        $product->onPrePersist();

        return $product;
    }

    /**
     * @param array<string, mixed>|null $payload
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private function request(string $method, string $url, ?array $payload = null): array
    {
        $this->client->request(
            $method,
            $url,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: null === $payload ? null : json_encode($payload, \JSON_THROW_ON_ERROR),
        );

        return [
            'status' => $this->client->getResponse()->getStatusCode(),
            'body' => json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR),
        ];
    }
}
