<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;

/**
 * Lançada quando já existe uma compra com o mesmo código de referência.
 *
 * #[WithHttpStatus(409)] diz qual status HTTP usar quando esta exceção chega até a resposta.
 * Quem monta o JSON do erro é o ApiExceptionListener.
 */
#[WithHttpStatus(409)]
final class DuplicatePurchaseException extends \DomainException
{
    public function __construct(
        public readonly string $referenceCode,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(\sprintf('Já existe uma compra com o código de referência "%s".', $referenceCode), 0, $previous);
    }
}
