<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\ApiToken;
use App\Entity\User;
use App\Repository\ApiTokenRepository;
use App\Security\ApiClientUser;
use App\Security\ApiTokenHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;

/**
 * Testes unitários do ApiTokenHandler (validação do token do cabeçalho Authorization).
 *
 * Token de login válido → identifica o dono do token; token inexistente ou vencido → BadCredentialsException (401).
 * Token fixo do .env (API_ACCESS_TOKEN) → identifica o cliente da API, sem consultar o banco; vazio = desligado.
 * O ApiTokenRepository é um stub; o Symfony não é iniciado.
 */
final class ApiTokenHandlerTest extends TestCase
{
    public function testValidTokenIdentifiesItsUser(): void
    {
        $user = (new User())->setName('Admin')->setEmail('admin@cbf.com.br');
        $badge = self::handlerReturning(new ApiToken($user, ApiToken::hash('abc'), new \DateTimeImmutable('+1 hour')))
            ->getUserBadgeFrom('abc');

        self::assertSame('admin@cbf.com.br', $badge->getUserIdentifier());
        self::assertSame($user, $badge->getUser());
    }

    public function testUnknownTokenIsRejected(): void
    {
        $this->expectException(BadCredentialsException::class);

        self::handlerReturning(null)->getUserBadgeFrom('abc');
    }

    public function testExpiredTokenIsRejected(): void
    {
        $user = (new User())->setName('Admin')->setEmail('admin@cbf.com.br');

        $this->expectException(BadCredentialsException::class);

        self::handlerReturning(new ApiToken($user, ApiToken::hash('abc'), new \DateTimeImmutable('-1 second')))
            ->getUserBadgeFrom('abc');
    }

    /**
     * Token fixo do .env.
     * Espera o cliente da API (ROLE_API_CLIENT), sem consultar o repositório de tokens de login.
     */
    public function testConfiguredApiTokenIdentifiesApiClient(): void
    {
        $repository = $this->createMock(ApiTokenRepository::class);
        $repository->expects($this->never())->method('findOneByPlainToken');

        $badge = (new ApiTokenHandler($repository, 'token-do-env'))->getUserBadgeFrom('token-do-env');

        self::assertSame(ApiClientUser::IDENTIFIER, $badge->getUserIdentifier());
        self::assertSame([ApiClientUser::ROLE], $badge->getUser()->getRoles());
    }

    /**
     * Token diferente do configurado (mesmo parecido).
     * Espera BadCredentialsException, já que também não é um token de login.
     */
    public function testWrongApiTokenIsRejected(): void
    {
        $this->expectException(BadCredentialsException::class);

        self::handlerReturning(null, 'token-do-env')->getUserBadgeFrom('token-do-enx');
    }

    /**
     * API_ACCESS_TOKEN vazio (acesso por token fixo desligado).
     * Espera que um token "vazio" não dê acesso: cai na checagem dos tokens de login e é recusado.
     */
    public function testEmptyConfiguredTokenDisablesApiClientAccess(): void
    {
        $this->expectException(BadCredentialsException::class);

        self::handlerReturning(null, '')->getUserBadgeFrom('');
    }

    private static function handlerReturning(?ApiToken $token, string $apiAccessToken = ''): ApiTokenHandler
    {
        $repository = self::createStub(ApiTokenRepository::class);
        $repository->method('findOneByPlainToken')->willReturn($token);

        return new ApiTokenHandler($repository, $apiAccessToken);
    }
}
