<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Corpo da requisição POST /api/purchases.
 *
 * Equivalente ao FormRequest do Laravel: descreve o formato esperado do JSON
 * e as regras de validação. O #[MapRequestPayload] no controller converte o JSON
 * neste objeto e valida antes de o controller rodar (erro → 422 automático).
 *
 * As chaves do JSON são snake_case (reference_code) e são convertidas para
 * camelCase (referenceCode) pelo name_converter configurado em config/packages/serializer.yaml.
 */
final class PurchaseInput
{
    /**
     * @param ProductInput[]|null $products
     */
    public function __construct(
        #[Assert\NotBlank(message: 'O código de referência é obrigatório.')]
        #[Assert\Length(max: 100, maxMessage: 'O código de referência deve ter no máximo {{ limit }} caracteres.')]
        public readonly ?string $referenceCode = null,

        #[Assert\NotNull(message: 'O valor da compra é obrigatório.')]
        #[Assert\PositiveOrZero(message: 'O valor da compra não pode ser negativo.')]
        #[Assert\LessThan(value: 10_000_000_000, message: 'O valor da compra é muito alto.')]
        public readonly ?float $amount = null,

        /** Desconto é opcional; quando não informado vale 0 */
        #[Assert\PositiveOrZero(message: 'O desconto não pode ser negativo.')]
        #[Assert\LessThan(value: 10_000_000_000, message: 'O desconto é muito alto.')]
        public readonly float $discount = 0.0,

        /** Data da compra em formato ISO 8601, ex.: 2026-11-21T14:30:00-03:00 */
        #[Assert\NotNull(message: 'A data da compra é obrigatória.')]
        public readonly ?\DateTimeImmutable $purchasedAt = null,

        /** Observações livres, ex.: "Produto enviado para o cliente por SEDEX" */
        public readonly ?string $notes = null,

        /** #[Assert\Valid] faz a validação entrar no objeto aninhado */
        #[Assert\NotNull(message: 'Os dados do cliente são obrigatórios.')]
        #[Assert\Valid]
        public readonly ?CustomerInput $customer = null,

        #[Assert\NotNull(message: 'A lista de produtos é obrigatória.')]
        #[Assert\Count(min: 1, minMessage: 'A compra deve ter pelo menos {{ limit }} produto.')]
        #[Assert\Valid]
        public readonly ?array $products = null,
    ) {
    }
}
