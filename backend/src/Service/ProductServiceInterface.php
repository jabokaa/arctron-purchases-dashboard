<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\ProductFilterInput;
use App\Dto\ProductListOutput;
use App\Dto\ProductOutput;
use App\Dto\ProductSaveInput;
use App\Exception\DuplicateProductCodeException;
use App\Exception\InvalidImageException;
use App\Exception\ProductHasSalesException;
use App\Exception\ProductNotFoundException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Contrato do cadastro de produtos (CRUD do catálogo).
 *
 * O controller depende desta interface, o que permite trocar a implementação por um mock nos testes.
 */
interface ProductServiceInterface
{
    public function list(ProductFilterInput $filter): ProductListOutput;

    /**
     * @throws ProductNotFoundException
     */
    public function find(int $id): ProductOutput;

    /**
     * @throws DuplicateProductCodeException código interno já usado por outro produto
     */
    public function create(ProductSaveInput $input): ProductOutput;

    /**
     * @throws ProductNotFoundException
     * @throws DuplicateProductCodeException código interno já usado por outro produto
     */
    public function update(int $id, ProductSaveInput $input): ProductOutput;

    /**
     * @throws ProductNotFoundException
     * @throws ProductHasSalesException produto que já foi vendido não pode ser removido
     */
    public function delete(int $id): void;

    /**
     * Grava a imagem do produto, no lugar da anterior (se houver).
     *
     * @throws ProductNotFoundException
     * @throws InvalidImageException o arquivo não é uma imagem aceita
     */
    public function setImage(int $id, UploadedFile $file): ProductOutput;

    /**
     * Remove a imagem do produto.
     *
     * @throws ProductNotFoundException
     */
    public function removeImage(int $id): ProductOutput;
}
