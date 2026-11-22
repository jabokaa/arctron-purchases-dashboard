<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;

/**
 * Lançada quando não existe produto com o id pedido.
 *
 * #[WithHttpStatus(404)] diz qual status HTTP usar; quem monta o JSON do erro é o ApiExceptionListener.
 */
#[WithHttpStatus(404)]
final class ProductNotFoundException extends \DomainException
{
    public function __construct(
        public readonly int $productId,
    ) {
        parent::__construct(\sprintf('Produto %d não encontrado.', $productId));
    }
}
