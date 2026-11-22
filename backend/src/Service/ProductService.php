<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\ProductFilterInput;
use App\Dto\ProductListOutput;
use App\Dto\ProductOutput;
use App\Dto\ProductSaveInput;
use App\Entity\Product;
use App\Exception\DuplicateProductCodeException;
use App\Exception\ProductHasSalesException;
use App\Exception\ProductNotFoundException;
use App\Repository\ProductRepository;
use App\Repository\PurchaseItemRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Cadastro de produtos (CRUD do catálogo).
 *
 * Quando estes métodos são chamados, os dados já foram validados pelo controller
 * (#[MapRequestPayload] / #[MapQueryString]).
 */
final class ProductService implements ProductServiceInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProductRepository $products,
        private readonly PurchaseItemRepository $purchaseItems,
    ) {}

    public function list(ProductFilterInput $filter): ProductListOutput
    {
        $result = $this->products->search($filter);

        return new ProductListOutput(
            data: array_map(ProductOutput::fromEntity(...), $result['items']),
            meta: [
                'page' => $filter->page,
                'per_page' => $filter->perPage,
                'total' => $result['total'],
                'total_pages' => (int) ceil($result['total'] / $filter->perPage),
            ],
        );
    }

    public function find(int $id): ProductOutput
    {
        return ProductOutput::fromEntity($this->get($id));
    }

    public function create(ProductSaveInput $input): ProductOutput
    {
        $product = new Product();
        $this->fill($product, $input);

        $this->em->persist($product);
        $this->save($product);

        return ProductOutput::fromEntity($product);
    }

    public function update(int $id, ProductSaveInput $input): ProductOutput
    {
        $product = $this->get($id);
        $this->fill($product, $input);

        $this->save($product);

        return ProductOutput::fromEntity($product);
    }

    public function delete(int $id): void
    {
        $product = $this->get($id);

        // As compras antigas guardam qual produto foi vendido; por isso produto vendido não pode sumir
        if ($this->purchaseItems->existsForProduct($product)) {
            throw new ProductHasSalesException($id);
        }

        // Os códigos do produto em cada canal (product_external_ids) são removidos junto
        $this->em->remove($product);
        $this->em->flush();
    }

    private function get(int $id): Product
    {
        return $this->products->findWithExternalIds($id) ?? throw new ProductNotFoundException($id);
    }

    /**
     * Copia os dados recebidos para o produto. Código interno e imagem vazios viram null.
     *
     * @throws DuplicateProductCodeException
     */
    private function fill(Product $product, ProductSaveInput $input): void
    {
        $internalCode = self::nullIfEmpty($input->internalCode);

        if (null !== $internalCode) {
            $owner = $this->products->findOneBy(['internalCode' => $internalCode]);

            if (null !== $owner && $owner !== $product) {
                throw new DuplicateProductCodeException($internalCode);
            }
        }

        $product
            ->setName(trim($input->name))
            ->setInternalCode($internalCode)
            ->setImageUrl(self::nullIfEmpty($input->imageUrl));
    }

    /**
     * Grava as mudanças. Se outra requisição gravou o mesmo código interno ao mesmo tempo
     * (depois da checagem do fill), o UNIQUE do banco recusa, e o erro vira DuplicateProductCodeException.
     */
    private function save(Product $product): void
    {
        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException $e) {
            throw new DuplicateProductCodeException((string) $product->getInternalCode());
        }
    }

    private static function nullIfEmpty(?string $value): ?string
    {
        $value = null === $value ? null : trim($value);

        return '' === $value ? null : $value;
    }
}
