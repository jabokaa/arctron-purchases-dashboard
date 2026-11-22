<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\ProductFilterInput;
use App\Dto\ProductSaveInput;
use App\Service\ProductServiceInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Cadastro de produtos (CRUD do catálogo).
 *
 * O controller fica "magro": só recebe a requisição e devolve a resposta. A regra fica no ProductService,
 * e os erros (404, 409, 422...) são convertidos em JSON pelo ApiExceptionListener.
 *
 * Os dados de entrada são validados antes de o método rodar (#[MapRequestPayload] / #[MapQueryString]):
 * se algo estiver errado, a resposta é 422 e o service nem é chamado.
 */
#[Route('/api/products', name: 'api_products_', format: 'json')]
final class ProductController extends AbstractController
{
    public function __construct(
        private readonly ProductServiceInterface $productService,
    ) {
    }

    /**
     * Lista o catálogo, em ordem alfabética e paginado.
     * Parâmetros: search (parte do nome ou do código interno), page e per_page (máx. 100).
     */
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        ProductFilterInput $filter = new ProductFilterInput(),
    ): JsonResponse {
        return $this->json($this->productService->list($filter));
    }

    /**
     * Detalhe de um produto. Inexistente → 404.
     */
    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        return $this->json($this->productService->find($id));
    }

    /**
     * Cria um produto → 201. Código interno já usado → 409.
     */
    #[Route('', name: 'create', methods: ['POST'])]
    public function create(
        #[MapRequestPayload(acceptFormat: 'json')] ProductSaveInput $input,
    ): JsonResponse {
        return $this->json($this->productService->create($input), Response::HTTP_CREATED);
    }

    /**
     * Atualiza um produto (substitui todos os campos) → 200. Inexistente → 404; código interno já usado → 409.
     */
    #[Route('/{id}', name: 'update', requirements: ['id' => '\d+'], methods: ['PUT'])]
    public function update(
        int $id,
        #[MapRequestPayload(acceptFormat: 'json')] ProductSaveInput $input,
    ): JsonResponse {
        return $this->json($this->productService->update($id, $input));
    }

    /**
     * Remove um produto → 204 (sem corpo). Inexistente → 404; produto que já foi vendido → 409.
     */
    #[Route('/{id}', name: 'delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function delete(int $id): Response
    {
        $this->productService->delete($id);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
