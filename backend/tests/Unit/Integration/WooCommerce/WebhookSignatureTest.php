<?php

declare(strict_types=1);

namespace App\Tests\Unit\Integration\WooCommerce;

use App\Integration\WooCommerce\WebhookSignature;
use PHPUnit\Framework\TestCase;

/**
 * Testes unitários do WebhookSignature (assinatura HMAC dos webhooks do WooCommerce).
 *
 * O que é testado: se a assinatura é calculada como o WooCommerce faz
 * (base64 do HMAC-SHA256 do corpo com o segredo) e se só a assinatura certa, para o corpo exato, é aceita.
 */
final class WebhookSignatureTest extends TestCase
{
    private const SECRET = 'segredo-de-teste';

    private const BODY = '{"id":727,"status":"processing"}';

    /**
     * A assinatura é base64(HMAC-SHA256(corpo, segredo)), igual à que o WooCommerce envia.
     * O valor esperado foi calculado à parte, com o mesmo algoritmo.
     */
    public function testSignsLikeWooCommerce(): void
    {
        $expected = base64_encode(hash_hmac('sha256', self::BODY, self::SECRET, true));

        self::assertSame($expected, (new WebhookSignature(self::SECRET))->sign(self::BODY));
    }

    /**
     * Assinatura correta para o corpo recebido: aceita.
     */
    public function testAcceptsValidSignature(): void
    {
        $signature = new WebhookSignature(self::SECRET);

        self::assertTrue($signature->isValid(self::BODY, $signature->sign(self::BODY)));
    }

    /**
     * Corpo alterado depois de assinado (um caractere a mais): recusa.
     */
    public function testRejectsTamperedBody(): void
    {
        $signature = new WebhookSignature(self::SECRET);

        self::assertFalse($signature->isValid(self::BODY.' ', $signature->sign(self::BODY)));
    }

    /**
     * Assinatura feita com outro segredo: recusa.
     */
    public function testRejectsSignatureWithAnotherSecret(): void
    {
        $other = (new WebhookSignature('outro-segredo'))->sign(self::BODY);

        self::assertFalse((new WebhookSignature(self::SECRET))->isValid(self::BODY, $other));
    }

    /**
     * Sem assinatura (cabeçalho ausente ou vazio): recusa.
     */
    public function testRejectsMissingSignature(): void
    {
        $signature = new WebhookSignature(self::SECRET);

        self::assertFalse($signature->isValid(self::BODY, null));
        self::assertFalse($signature->isValid(self::BODY, ''));
    }

    /**
     * Segredo não configurado: recusa tudo, para não aceitar webhooks assinados com segredo vazio.
     */
    public function testRejectsEverythingWhenSecretIsEmpty(): void
    {
        $emptySecret = new WebhookSignature('');

        self::assertFalse($emptySecret->isValid(self::BODY, $emptySecret->sign(self::BODY)));
    }
}
