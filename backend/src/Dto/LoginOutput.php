<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Resposta do login: o token (mostrado só agora, não fica guardado em texto) e o usuário.
 *
 *   {"token": "...", "token_type": "Bearer", "expires_at": "2026-11-22T20:00:00+00:00", "user": {...}}
 */
final class LoginOutput
{
    public function __construct(
        public readonly string $token,
        public readonly \DateTimeImmutable $expiresAt,
        public readonly UserOutput $user,
        public readonly string $tokenType = 'Bearer',
    ) {}
}
