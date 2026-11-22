<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;

/**
 * Lançada no login quando o e-mail não existe ou a senha está errada.
 *
 * A mensagem é a mesma nos dois casos, para não revelar quais e-mails têm conta.
 */
#[WithHttpStatus(401)]
final class InvalidCredentialsException extends \DomainException
{
    public function __construct()
    {
        parent::__construct('E-mail ou senha inválidos.');
    }
}
