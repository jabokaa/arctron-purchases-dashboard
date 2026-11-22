<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\TimelineInterval;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Parâmetros do GET /api/reports/timeline, recebidos na query string:
 *
 *   /api/reports/timeline?interval=month
 *
 * O #[MapQueryString] no controller converte a query string neste objeto e valida antes de o controller rodar.
 */
final class TimelineFilterInput
{
    public function __construct(
        /** Tamanho de cada período: day, week ou month (padrão: day) */
        #[Assert\Choice(
            callback: [TimelineInterval::class, 'values'],
            message: 'O intervalo deve ser day, week ou month.',
        )]
        public readonly string $interval = TimelineInterval::Day->value,
    ) {}

    /** O intervalo já validado, como enum */
    public function interval(): TimelineInterval
    {
        return TimelineInterval::from($this->interval);
    }
}
