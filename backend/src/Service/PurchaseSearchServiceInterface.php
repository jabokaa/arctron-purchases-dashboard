<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\PurchaseFilterInput;
use App\Dto\PurchaseListOutput;
use App\Dto\PurchaseOutput;
use App\Exception\PurchaseNotFoundException;

/**
 * Contrato da consulta de compras (listagem com filtros e detalhe de uma compra).
 *
 * O controller depende desta interface, o que permite trocar a implementação por um mock nos testes.
 */
interface PurchaseSearchServiceInterface
{
    public function search(PurchaseFilterInput $filter): PurchaseListOutput;

    /**
     * @throws PurchaseNotFoundException quando não existe compra com o id informado
     */
    public function find(int $id): PurchaseOutput;
}
