<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Um produto da compra (cada item da lista "products" do JSON).
 */
final class ProductInput
{
    public function __construct(
        #[Assert\NotBlank(message: 'O nome do produto é obrigatório.')]
        #[Assert\Length(max: 255, maxMessage: 'O nome do produto deve ter no máximo {{ limit }} caracteres.')]
        public readonly ?string $name = null,

        #[Assert\NotNull(message: 'A quantidade é obrigatória.')]
        #[Assert\Positive(message: 'A quantidade deve ser maior que zero.')]
        public readonly ?int $quantity = null,

        #[Assert\NotNull(message: 'O valor unitário é obrigatório.')]
        #[Assert\PositiveOrZero(message: 'O valor unitário não pode ser negativo.')]
        #[Assert\LessThan(value: 10_000_000_000, message: 'O valor unitário é muito alto.')]
        public readonly ?float $unitPrice = null,
    ) {
    }
}
