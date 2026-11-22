<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\PurchaseFilterInput;
use App\Dto\PurchaseListOutput;
use App\Dto\PurchaseOutput;
use App\Exception\PurchaseNotFoundException;
use App\Repository\PurchaseRepository;

/**
 * Consulta de compras: listagem com filtros (resposta paginada) e detalhe de uma compra.
 */
final class PurchaseSearchService implements PurchaseSearchServiceInterface
{
    public function __construct(
        private readonly PurchaseRepository $purchases,
    ) {}

    public function search(PurchaseFilterInput $filter): PurchaseListOutput
    {
        $result = $this->purchases->search($filter);

        return new PurchaseListOutput(
            data: array_map(PurchaseOutput::fromEntity(...), $result['items']),
            meta: [
                'page' => $filter->page,
                'per_page' => $filter->perPage,
                'total' => $result['total'],
                'total_pages' => (int) ceil($result['total'] / $filter->perPage),
            ],
        );
    }

    public function find(int $id): PurchaseOutput
    {
        $purchase = $this->purchases->findWithDetails($id)
            ?? throw new PurchaseNotFoundException($id);

        return PurchaseOutput::fromEntity($purchase);
    }
}
