<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\PurchaseInput;
use App\Entity\Purchase;
use App\Entity\SalesChannel;
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
     * Grava a compra no canal de venda informado: API (endpoint POST /api/purchases, o padrão)
     * ou WOOCOMMERCE (pedidos recebidos pelo webhook).
     *
     * @throws DuplicatePurchaseException quando o código de referência já foi usado
     */
    public function create(PurchaseInput $input, string $salesChannelCode = SalesChannel::API): Purchase;
}
