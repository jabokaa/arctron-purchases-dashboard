<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\User;

/**
 * Usuário logado dos testes das rotas /api, que exigem autenticação.
 * Não vai para o banco: o KernelBrowser::loginUser() só precisa do objeto.
 */
final class TestUser
{
    public static function create(): User
    {
        return (new User())->setName('Administrador de Teste')->setEmail('teste@cbf.com.br');
    }
}
