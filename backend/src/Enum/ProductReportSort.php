<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Critério de ordenação do ranking de produtos (GET /api/reports/products?sort=...).
 */
enum ProductReportSort: string
{
    /** Valor total vendido (já com o desconto dos itens) */
    case Total = 'total';

    /** Preço médio efetivo por unidade (valor total ÷ quantidade vendida) */
    case Average = 'average';

    /** Quantidade de unidades vendidas */
    case Quantity = 'quantity';

    /** Expressão do ORDER BY (lista fixa: o texto do usuário nunca entra no SQL) */
    public function sql(): string
    {
        return match ($this) {
            self::Total => 'SUM(purchase_items.total_price)',
            self::Average => 'SUM(purchase_items.total_price)::NUMERIC / SUM(purchase_items.quantity)',
            self::Quantity => 'SUM(purchase_items.quantity)',
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
