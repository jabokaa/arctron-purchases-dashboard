<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Corpo do POST /api/auth/login: {"email": "...", "password": "..."}.
 *
 * As propriedades aceitam null para que campos ausentes cheguem até a validação
 * e voltem como erro 422 com mensagem clara.
 */
final class LoginInput
{
    public function __construct(
        #[Assert\NotBlank(message: 'O e-mail é obrigatório.')]
        #[Assert\Email(message: 'O e-mail {{ value }} não é válido.')]
        public readonly ?string $email = null,

        #[Assert\NotBlank(message: 'A senha é obrigatória.')]
        public readonly ?string $password = null,
    ) {}
}
