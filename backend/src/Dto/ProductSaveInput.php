<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Corpo do POST /api/products (criar) e do PUT /api/products/{id} (atualizar):
 *
 *   { "name": "Chuteira de Campo", "internal_code": "CHU-001" }
 *
 * O PUT substitui o nome e o código interno: campo opcional não enviado vira null.
 * A imagem não vai aqui: ela é enviada em arquivo por POST /api/products/{id}/image.
 */
final class ProductSaveInput
{
    public function __construct(
        #[Assert\NotBlank(message: 'O nome do produto é obrigatório.')]
        #[Assert\Length(max: 255, maxMessage: 'O nome do produto deve ter no máximo {{ limit }} caracteres.')]
        public readonly ?string $name = null,

        /** Código interno (SKU próprio), opcional; quando informado, não pode se repetir entre produtos */
        #[Assert\Length(max: 100, maxMessage: 'O código interno deve ter no máximo {{ limit }} caracteres.')]
        public readonly ?string $internalCode = null,
    ) {}
}
