<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Imagem mínima para os testes de upload.
 */
final class TestImage
{
    /** PNG de 1x1 pixel */
    public static function pngBytes(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
    }
}
