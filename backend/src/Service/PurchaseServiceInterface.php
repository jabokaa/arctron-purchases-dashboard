<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\PurchaseInput;
use App\Entity\Purchase;
use App\Exception\DuplicatePurchaseException;

/**
 * Contrato do recebimento de compras.
 *
 * O controller depende desta interface (e não da classe PurchaseService),
 * o que permite trocar a implementação por um mock nos testes.
 * Como só existe uma implementação, o Symfony liga a interface ao PurchaseService sozinho.
 */
interface PurchaseServiceInterface
{
    /**
     * @throws DuplicatePurchaseException quando o código de referência já foi usado
     */
    public function create(PurchaseInput $input): Purchase;
}
