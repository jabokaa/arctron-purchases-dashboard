<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Critério de ordenação do ranking de clientes (GET /api/reports/customers?sort=...).
 */
enum CustomerReportSort: string
{
    /** Valor total comprado */
    case Total = 'total';

    /** Valor médio por compra */
    case Average = 'average';

    /** Quantidade de compras */
    case Count = 'count';

    /** Expressão do ORDER BY (lista fixa: o texto do usuário nunca entra no SQL) */
    public function sql(): string
    {
        return match ($this) {
            self::Total => 'SUM(purchases.amount)',
            self::Average => 'AVG(purchases.amount)',
            self::Count => 'COUNT(purchases.id)',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
