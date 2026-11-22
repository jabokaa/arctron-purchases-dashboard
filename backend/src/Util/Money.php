<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Conversões de dinheiro.
 */
final class Money
{
    /**
     * Converte um valor decimal em reais, como os enviados pelo WooCommerce ("50.00", "160.1", "3"),
     * para centavos inteiros: "160.10" → 16010.
     *
     * A conversão é feita no texto, sem float, para não haver erro de arredondamento.
     * Casas além da segunda são arredondadas para o centavo mais próximo ("0.005" → 1).
     *
     * @throws \InvalidArgumentException se o valor não for um número decimal
     */
    public static function toCents(string|int $value): int
    {
        if (\is_int($value)) {
            return $value * 100;
        }

        if (!preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', trim($value), $matches)) {
            throw new \InvalidArgumentException(\sprintf('Valor monetário inválido: "%s".', $value));
        }

        [, $sign, $integer, $decimals] = $matches + [3 => ''];

        $decimals = str_pad($decimals, 3, '0');
        $cents = (int) $integer * 100 + (int) substr($decimals, 0, 2);

        // Terceira casa decimal 5 ou mais: arredonda para cima
        if ((int) $decimals[2] >= 5) {
            ++$cents;
        }

        return '-' === $sign ? -$cents : $cents;
    }
}
