<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;

/**
 * Lançada ao tentar remover um produto que já foi vendido:
 * as compras antigas precisam continuar mostrando qual produto foi vendido.
 *
 * #[WithHttpStatus(409)] diz qual status HTTP usar; quem monta o JSON do erro é o ApiExceptionListener.
 */
#[WithHttpStatus(409)]
final class ProductHasSalesException extends \DomainException
{
    public function __construct(
        public readonly int $productId,
    ) {
        parent::__construct(\sprintf('O produto %d já foi vendido e não pode ser removido.', $productId));
    }
}
