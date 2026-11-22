<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Tests\Integration\IntegrationTestCase;

/**
 * Testes de integração do CRUD de produtos (/api/products), do jeito que o front usaria.
 *
 * O que é testado: o ciclo completo de um produto (criar → listar → ver → atualizar → remover)
 * passando por requisição → validação → controller → ProductService → repositório → PostgreSQL,
 * e as regras que dependem do banco: código interno único e produto vendido que não pode ser removido.
 * Nada é mockado; depois de cada requisição o teste confere o banco com SQL.
 *
 * Como é testado:
 * - O banco de teste (app_test) é criado do zero com as migrations (ver IntegrationTestCase)
 *   e esvaziado antes de cada teste.
 */
final class ProductApiTest extends IntegrationTestCase
{
    /**
     * Criar produto com nome, código interno e imagem.
     * Espera 201 com o produto criado e o registro no banco.
     */
    public function testCreatesProduct(): void
    {
        $response = $this->api('POST', '/api/products', ['name' => 'Chuteira de Campo', 'internal_code' => 'CHU-001', 'image_url' => 'https://exemplo.com/chuteira.jpg']);

        self::assertSame(201, $response['status']);
        self::assertIsInt($response['body']['id']);
        self::assertSame(['Chuteira de Campo', 'CHU-001', 'https://exemplo.com/chuteira.jpg', []], [
            $response['body']['name'], $response['body']['internal_code'], $response['body']['image_url'], $response['body']['external_ids'],
        ]);

        self::assertSame(
            ['name' => 'Chuteira de Campo', 'internal_code' => 'CHU-001', 'image_url' => 'https://exemplo.com/chuteira.jpg'],
            $this->connection()->fetchAssociative('SELECT name, internal_code, image_url FROM products'),
        );
    }

    /**
     * Ciclo completo: criar, listar com busca, ver o detalhe, atualizar e remover.
     * Espera cada etapa refletida no banco e, no fim, o produto apagado (404 no detalhe).
     */
    public function testFullLifecycle(): void
    {
        $id = $this->api('POST', '/api/products', ['name' => 'Bola de Futebol', 'internal_code' => 'BOL-001'])['body']['id'];

        $list = $this->api('GET', '/api/products?search=bola');
        self::assertSame(['Bola de Futebol'], array_column($list['body']['data'], 'name'));
        self::assertSame(1, $list['body']['meta']['total']);

        self::assertSame('BOL-001', $this->api('GET', '/api/products/'.$id)['body']['internal_code']);

        $updated = $this->api('PUT', '/api/products/'.$id, ['name' => 'Bola Oficial', 'internal_code' => 'BOL-002', 'image_url' => 'https://exemplo.com/bola.jpg']);
        self::assertSame(200, $updated['status']);
        self::assertSame(
            ['name' => 'Bola Oficial', 'internal_code' => 'BOL-002', 'image_url' => 'https://exemplo.com/bola.jpg'],
            $this->connection()->fetchAssociative('SELECT name, internal_code, image_url FROM products WHERE id = ?', [$id]),
        );

        $this->client->request('DELETE', '/api/products/'.$id);
        self::assertSame(204, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, $this->countRows('products'));

        self::assertSame(404, $this->api('GET', '/api/products/'.$id)['status']);
    }

    /**
     * Dois produtos com o mesmo código interno.
     * Espera 409 no segundo e só um produto no banco.
     */
    public function testRejectsDuplicateInternalCode(): void
    {
        $this->api('POST', '/api/products', ['name' => 'Chuteira', 'internal_code' => 'CHU-001']);

        $response = $this->api('POST', '/api/products', ['name' => 'Outra Chuteira', 'internal_code' => 'CHU-001']);

        self::assertSame(409, $response['status']);
        self::assertSame(1, $this->countRows('products'));
    }

    /**
     * Atualizar um produto para o código interno de outro.
     * Espera 409 e o produto sem alteração.
     */
    public function testUpdateRejectsCodeOfAnotherProduct(): void
    {
        $this->api('POST', '/api/products', ['name' => 'Chuteira', 'internal_code' => 'CHU-001']);
        $id = $this->api('POST', '/api/products', ['name' => 'Bola', 'internal_code' => 'BOL-001'])['body']['id'];

        self::assertSame(409, $this->api('PUT', '/api/products/'.$id, ['name' => 'Bola', 'internal_code' => 'CHU-001'])['status']);
        self::assertSame('BOL-001', $this->connection()->fetchOne('SELECT internal_code FROM products WHERE id = ?', [$id]));
    }

    /**
     * Produto criado por uma compra (POST /api/purchases) não pode ser removido: as compras guardam qual produto foi vendido.
     * Espera 409 e o produto continuando no banco.
     */
    public function testCannotDeleteProductThatWasSold(): void
    {
        $this->postPurchase([
            'reference_code' => 'PED-1',
            'purchased_at' => '2026-11-21T12:00:00Z',
            'customer' => ['name' => 'João Silva', 'email' => 'joao@email.com', 'cpf' => '529.982.247-25'],
            'products' => [['external_id' => 'CAM-001', 'name' => 'Camiseta', 'quantity' => 1, 'unit_price' => 5000]],
        ]);
        $id = (int) $this->connection()->fetchOne('SELECT id FROM products');

        $response = $this->api('DELETE', '/api/products/'.$id);

        self::assertSame(409, $response['status']);
        self::assertSame(\sprintf('O produto %d já foi vendido e não pode ser removido.', $id), $response['body']['detail']);
        self::assertSame(1, $this->countRows('products'));
    }

    /**
     * Remover produto que tem código em um canal (mas nunca foi vendido).
     * Espera 204 e o código do canal removido junto.
     */
    public function testDeleteRemovesExternalIdsToo(): void
    {
        $id = $this->api('POST', '/api/products', ['name' => 'Apito'])['body']['id'];
        $this->connection()->executeStatement(
            "INSERT INTO product_external_ids (product_id, sales_channel_id, external_id, created_at) SELECT ?, id, 'API-1', now() FROM sales_channels WHERE code = 'API'",
            [$id],
        );

        $this->client->request('DELETE', '/api/products/'.$id);

        self::assertSame(204, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, $this->countRows('product_external_ids'));
    }

    /**
     * Dados inválidos (sem nome e imagem que não é endereço).
     * Espera 422 com os dois campos e nada gravado.
     */
    public function testRejectsInvalidData(): void
    {
        $response = $this->api('POST', '/api/products', ['name' => '', 'image_url' => 'chuteira.jpg']);

        self::assertSame(422, $response['status']);
        self::assertSame(['name', 'image_url'], array_column($response['body']['violations'], 'field'));
        self::assertSame(0, $this->countRows('products'));
    }

    // =========================================================================
    // Auxiliares
    // =========================================================================

    /**
     * @param array<string, mixed>|null $payload
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private function api(string $method, string $url, ?array $payload = null): array
    {
        $this->client->request(
            $method,
            $url,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: null === $payload ? null : json_encode($payload, \JSON_THROW_ON_ERROR),
        );

        $content = (string) $this->client->getResponse()->getContent();

        return [
            'status' => $this->client->getResponse()->getStatusCode(),
            'body' => '' === $content ? [] : json_decode($content, true, flags: \JSON_THROW_ON_ERROR),
        ];
    }
}
