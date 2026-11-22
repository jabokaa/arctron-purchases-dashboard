<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Lançada quando já existe uma compra com o mesmo código de referência.
 */
final class DuplicatePurchaseException extends \DomainException
{
    public function __construct(
        public readonly string $referenceCode,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(\sprintf('Já existe uma compra com o código de referência "%s".', $referenceCode), 0, $previous);
    }
}
