<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\User;
use App\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Testes do controle de acesso das rotas /api (access_control do security.yaml).
 *
 * O que é testado: todas as rotas /api exigem o token do login, exceto o próprio login e o webhook do
 * WooCommerce (que se autentica pela assinatura do corpo). Sem token, com token inválido ou depois do logout
 * a resposta é 401 em JSON; com um token válido a rota responde normalmente.
 *
 * Como é testado: fluxo completo, sem mocks, no banco de teste. O cliente começa SEM token.
 */
final class ApiAccessApiTest extends IntegrationTestCase
{
    private const EMAIL = 'admin@cbf.com.br';
    private const PASSWORD = 'senha-secreta';

    protected bool $authenticated = false;

    protected function setUp(): void
    {
        parent::setUp();

        $container = static::getContainer();
        $user = (new User())->setName('Administrador CBF')->setEmail(self::EMAIL);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));

        $em = $container->get(EntityManagerInterface::class);
        $em->persist($user);
        $em->flush();
    }

    /**
     * Cada rota protegida, chamada sem token.
     * Espera 401 com o aviso de que é preciso estar autenticado (e nada gravado no banco).
     */
    #[DataProvider('protectedRoutesProvider')]
    public function testProtectedRoutesRequireAuthentication(string $method, string $url): void
    {
        $response = $this->call($method, $url);

        self::assertSame(401, $response['status'], "$method $url");
        self::assertSame('Não autorizado', $response['body']['title']);
        self::assertStringContainsString('É necessário estar autenticado', $response['body']['detail']);
        self::assertSame(0, $this->countRows('products') + $this->countRows('purchases'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function protectedRoutesProvider(): iterable
    {
        yield 'me' => ['GET', '/api/auth/me'];
        yield 'logout' => ['POST', '/api/auth/logout'];
        yield 'listar produtos' => ['GET', '/api/products'];
        yield 'ver produto' => ['GET', '/api/products/1'];
        yield 'criar produto' => ['POST', '/api/products'];
        yield 'atualizar produto' => ['PUT', '/api/products/1'];
        yield 'remover produto' => ['DELETE', '/api/products/1'];
        yield 'enviar imagem' => ['POST', '/api/products/1/image'];
        yield 'remover imagem' => ['DELETE', '/api/products/1/image'];
        yield 'listar compras' => ['GET', '/api/purchases'];
        yield 'ver compra' => ['GET', '/api/purchases/1'];
        yield 'criar compra' => ['POST', '/api/purchases'];
        yield 'relatório resumo' => ['GET', '/api/reports/summary'];
        yield 'relatório evolução' => ['GET', '/api/reports/timeline'];
        yield 'relatório clientes' => ['GET', '/api/reports/customers'];
        yield 'relatório produtos' => ['GET', '/api/reports/products'];
    }

    /**
     * Login sem token.
     * Espera que a rota seja alcançada (422 de validação, não 401 de acesso) e que o login certo devolva o token.
     */
    public function testLoginIsPublic(): void
    {
        self::assertSame(422, $this->call('POST', '/api/auth/login', [])['status']);

        $response = $this->call('POST', '/api/auth/login', ['email' => self::EMAIL, 'password' => self::PASSWORD]);
        self::assertSame(200, $response['status']);
        self::assertArrayHasKey('token', $response['body']);
    }

    /**
     * Webhook do WooCommerce sem token de login e sem assinatura.
     * Espera 401 pela ASSINATURA inválida (a rota foi alcançada: ela não exige o token de login).
     */
    public function testWebhookIsReachableWithoutLoginToken(): void
    {
        $response = $this->call('POST', '/api/webhooks/woocommerce', ['id' => 1]);

        self::assertSame(401, $response['status']);
        self::assertSame('Assinatura do webhook inválida.', $response['body']['detail']);
    }

    /**
     * Token que não existe.
     * Espera 401 com o aviso de token inválido.
     */
    public function testInvalidTokenIsRejected(): void
    {
        $response = $this->call('GET', '/api/products', token: str_repeat('a', 64));

        self::assertSame(401, $response['status']);
        self::assertSame('Token inválido ou expirado. Faça login novamente.', $response['body']['detail']);
    }

    /**
     * Login → usar o token nas rotas → logout → o mesmo token deixa de valer.
     * Espera 200 nas rotas de produtos, compras e relatórios com o token, e 401 depois do logout.
     */
    public function testValidTokenGrantsAccessUntilLogout(): void
    {
        $token = $this->call('POST', '/api/auth/login', ['email' => self::EMAIL, 'password' => self::PASSWORD])['body']['token'];

        foreach (['/api/products', '/api/purchases', '/api/reports/summary', '/api/reports/timeline', '/api/reports/customers', '/api/reports/products', '/api/auth/me'] as $url) {
            self::assertSame(200, $this->call('GET', $url, token: $token)['status'], $url);
        }

        self::assertSame(204, $this->call('POST', '/api/auth/logout', token: $token)['status']);
        self::assertSame(401, $this->call('GET', '/api/products', token: $token)['status']);
    }

    /**
     * @param array<string, mixed>|null $payload
     *
     * @return array{status: int, body: array<string, mixed>|null}
     */
    private function call(string $method, string $url, ?array $payload = null, ?string $token = null): array
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if (null !== $token) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }

        $this->client->request($method, $url, server: $server, content: null === $payload ? null : json_encode($payload, \JSON_THROW_ON_ERROR));

        return [
            'status' => $this->client->getResponse()->getStatusCode(),
            'body' => json_decode((string) $this->client->getResponse()->getContent(), true),
        ];
    }
}
