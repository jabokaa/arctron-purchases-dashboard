<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\PurchaseFilterInput;
use App\Dto\PurchaseInput;
use App\Dto\PurchaseOutput;
use App\Service\PurchaseSearchServiceInterface;
use App\Service\PurchaseServiceInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Endpoints de compras.
 *
 * O controller fica "magro": só recebe a requisição e devolve a resposta.
 * A regra de negócio fica nos services (PurchaseService para gravar, PurchaseSearchService para consultar),
 * e os erros (422, 409, 500...) são convertidos em JSON pelo ApiExceptionListener.
 */
#[Route('/api/purchases', name: 'api_purchases_', format: 'json')]
final class PurchaseController extends AbstractController
{
    public function __construct(
        private readonly PurchaseServiceInterface $purchaseService,
        private readonly PurchaseSearchServiceInterface $purchaseSearchService,
    ) {
    }

    /**
     * Consulta compras com filtros opcionais na query string, paginadas.
     *
     * O #[MapQueryString] converte a query string (snake_case) em PurchaseFilterInput e valida.
     * Sem nenhum parâmetro, usa o objeto padrão (sem filtros, página 1).
     * Filtro inválido → 422 (por padrão o Symfony usaria 404, por isso o validationFailedStatusCode).
     */
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        PurchaseFilterInput $filter = new PurchaseFilterInput(),
    ): JsonResponse {
        return $this->json($this->purchaseSearchService->search($filter));
    }

    /**
     * Detalhe de uma compra: cliente (com documento), itens, observações e canal.
     *
     * O requirements aceita só números no {id}: /api/purchases/abc nem chega aqui (vira 404 de rota).
     * Compra inexistente: o service lança PurchaseNotFoundException, que vira 404.
     */
    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        return $this->json($this->purchaseSearchService->find($id));
    }

    /**
     * Recebe uma compra.
     *
     * O #[MapRequestPayload] faz, antes do método rodar:
     *  1. lê o corpo JSON da requisição;
     *  2. converte para PurchaseInput (snake_case → camelCase);
     *  3. valida as regras #[Assert\...] do DTO.
     * Se algo falhar, a requisição é recusada com 422 e este método nem é chamado.
     *
     * Compra duplicada: o service lança DuplicatePurchaseException, que vira 409.
     */
    #[Route('', name: 'create', methods: ['POST'])]
    public function create(
        #[MapRequestPayload(acceptFormat: 'json')] PurchaseInput $input,
    ): JsonResponse {
        $purchase = $this->purchaseService->create($input);

        return $this->json(PurchaseOutput::fromEntity($purchase), Response::HTTP_CREATED);
    }
}
