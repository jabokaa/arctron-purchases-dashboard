<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;

/**
 * Lançada quando a assinatura HMAC do webhook está ausente ou não confere.
 * Nada é gravado nem publicado na fila.
 *
 * #[WithHttpStatus(401)] diz qual status HTTP usar; quem monta o JSON do erro é o ApiExceptionListener.
 */
#[WithHttpStatus(401)]
final class InvalidWebhookSignatureException extends \DomainException
{
    public function __construct()
    {
        parent::__construct('Assinatura do webhook inválida.');
    }
}
