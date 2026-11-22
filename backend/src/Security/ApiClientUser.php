<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * "Usuário" de quem acessa a API com o token fixo do .env (API_ACCESS_TOKEN), por exemplo outro sistema.
 * Não é uma pessoa cadastrada: não existe no banco e não tem as rotas de sessão (/api/auth/me e /logout).
 */
final class ApiClientUser implements UserInterface
{
    public const ROLE = 'ROLE_API_CLIENT';
    public const IDENTIFIER = 'api-access-token';

    public function getRoles(): array
    {
        return [self::ROLE];
    }

    public function getUserIdentifier(): string
    {
        return self::IDENTIFIER;
    }
}
