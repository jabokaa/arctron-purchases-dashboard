<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\PurchaseFilterInput;
use App\Dto\PurchaseListOutput;

/**
 * Contrato da consulta de compras.
 *
 * O controller depende desta interface, o que permite trocar a implementação por um mock nos testes.
 */
interface PurchaseSearchServiceInterface
{
    public function search(PurchaseFilterInput $filter): PurchaseListOutput;
}
