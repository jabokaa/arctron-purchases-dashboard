<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Resposta do GET /api/products: a página atual do catálogo e os dados da paginação.
 *
 *   { "data": [ ...produtos... ], "meta": { "page": 1, "per_page": 20, "total": 11, "total_pages": 1 } }
 */
final class ProductListOutput
{
    /**
     * @param list<ProductOutput>                                           $data
     * @param array{page: int, per_page: int, total: int, total_pages: int} $meta
     */
    public function __construct(
        public readonly array $data,
        public readonly array $meta,
    ) {}
}
