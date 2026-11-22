<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Dto\LoginInput;
use App\Entity\ApiToken;
use App\Entity\User;
use App\Exception\InvalidCredentialsException;
use App\Repository\ApiTokenRepository;
use App\Repository\UserRepository;
use App\Service\AuthService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Testes unitários do AuthService (login e logout).
 *
 * O que é testado:
 * - login(): senha certa emite token (só o hash vai para o banco) com a validade configurada;
 *   e-mail inexistente ou senha errada viram InvalidCredentialsException;
 * - logout(): o token informado é apagado; token desconhecido é ignorado.
 *
 * Como é testado:
 * - Teste unitário puro: sem Symfony e sem banco. Repositórios e hasher são mocks/stubs.
 */
final class AuthServiceTest extends TestCase
{
    private const TTL = 3600;

    public function testLoginIssuesTokenAndStoresOnlyItsHash(): void
    {
        $user = self::user();

        $users = $this->createStub(UserRepository::class);
        $users->method('findOneByEmail')->willReturn($user);

        $hasher = $this->createStub(UserPasswordHasherInterface::class);
        $hasher->method('isPasswordValid')->willReturn(true);

        $saved = null;
        $tokens = $this->createMock(ApiTokenRepository::class);
        $tokens->expects($this->once())->method('deleteExpired');
        $tokens->expects($this->once())->method('save')->willReturnCallback(static function (ApiToken $token) use (&$saved): void {
            $saved = $token;
        });

        $before = new \DateTimeImmutable();
        $output = (new AuthService($users, $tokens, $hasher, self::TTL))->login(new LoginInput('admin@cbf.com.br', 'segredo'));

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $output->token);
        self::assertSame('Bearer', $output->tokenType);
        self::assertSame('admin@cbf.com.br', $output->user->email);

        self::assertInstanceOf(ApiToken::class, $saved);
        self::assertSame($user, $saved->getUser());
        self::assertSame(hash('sha256', $output->token), $saved->getTokenHash());
        self::assertNotSame($output->token, $saved->getTokenHash());
        self::assertEqualsWithDelta($before->getTimestamp() + self::TTL, $saved->getExpiresAt()->getTimestamp(), 2);
    }

    public function testLoginWithWrongPasswordFails(): void
    {
        $users = $this->createStub(UserRepository::class);
        $users->method('findOneByEmail')->willReturn(self::user());

        $hasher = $this->createStub(UserPasswordHasherInterface::class);
        $hasher->method('isPasswordValid')->willReturn(false);

        $tokens = $this->createMock(ApiTokenRepository::class);
        $tokens->expects($this->never())->method('save');

        $this->expectException(InvalidCredentialsException::class);

        (new AuthService($users, $tokens, $hasher, self::TTL))->login(new LoginInput('admin@cbf.com.br', 'errada'));
    }

    /**
     * E-mail sem conta: também deve calcular um hash (mesmo tempo de resposta) e falhar com a mesma exceção.
     */
    public function testLoginWithUnknownEmailFailsAfterHashing(): void
    {
        $users = $this->createStub(UserRepository::class);
        $users->method('findOneByEmail')->willReturn(null);

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->expects($this->once())->method('hashPassword')->willReturn('hash');

        $tokens = $this->createMock(ApiTokenRepository::class);
        $tokens->expects($this->never())->method('save');

        $this->expectException(InvalidCredentialsException::class);
        $this->expectExceptionMessage('E-mail ou senha inválidos.');

        (new AuthService($users, $tokens, $hasher, self::TTL))->login(new LoginInput('ninguem@cbf.com.br', 'x'));
    }

    public function testLogoutRemovesToken(): void
    {
        $token = new ApiToken(self::user(), ApiToken::hash('abc'), new \DateTimeImmutable('+1 hour'));

        $tokens = $this->createMock(ApiTokenRepository::class);
        $tokens->expects($this->once())->method('findOneByPlainToken')->with('abc')->willReturn($token);
        $tokens->expects($this->once())->method('remove')->with($this->identicalTo($token));

        (new AuthService($this->createStub(UserRepository::class), $tokens, $this->createStub(UserPasswordHasherInterface::class), self::TTL))
            ->logout('abc');
    }

    public function testLogoutWithUnknownTokenDoesNothing(): void
    {
        $tokens = $this->createMock(ApiTokenRepository::class);
        $tokens->method('findOneByPlainToken')->willReturn(null);
        $tokens->expects($this->never())->method('remove');

        (new AuthService($this->createStub(UserRepository::class), $tokens, $this->createStub(UserPasswordHasherInterface::class), self::TTL))
            ->logout('desconhecido');
    }

    private static function user(): User
    {
        return (new User())->setName('Administrador')->setEmail('admin@cbf.com.br')->setPassword('hash');
    }
}
