<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\LoginInput;
use App\Dto\LoginOutput;
use App\Exception\InvalidCredentialsException;

interface AuthServiceInterface
{
    /**
     * Confere e-mail e senha e emite um novo token de acesso.
     *
     * @throws InvalidCredentialsException e-mail inexistente ou senha errada
     */
    public function login(LoginInput $input): LoginOutput;

    /**
     * Invalida o token informado. Token inexistente é ignorado (logout é idempotente).
     */
    public function logout(string $plainToken): void;
}
