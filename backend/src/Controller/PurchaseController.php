<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\PurchaseInput;
use App\Dto\PurchaseOutput;
use App\Service\PurchaseService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Endpoints de compras.
 *
 * O controller fica "magro": só recebe a requisição e devolve a resposta.
 * A regra de negócio (salvar cliente e compra) fica no PurchaseService,
 * e os erros (422, 409, 500...) são convertidos em JSON pelo ApiExceptionListener.
 */
#[Route('/api/purchases', name: 'api_purchases_', format: 'json')]
final class PurchaseController extends AbstractController
{
    public function __construct(
        private readonly PurchaseService $purchaseService,
    ) {
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
