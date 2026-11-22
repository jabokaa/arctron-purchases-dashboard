<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Resposta da consulta de compras: a página atual de compras e os dados da paginação.
 *
 *   { "data": [ ...compras... ], "meta": { "page": 1, "per_page": 20, "total": 42, "total_pages": 3 } }
 */
final class PurchaseListOutput
{
    /**
     * @param list<PurchaseOutput>                                                  $data
     * @param array{page: int, per_page: int, total: int, total_pages: int} $meta
     */
    public function __construct(
        public readonly array $data,
        public readonly array $meta,
    ) {}
}
