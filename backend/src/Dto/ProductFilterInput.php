<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Filtros do GET /api/products (lista do catálogo), recebidos na query string:
 *
 *   /api/products?search=chuteira&page=1&per_page=20
 */
final class ProductFilterInput
{
    public function __construct(
        /** Parte do nome ou do código interno (não diferencia maiúsculas) */
        #[Assert\Length(max: 255, maxMessage: 'A busca deve ter no máximo {{ limit }} caracteres.')]
        public readonly ?string $search = null,

        #[Assert\Positive(message: 'A página deve ser maior que zero.')]
        public readonly int $page = 1,

        #[Assert\Range(notInRangeMessage: 'A quantidade por página deve estar entre {{ min }} e {{ max }}.', min: 1, max: 100)]
        public readonly int $perPage = 20,
    ) {}
}
