<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Um produto da compra (cada item da lista "products" do JSON).
 *
 * O produto é identificado pelo external_id (o código dele no canal de venda). Se o external_id ainda não for
 * conhecido, o PurchaseService procura o produto pelo internal_code (código do catálogo) e, se também não
 * encontrar, cria o produto usando o name.
 */
final class ProductInput
{
    /**
     * Código do produto no canal de venda (tabela product_external_ids).
     * Sempre string aqui dentro, mesmo que o JSON envie número.
     */
    #[Assert\NotBlank(message: 'O external_id do produto é obrigatório.')]
    #[Assert\Length(max: 255, maxMessage: 'O external_id do produto deve ter no máximo {{ limit }} caracteres.')]
    public readonly ?string $externalId;

    /**
     * @param string|int|null $externalId aceita "CAM-001" ou 123 (alguns canais enviam o ID como número)
     */
    public function __construct(
        string|int|null $externalId = null,

        /** Nome do produto; usado para cadastrar o produto quando ele ainda não existe */
        #[Assert\NotBlank(message: 'O nome do produto é obrigatório.')]
        #[Assert\Length(max: 255, maxMessage: 'O nome do produto deve ter no máximo {{ limit }} caracteres.')]
        public readonly ?string $name = null,

        #[Assert\NotNull(message: 'A quantidade é obrigatória.')]
        #[Assert\Positive(message: 'A quantidade deve ser maior que zero.')]
        public readonly ?int $quantity = null,

        /** Valor unitário em centavos, ex.: 5000 = R$ 50,00 */
        #[Assert\NotNull(message: 'O valor unitário é obrigatório.')]
        #[Assert\PositiveOrZero(message: 'O valor unitário não pode ser negativo.')]
        #[Assert\LessThan(value: 1_000_000_000_000, message: 'O valor unitário é muito alto.')]
        public readonly ?int $unitPrice = null,

        /** Desconto do item em centavos (sobre o total do item); é opcional e, quando não informado, vale 0 */
        #[Assert\PositiveOrZero(message: 'O desconto do item não pode ser negativo.')]
        #[Assert\LessThan(value: 1_000_000_000_000, message: 'O desconto do item é muito alto.')]
        public readonly int $discount = 0,

        /**
         * Código interno do produto no catálogo (SKU), opcional. Quando o external_id ainda não é conhecido no canal,
         * o produto do catálogo com este código é usado (e ganha o vínculo com o canal), em vez de criar outro.
         * Ex.: o WooCommerce envia o SKU de cada item, igual ao código interno do catálogo (CAM-001).
         */
        #[Assert\Length(max: 100, maxMessage: 'O código interno do produto deve ter no máximo {{ limit }} caracteres.')]
        public readonly ?string $internalCode = null,
    ) {
        // Normaliza para string: 123 → "123"
        $this->externalId = null === $externalId ? null : (string) $externalId;
    }
}
