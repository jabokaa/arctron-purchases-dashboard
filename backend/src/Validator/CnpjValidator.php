<?php

declare(strict_types=1);

namespace App\Validator;

use App\Util\DocumentNumber;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * Lógica da validação #[Cnpj].
 *
 * Aceita o CNPJ numérico (11.222.333/0001-81) e o alfanumérico, em vigor desde julho/2026
 * (12.ABC.345/01DE-35): as 12 primeiras posições podem ter letras e os 2 últimos
 * são sempre dígitos verificadores numéricos.
 */
final class CnpjValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof Cnpj) {
            throw new UnexpectedTypeException($constraint, Cnpj::class);
        }

        // Valor vazio é responsabilidade do #[NotBlank]
        if (null === $value || '' === $value) {
            return;
        }

        if (!\is_string($value)) {
            throw new UnexpectedValueException($value, 'string');
        }

        if (!self::isValid($value)) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ value }}', $value)
                ->addViolation();
        }
    }

    public static function isValid(string $cnpj): bool
    {
        $cnpj = DocumentNumber::normalizeCnpj($cnpj);

        // 12 caracteres alfanuméricos + 2 dígitos verificadores; não pode ser tudo igual (ex.: 00000000000000)
        if (!preg_match('/^[A-Z0-9]{12}\d{2}$/', $cnpj) || preg_match('/^(.)\1{13}$/', $cnpj)) {
            return false;
        }

        // Cada caractere vale (código ASCII - 48): "0".."9" → 0..9, "A" → 17, "B" → 18...
        // Para CNPJ só com números, isso é o mesmo que usar o próprio dígito.
        $values = array_map(static fn (string $char): int => \ord($char) - 48, str_split($cnpj));

        // Pesos oficiais para o 1º e o 2º dígito verificador
        $weights = [
            12 => [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2],
            13 => [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2],
        ];

        foreach ($weights as $position => $positionWeights) {
            $sum = 0;
            foreach ($positionWeights as $i => $weight) {
                $sum += $values[$i] * $weight;
            }

            $remainder = $sum % 11;
            $checkDigit = $remainder < 2 ? 0 : 11 - $remainder;

            if ($values[$position] !== $checkDigit) {
                return false;
            }
        }

        return true;
    }
}
