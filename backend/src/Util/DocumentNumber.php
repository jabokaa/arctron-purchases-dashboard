<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Funções para números de documento (CPF, CNPJ...).
 */
final class DocumentNumber
{
    /** Remove a máscara do documento: "529.982.247-25" → "52998224725" */
    public static function onlyDigits(string $value): string
    {
        return preg_replace('/\D/', '', $value);
    }

    /**
     * Remove a máscara do CNPJ mantendo letras, pois desde julho/2026 o CNPJ pode ser alfanumérico.
     * "11.222.333/0001-81" → "11222333000181"
     * "12.abc.345/01de-35" → "12ABC34501DE35"
     */
    public static function normalizeCnpj(string $value): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper($value));
    }
}
