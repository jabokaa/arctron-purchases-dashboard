<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\ApiToken;
use App\Entity\User;
use App\Repository\ApiTokenRepository;
use App\Security\ApiTokenHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;

/**
 * Testes unitários do ApiTokenHandler (validação do token do cabeçalho Authorization).
 *
 * Token válido → identifica o dono do token; token inexistente ou vencido → BadCredentialsException (401).
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

    private static function handlerReturning(?ApiToken $token): ApiTokenHandler
    {
        $repository = self::createStub(ApiTokenRepository::class);
        $repository->method('findOneByPlainToken')->willReturn($token);

        return new ApiTokenHandler($repository);
    }
}
