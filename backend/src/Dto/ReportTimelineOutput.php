<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Resposta do GET /api/reports/timeline: as compras agrupadas por período, do mais antigo para o mais recente.
 * Períodos sem compras aparecem zerados, para o gráfico não "pular" datas. Valores em centavos.
 *
 *   {
 *     "interval": "month",
 *     "data": [
 *       { "period": "2026-10", "start": "2026-10-01", "end": "2026-10-31", "purchases_count": 0, "amount": 0, "discount": 0 },
 *       { "period": "2026-11", "start": "2026-11-01", "end": "2026-11-30", "purchases_count": 3, "amount": 29409, "discount": 600 }
 *     ]
 *   }
 */
final class ReportTimelineOutput
{
    /**
     * @param list<array{period: string, start: string, end: string, purchases_count: int, amount: int, discount: int}> $data
     */
    public function __construct(
        public readonly string $interval,
        public readonly array $data,
    ) {}
}
