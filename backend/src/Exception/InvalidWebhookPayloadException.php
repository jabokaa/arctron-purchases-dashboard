<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;

/**
 * Lançada quando o corpo do webhook (com assinatura válida) não é um objeto JSON.
 * Nada é gravado nem publicado na fila.
 *
 * #[WithHttpStatus(400)] diz qual status HTTP usar; quem monta o JSON do erro é o ApiExceptionListener.
 */
#[WithHttpStatus(400)]
final class InvalidWebhookPayloadException extends \DomainException
{
    public function __construct()
    {
        parent::__construct('O corpo do webhook não é um JSON válido.');
    }
}
