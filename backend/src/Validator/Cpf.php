<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Valida se o valor é um CPF válido (com ou sem máscara),
 * conferindo a quantidade de dígitos e os dígitos verificadores.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_PARAMETER)]
final class Cpf extends Constraint
{
    public string $message = 'O CPF "{{ value }}" não é válido.';

    /**
     * Indica qual classe executa a validação desta regra.
     * Por convenção o Symfony já usaria "CpfValidator" (nome da classe + "Validator"),
     * mas deixamos explícito para o mapeamento ficar claro.
     */
    public function validatedBy(): string
    {
        return CpfValidator::class;
    }
}
