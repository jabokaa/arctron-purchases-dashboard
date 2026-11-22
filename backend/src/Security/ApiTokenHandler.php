<?php

declare(strict_types=1);

namespace App\Security;

use App\Repository\ApiTokenRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/**
 * Valida o token do cabeçalho Authorization (Bearer), configurado em security.yaml.
 * Há duas formas de ter acesso à API:
 * 1. token de login (emitido pelo POST /api/auth/login e guardado no banco): identifica o usuário logado;
 * 2. token fixo do .env (API_ACCESS_TOKEN), para outros sistemas: identifica um ApiClientUser.
 * Se API_ACCESS_TOKEN estiver vazio, a segunda forma fica desligada.
 *
 * O Symfony chama este handler em toda requisição /api que traz o cabeçalho.
 * Token inexistente ou vencido → BadCredentialsException → 401 (ver ApiAuthenticationErrorHandler).
 */
final class ApiTokenHandler implements AccessTokenHandlerInterface
{
    public function __construct(
        private readonly ApiTokenRepository $apiTokenRepository,
        #[Autowire('%env(API_ACCESS_TOKEN)%')]
        private readonly string $apiAccessToken = '',
    ) {
    }

    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        // hash_equals compara em tempo constante (não vaza o token pelo tempo de resposta)
        if ('' !== $this->apiAccessToken && hash_equals($this->apiAccessToken, $accessToken)) {
            return new UserBadge(ApiClientUser::IDENTIFIER, static fn () => new ApiClientUser());
        }

        $token = $this->apiTokenRepository->findOneByPlainToken($accessToken);

        if (null === $token || $token->isExpired()) {
            throw new BadCredentialsException('Token inválido ou expirado.');
        }

        $user = $token->getUser();

        return new UserBadge($user->getUserIdentifier(), static fn () => $user);
    }
}
