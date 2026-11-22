<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Resposta dos rankings (GET /api/reports/customers e /api/reports/products):
 * a página atual do ranking e os dados da paginação.
 *
 *   { "data": [ ... ], "meta": { "page": 1, "limit": 20, "total": 42, "total_pages": 3 } }
 */
final class ReportRankingOutput
{
    /**
     * @param list<array<string, mixed>>                                         $data
     * @param array{page: int, limit: int, total: int, total_pages: int} $meta
     */
    public function __construct(
        public readonly array $data,
        public readonly array $meta,
    ) {}
}
