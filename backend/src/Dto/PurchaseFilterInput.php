<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Filtros da consulta GET /api/purchases, recebidos na query string:
 *
 *   /api/purchases?reference_code=PED&customer_name=joão&date_from=2026-11-01&amount_min=1000&page=2
 *
 * Todos os filtros são opcionais e podem ser combinados (todos precisam bater).
 * O #[MapQueryString] no controller converte a query string neste objeto e valida antes de o controller rodar.
 */
final class PurchaseFilterInput
{
    /** Formatos de data aceitos: só a data (2026-11-21) ou data e hora ISO 8601 (2026-11-21T14:30:00-03:00) */
    private const DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}(:\d{2})?([+-]\d{2}:\d{2}|Z)?)?$/';

    public function __construct(
        /** Parte do código de referência (não diferencia maiúsculas) */
        #[Assert\Length(max: 100, maxMessage: 'O código de referência deve ter no máximo {{ limit }} caracteres.')]
        public readonly ?string $referenceCode = null,

        /** Parte do nome do cliente: nome (pessoa física), razão social ou nome fantasia (pessoa jurídica) */
        #[Assert\Length(max: 255, maxMessage: 'O nome do cliente deve ter no máximo {{ limit }} caracteres.')]
        public readonly ?string $customerName = null,

        /** Parte do e-mail do cliente (não diferencia maiúsculas) */
        #[Assert\Length(max: 255, maxMessage: 'O e-mail do cliente deve ter no máximo {{ limit }} caracteres.')]
        public readonly ?string $customerEmail = null,

        /** CPF ou CNPJ completo, com ou sem máscara */
        #[Assert\Length(max: 20, maxMessage: 'O documento do cliente deve ter no máximo {{ limit }} caracteres.')]
        public readonly ?string $customerDocument = null,

        /** Data inicial da compra (inclusive) */
        public readonly ?string $dateFrom = null,

        /** Data final da compra (inclusive). Só a data (2026-11-30) vale o dia inteiro */
        public readonly ?string $dateTo = null,

        /** Valor total mínimo da compra, em centavos (inclusive) */
        #[Assert\PositiveOrZero(message: 'O valor mínimo não pode ser negativo.')]
        public readonly ?int $amountMin = null,

        /** Valor total máximo da compra, em centavos (inclusive) */
        #[Assert\PositiveOrZero(message: 'O valor máximo não pode ser negativo.')]
        public readonly ?int $amountMax = null,

        #[Assert\Positive(message: 'A página deve ser maior que zero.')]
        public readonly int $page = 1,

        #[Assert\Range(notInRangeMessage: 'A quantidade por página deve estar entre {{ min }} e {{ max }}.', min: 1, max: 100)]
        public readonly int $perPage = 20,
    ) {}

    /** Início do intervalo de datas (inclusive), em UTC como no banco */
    public function startDate(): ?\DateTimeImmutable
    {
        return self::parseDate($this->dateFrom);
    }

    /**
     * Fim do intervalo de datas (EXCLUSIVO), em UTC como no banco.
     * Só a data (2026-11-30) vira o início do dia seguinte, para incluir o dia 30 inteiro;
     * com hora, vale 1 segundo depois do horário informado.
     */
    public function endDateExclusive(): ?\DateTimeImmutable
    {
        $date = self::parseDate($this->dateTo);

        if (null === $date) {
            return null;
        }

        return self::isDateOnly((string) $this->dateTo) ? $date->modify('+1 day') : $date->modify('+1 second');
    }

    /**
     * Regras entre campos e formato das datas.
     */
    #[Assert\Callback]
    public function validateRanges(ExecutionContextInterface $context): void
    {
        foreach (['dateFrom' => $this->dateFrom, 'dateTo' => $this->dateTo] as $field => $value) {
            if (null !== $value && null === self::parseDate($value)) {
                $context->buildViolation('Data inválida. Use AAAA-MM-DD (ex.: 2026-11-21) ou ISO 8601 (ex.: 2026-11-21T14:30:00-03:00).')
                    ->atPath($field)
                    ->addViolation();
            }
        }

        $start = $this->startDate();
        $end = $this->endDateExclusive();
        if (null !== $start && null !== $end && $start >= $end) {
            $context->buildViolation('A data inicial não pode ser maior que a data final.')
                ->atPath('dateFrom')
                ->addViolation();
        }

        if (null !== $this->amountMin && null !== $this->amountMax && $this->amountMin > $this->amountMax) {
            $context->buildViolation('O valor mínimo não pode ser maior que o valor máximo.')
                ->atPath('amountMin')
                ->addViolation();
        }
    }

    /**
     * Converte o texto da data para DateTimeImmutable em UTC; null se vazio ou inválido.
     * Sem fuso informado, a data é considerada UTC (o mesmo padrão do banco).
     */
    private static function parseDate(?string $value): ?\DateTimeImmutable
    {
        if (null === $value || '' === $value || !preg_match(self::DATE_PATTERN, $value)) {
            return null;
        }

        try {
            $date = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null; // ex.: 2026-02-30 tem o formato certo, mas não existe
        }

        // Garante que a data existe de verdade (o PHP transformaria 2026-02-30 em 2026-03-02)
        if ($date->format('Y-m-d') !== substr($value, 0, 10) && self::isDateOnly($value)) {
            return null;
        }

        return $date->setTimezone(new \DateTimeZone('UTC'));
    }

    private static function isDateOnly(string $value): bool
    {
        return 10 === \strlen($value);
    }
}
