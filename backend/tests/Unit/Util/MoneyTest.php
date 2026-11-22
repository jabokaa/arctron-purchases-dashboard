<?php

declare(strict_types=1);

namespace App\Tests\Unit\Util;

use App\Util\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Testes unitários do Money::toCents() (valor decimal em reais → centavos inteiros).
 *
 * O que é testado: a conversão dos valores enviados pelo WooCommerce ("50.00", "160.1"...) para centavos,
 * sem erro de arredondamento, incluindo o arredondamento da terceira casa e a recusa de textos inválidos.
 */
final class MoneyTest extends TestCase
{
    /**
     * Para cada caso do provider, converte o valor e espera os centavos.
     */
    #[DataProvider('validValueProvider')]
    public function testConvertsToCents(string|int $value, int $expected): void
    {
        self::assertSame($expected, Money::toCents($value));
    }

    /**
     * @return iterable<string, array{string|int, int}>
     */
    public static function validValueProvider(): iterable
    {
        // Formato mais comum do WooCommerce: duas casas
        yield 'duas casas' => ['160.10', 16010];

        // Uma casa decimal: "160.1" = R$ 160,10
        yield 'uma casa' => ['160.1', 16010];

        // Sem casas decimais
        yield 'sem casas' => ['30', 3000];

        // Inteiro (não texto)
        yield 'inteiro' => [30, 3000];

        // Zero
        yield 'zero' => ['0.00', 0];

        // Valor que dá erro de arredondamento com float (0.1 + 0.2): aqui é exato
        yield 'centavos exatos' => ['0.30', 30];

        // Terceira casa menor que 5: arredonda para baixo
        yield 'terceira casa para baixo' => ['10.004', 1000];

        // Terceira casa 5 ou mais: arredonda para cima
        yield 'terceira casa para cima' => ['10.005', 1001];

        // Espaços em volta são ignorados
        yield 'com espaços' => [' 50.00 ', 5000];

        // Valor negativo (ex.: estorno)
        yield 'negativo' => ['-12.50', -1250];

        // Valor alto, sem perder precisão
        yield 'valor alto' => ['9999999999.99', 999999999999];
    }

    /**
     * Para cada texto que não é um número decimal, espera InvalidArgumentException.
     */
    #[DataProvider('invalidValueProvider')]
    public function testRejectsInvalidValue(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Money::toCents($value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidValueProvider(): iterable
    {
        // Texto
        yield 'texto' => ['abc'];

        // Vírgula como separador decimal (formato brasileiro) não é o formato do WooCommerce
        yield 'vírgula' => ['10,50'];

        // Vazio
        yield 'vazio' => [''];

        // Com símbolo de moeda
        yield 'com R$' => ['R$ 10.00'];
    }
}
