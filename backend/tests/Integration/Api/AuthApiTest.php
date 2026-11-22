<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\User;
use App\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Testes de integração da autenticação: POST /api/auth/login, GET /api/auth/me e POST /api/auth/logout.
 *
 * O que é testado: o fluxo completo que o front faz (login → usar o token → logout),
 * passando pelo firewall do Symfony, ApiTokenHandler, AuthService e PostgreSQL. Nada é mockado.
 *
 * Como é testado: antes de cada teste o banco é esvaziado e um usuário é cadastrado com senha conhecida.
 */
final class AuthApiTest extends IntegrationTestCase
{
    private const EMAIL = 'admin@cbf.com.br';
    private const PASSWORD = 'senha-secreta';

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
     * Login certo (e-mail em maiúsculas também vale): 200 com token, validade e usuário (sem senha).
     * O banco guarda só o hash do token.
     */
    public function testLoginReturnsToken(): void
    {
        $response = $this->login('ADMIN@cbf.com.br', self::PASSWORD);

        self::assertSame(200, $response['status']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $response['body']['token']);
        self::assertSame('Bearer', $response['body']['token_type']);
        self::assertArrayHasKey('expires_at', $response['body']);
        self::assertSame(['name' => 'Administrador CBF', 'email' => self::EMAIL, 'roles' => ['ROLE_USER']], array_diff_key($response['body']['user'], ['id' => true]));

        self::assertSame(1, $this->countRows('api_tokens'));
        self::assertSame(hash('sha256', $response['body']['token']), $this->connection()->fetchOne('SELECT token_hash FROM api_tokens'));
    }

    public function testLoginWithWrongPasswordReturns401(): void
    {
        $response = $this->login(self::EMAIL, 'errada');

        self::assertSame(401, $response['status']);
        self::assertSame('E-mail ou senha inválidos.', $response['body']['detail']);
        self::assertSame(0, $this->countRows('api_tokens'));
    }

    /** E-mail sem conta: mesma resposta da senha errada, para não revelar quem tem conta */
    public function testLoginWithUnknownEmailReturns401(): void
    {
        $response = $this->login('ninguem@cbf.com.br', self::PASSWORD);

        self::assertSame(401, $response['status']);
        self::assertSame('E-mail ou senha inválidos.', $response['body']['detail']);
    }

    public function testLoginWithoutFieldsReturns422(): void
    {
        $response = $this->login('', '');

        self::assertSame(422, $response['status']);
        self::assertSame(['email', 'password'], array_column($response['body']['violations'], 'field'));
    }

    public function testMeReturnsLoggedUser(): void
    {
        $token = $this->login(self::EMAIL, self::PASSWORD)['body']['token'];

        $response = $this->request('GET', '/api/auth/me', $token);

        self::assertSame(200, $response['status']);
        self::assertSame(self::EMAIL, $response['body']['email']);
    }

    public function testMeWithoutTokenReturns401(): void
    {
        $response = $this->request('GET', '/api/auth/me');

        self::assertSame(401, $response['status']);
        self::assertSame('Bearer', $this->client->getResponse()->headers->get('WWW-Authenticate'));
    }

    public function testMeWithInvalidTokenReturns401(): void
    {
        $response = $this->request('GET', '/api/auth/me', 'token-que-nao-existe');

        self::assertSame(401, $response['status']);
        self::assertSame('Token inválido ou expirado. Faça login novamente.', $response['body']['detail']);
    }

    public function testExpiredTokenReturns401(): void
    {
        $token = $this->login(self::EMAIL, self::PASSWORD)['body']['token'];
        $this->connection()->executeStatement("UPDATE api_tokens SET expires_at = NOW() - INTERVAL '1 minute'");

        self::assertSame(401, $this->request('GET', '/api/auth/me', $token)['status']);
    }

    /** Depois do logout o mesmo token não vale mais */
    public function testLogoutInvalidatesToken(): void
    {
        $token = $this->login(self::EMAIL, self::PASSWORD)['body']['token'];

        self::assertSame(204, $this->request('POST', '/api/auth/logout', $token)['status']);
        self::assertSame(0, $this->countRows('api_tokens'));
        self::assertSame(401, $this->request('GET', '/api/auth/me', $token)['status']);
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function login(string $email, string $password): array
    {
        $this->client->request(
            'POST',
            '/api/auth/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => $email, 'password' => $password], \JSON_THROW_ON_ERROR),
        );

        return $this->response();
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function request(string $method, string $uri, ?string $token = null): array
    {
        $this->client->request($method, $uri, server: null === $token ? [] : ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        return $this->response();
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function response(): array
    {
        $content = (string) $this->client->getResponse()->getContent();

        return [
            'status' => $this->client->getResponse()->getStatusCode(),
            'body' => '' === $content ? [] : json_decode($content, true, flags: \JSON_THROW_ON_ERROR),
        ];
    }
}
