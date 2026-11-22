<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\InvalidImageException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ProductImageStorage implements ProductImageStorageInterface
{
    public const PUBLIC_PREFIX = '/uploads/products/';

    /** Igual ao upload_max_filesize padrão do PHP (2M): acima disso o PHP recusa o arquivo antes de chegar na aplicação */
    public const MAX_SIZE = 2 * 1024 * 1024;

    /** Tipo (detectado pelo conteúdo, não pelo nome do arquivo) → extensão */
    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    private const FILE_NAME_PATTERN = '/^[a-f0-9]{32}\.(?:jpg|png|webp|gif)$/';

    public function __construct(
        #[Autowire('%app.product_images_dir%')]
        private readonly string $directory,
    ) {
    }

    public function storeUploaded(UploadedFile $file): string
    {
        $contents = @file_get_contents($file->getPathname());
        if (false === $contents) {
            throw new InvalidImageException('Não foi possível ler o arquivo enviado.');
        }

        return $this->storeContents($contents);
    }

    public function storeContents(string $contents): string
    {
        if ('' === $contents || \strlen($contents) > self::MAX_SIZE) {
            throw new InvalidImageException('A imagem deve ter no máximo 2 MB.');
        }

        $extension = self::EXTENSIONS[(new \finfo(\FILEINFO_MIME_TYPE))->buffer($contents)] ?? null;
        if (null === $extension) {
            throw new InvalidImageException('O arquivo deve ser uma imagem JPG, PNG, WebP ou GIF.');
        }

        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Não foi possível criar a pasta de imagens.');
        }

        $name = bin2hex(random_bytes(16)).'.'.$extension;
        if (false === file_put_contents($this->directory.'/'.$name, $contents)) {
            throw new \RuntimeException('Não foi possível gravar a imagem.');
        }

        return self::PUBLIC_PREFIX.$name;
    }

    public function remove(?string $imageUrl): void
    {
        if (!$this->isLocal($imageUrl)) {
            return;
        }

        @unlink($this->directory.'/'.basename((string) $imageUrl));
    }

    public function isLocal(?string $imageUrl): bool
    {
        return null !== $imageUrl
            && str_starts_with($imageUrl, self::PUBLIC_PREFIX)
            && 1 === preg_match(self::FILE_NAME_PATTERN, substr($imageUrl, \strlen(self::PUBLIC_PREFIX)));
    }
}
