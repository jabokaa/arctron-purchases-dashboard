<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;

/**
 * Lançada quando o código interno informado já pertence a outro produto.
 *
 * #[WithHttpStatus(409)] diz qual status HTTP usar; quem monta o JSON do erro é o ApiExceptionListener.
 */
#[WithHttpStatus(409)]
final class DuplicateProductCodeException extends \DomainException
{
    public function __construct(
        public readonly string $internalCode,
    ) {
        parent::__construct(\sprintf('Já existe um produto com o código interno "%s".', $internalCode));
    }
}
