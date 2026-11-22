<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Tamanho de cada período da linha do tempo (GET /api/reports/timeline?interval=...).
 *
 * O valor (day, week, month) é o mesmo nome usado pela função date_trunc do PostgreSQL,
 * que agrupa as compras no início de cada período. A semana começa na segunda-feira (padrão ISO).
 */
enum TimelineInterval: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';

    /**
     * Rótulo do período, para o eixo do gráfico: 2026-11-21 (dia), 2026-W47 (semana ISO), 2026-11 (mês).
     */
    public function label(\DateTimeImmutable $start): string
    {
        return match ($this) {
            self::Day => $start->format('Y-m-d'),
            self::Week => $start->format('o-\WW'),
            self::Month => $start->format('Y-m'),
        };
    }

    /**
     * Último dia do período que começa em $start (inclusive): o mesmo dia, o domingo da semana
     * ou o último dia do mês.
     */
    public function end(\DateTimeImmutable $start): \DateTimeImmutable
    {
        return match ($this) {
            self::Day => $start,
            self::Week => $start->modify('+6 days'),
            self::Month => $start->modify('last day of this month'),
        };
    }

    /**
     * @return list<string> valores aceitos, para validação e mensagens de erro
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
