<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Direção da ordenação dos rankings (?order=asc|desc).
 */
enum SortOrder: string
{
    case Asc = 'asc';
    case Desc = 'desc';

    /** Palavra usada no ORDER BY do SQL (lista fixa: o texto do usuário nunca entra no SQL) */
    public function sql(): string
    {
        return match ($this) {
            self::Asc => 'ASC',
            self::Desc => 'DESC',
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
