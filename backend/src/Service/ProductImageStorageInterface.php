<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\InvalidImageException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Guarda as imagens dos produtos no disco (public/uploads/products).
 *
 * O que vai para o banco (products.image_url) é o caminho público do arquivo, ex.: /uploads/products/ab12....jpg.
 */
interface ProductImageStorageInterface
{
    /**
     * @return string caminho público do arquivo gravado
     *
     * @throws InvalidImageException não é uma imagem aceita ou passa do tamanho máximo
     */
    public function storeUploaded(UploadedFile $file): string;

    /**
     * @return string caminho público do arquivo gravado
     *
     * @throws InvalidImageException não é uma imagem aceita ou passa do tamanho máximo
     */
    public function storeContents(string $contents): string;

    /**
     * Apaga o arquivo. Ignora valores vazios e endereços que não são arquivos desta pasta (ex.: URL externa).
     */
    public function remove(?string $imageUrl): void;

    /**
     * O endereço aponta para um arquivo guardado por este storage?
     */
    public function isLocal(?string $imageUrl): bool;
}
