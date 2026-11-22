<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;

/**
 * Lançada quando não existe compra com o id pedido.
 *
 * #[WithHttpStatus(404)] diz qual status HTTP usar quando esta exceção chega até a resposta.
 * Quem monta o JSON do erro é o ApiExceptionListener.
 */
#[WithHttpStatus(404)]
final class PurchaseNotFoundException extends \DomainException
{
    public function __construct(
        public readonly int $purchaseId,
    ) {
        parent::__construct(\sprintf('Compra %d não encontrada.', $purchaseId));
    }
}
