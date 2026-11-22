<?php

declare(strict_types=1);

namespace App\Integration\WooCommerce;

use App\Dto\CustomerInput;
use App\Dto\ProductInput;
use App\Dto\PurchaseInput;
use App\Util\Money;

/**
 * Converte um pedido do WooCommerce (payload do webhook order.created, formato da REST API v3)
 * no mesmo PurchaseInput recebido pelo POST /api/purchases.
 *
 * Depois de convertido, o pedido passa pelas mesmas validações e pelo mesmo PurchaseService
 * do endpoint de compras. Campos ausentes viram null e são apontados pela validação.
 *
 * De → para:
 * - reference_code: "WC-" + id do pedido (o prefixo evita conflito com códigos de outros canais)
 * - purchased_at:   date_created_gmt (UTC)
 * - notes:          customer_note
 * - customer:       billing (nome, e-mail) + CPF/CNPJ do plugin "Brazilian Market on WooCommerce",
 *                   que fica em billing.cpf / billing.cnpj ou em meta_data (_billing_cpf / _billing_cnpj)
 * - products:       line_items (o id do produto no WooCommerce vira o external_id)
 */
final class OrderMapper
{
    /** Tipo de pessoa no plugin Brazilian Market: 1 = física, 2 = jurídica */
    private const PERSON_TYPE_COMPANY = '2';

    /**
     * @param array<string, mixed> $order
     *
     * @throws \InvalidArgumentException se um valor monetário do pedido não for um número
     */
    public function toPurchaseInput(array $order): PurchaseInput
    {
        return new PurchaseInput(
            referenceCode: isset($order['id']) ? 'WC-'.$order['id'] : null,
            purchasedAt: self::purchasedAt($order),
            notes: self::filled($order['customer_note'] ?? null),
            customer: $this->customer($order),
            products: array_map($this->product(...), $order['line_items'] ?? []),
        );
    }

    /**
     * Cliente a partir dos dados de cobrança. Com CNPJ, é pessoa jurídica e o nome é a empresa (billing.company).
     *
     * @param array<string, mixed> $order
     */
    private function customer(array $order): CustomerInput
    {
        $billing = $order['billing'] ?? [];
        $meta = self::metaData($order);

        $cpf = self::filled($billing['cpf'] ?? $meta['_billing_cpf'] ?? null);
        $cnpj = self::filled($billing['cnpj'] ?? $meta['_billing_cnpj'] ?? null);
        $personType = (string) ($billing['persontype'] ?? $meta['_billing_persontype'] ?? '');

        // O plugin pode mandar os dois campos; o tipo de pessoa decide qual vale
        $isCompany = null !== $cnpj && (self::PERSON_TYPE_COMPANY === $personType || null === $cpf);

        $fullName = self::filled(trim(($billing['first_name'] ?? '').' '.($billing['last_name'] ?? '')));

        return new CustomerInput(
            name: $isCompany ? (self::filled($billing['company'] ?? null) ?? $fullName) : $fullName,
            email: self::filled($billing['email'] ?? null),
            cpf: $isCompany ? null : $cpf,
            cnpj: $isCompany ? $cnpj : null,
        );
    }

    /**
     * Item do pedido. O WooCommerce informa o valor da linha antes do desconto (subtotal)
     * e depois do desconto (total):
     * - unit_price = subtotal ÷ quantidade (arredondado para o centavo, quando não divide exato);
     * - discount   = subtotal − total.
     * Produto com variação (ex.: tamanho) usa o id da variação, que é um item diferente no estoque.
     *
     * @param array<string, mixed> $lineItem
     */
    private function product(array $lineItem): ProductInput
    {
        $quantity = isset($lineItem['quantity']) ? (int) $lineItem['quantity'] : null;
        $subtotal = isset($lineItem['subtotal']) ? Money::toCents((string) $lineItem['subtotal']) : null;
        $total = isset($lineItem['total']) ? Money::toCents((string) $lineItem['total']) : $subtotal;

        $productId = (int) ($lineItem['variation_id'] ?? 0) ?: (int) ($lineItem['product_id'] ?? 0);

        return new ProductInput(
            externalId: $productId > 0 ? (string) $productId : null,
            name: self::filled($lineItem['name'] ?? null),
            quantity: $quantity,
            unitPrice: null !== $subtotal && $quantity > 0 ? (int) round($subtotal / $quantity) : null,
            discount: null !== $subtotal && null !== $total ? $subtotal - $total : 0,
        );
    }

    /**
     * date_created_gmt vem sem fuso (ex.: 2026-11-21T17:30:00) e está em UTC.
     *
     * @param array<string, mixed> $order
     */
    private static function purchasedAt(array $order): ?\DateTimeImmutable
    {
        $date = self::filled($order['date_created_gmt'] ?? null);

        if (null === $date) {
            return null;
        }

        try {
            return new \DateTimeImmutable($date, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null; // data ilegível: a validação aponta "data da compra obrigatória"
        }
    }

    /**
     * meta_data do WooCommerce vem como lista de { key, value }; vira um mapa key → value.
     *
     * @param array<string, mixed> $order
     *
     * @return array<string, mixed>
     */
    private static function metaData(array $order): array
    {
        $meta = [];
        foreach ($order['meta_data'] ?? [] as $item) {
            if (isset($item['key'])) {
                $meta[$item['key']] = $item['value'] ?? null;
            }
        }

        return $meta;
    }

    /** Texto vazio vira null, para a validação tratar como "não informado" */
    private static function filled(mixed $value): ?string
    {
        if (null === $value || \is_array($value)) {
            return null;
        }

        $value = trim((string) $value);

        return '' === $value ? null : $value;
    }
}
