<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;

/**
 * Lançada quando o arquivo enviado como imagem do produto não é uma imagem aceita
 * (JPG, PNG, WebP ou GIF, de até 2 MB).
 */
#[WithHttpStatus(422)]
final class InvalidImageException extends \DomainException
{
}
