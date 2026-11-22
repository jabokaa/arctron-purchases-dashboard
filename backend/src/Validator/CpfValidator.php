<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * Lógica da validação #[Cpf].
 * O Symfony liga as duas classes pelo nome: Cpf → CpfValidator.
 */
final class CpfValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof Cpf) {
            throw new UnexpectedTypeException($constraint, Cpf::class);
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

    public static function isValid(string $cpf): bool
    {
        // Remove a máscara (pontos, traço, espaços)
        $digits = preg_replace('/\D/', '', $cpf);

        // Precisa ter 11 dígitos e não pode ser todos iguais (ex.: 111.111.111-11)
        if (11 !== \strlen($digits) || preg_match('/^(\d)\1{10}$/', $digits)) {
            return false;
        }

        // Calcula e confere os dois dígitos verificadores (posições 10 e 11)
        for ($position = 9; $position < 11; ++$position) {
            $sum = 0;
            for ($i = 0; $i < $position; ++$i) {
                $sum += (int) $digits[$i] * (($position + 1) - $i);
            }

            $checkDigit = ((10 * $sum) % 11) % 10;

            if ((int) $digits[$position] !== $checkDigit) {
                return false;
            }
        }

        return true;
    }
}
