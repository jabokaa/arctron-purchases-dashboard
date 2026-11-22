<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Valida se o valor é um CNPJ válido (com ou sem máscara), numérico ou alfanumérico,
 * conferindo o formato e os dígitos verificadores.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_PARAMETER)]
final class Cnpj extends Constraint
{
    public string $message = 'O CNPJ "{{ value }}" não é válido.';

    /**
     * Indica qual classe executa a validação desta regra.
     */
    public function validatedBy(): string
    {
        return CnpjValidator::class;
    }
}
