<?php

declare(strict_types=1);

namespace App\Security;

use App\Repository\ApiTokenRepository;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/**
 * Valida o token do cabeçalho "Authorization: Bearer <token>" (configurado em security.yaml).
 *
 * O Symfony chama este handler em toda requisição /api que traz o cabeçalho.
 * Token inexistente ou vencido → BadCredentialsException → 401 (ver ApiAuthenticationErrorHandler).
 */
final class ApiTokenHandler implements AccessTokenHandlerInterface
{
    public function __construct(
        private readonly ApiTokenRepository $apiTokenRepository,
    ) {
    }

    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        $token = $this->apiTokenRepository->findOneByPlainToken($accessToken);

        if (null === $token || $token->isExpired()) {
            throw new BadCredentialsException('Token inválido ou expirado.');
        }

        $user = $token->getUser();

        return new UserBadge($user->getUserIdentifier(), static fn () => $user);
    }
}
