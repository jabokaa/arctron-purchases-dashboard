<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Corpo do POST /api/products (criar) e do PUT /api/products/{id} (atualizar):
 *
 *   { "name": "Chuteira de Campo", "internal_code": "CHU-001", "image_url": "https://.../chuteira.jpg" }
 *
 * O PUT substitui o produto inteiro: campo opcional não enviado vira null.
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

        /** Endereço da imagem do produto, opcional */
        #[Assert\Url(
            message: 'A imagem deve ser um endereço válido, começando com http:// ou https://.',
            protocols: ['http', 'https'],
            requireTld: true,
            tldMessage: 'A imagem deve ter um domínio completo (ex.: exemplo.com.br).',
        )]
        #[Assert\Length(max: 2048, maxMessage: 'O endereço da imagem deve ter no máximo {{ limit }} caracteres.')]
        public readonly ?string $imageUrl = null,
    ) {}
}
