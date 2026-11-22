<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\PurchaseInput;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Endpoints de compras.
 *
 * O controller fica "magro": só recebe a requisição e devolve a resposta.
 * A regra de negócio (salvar cliente, produtos e compra) fica no PurchaseService (passo 5).
 */
#[Route('/api/purchases', name: 'api_purchases_')]
final class PurchaseController extends AbstractController
{
    /**
     * Recebe uma compra.
     *
     * O #[MapRequestPayload] faz, antes do método rodar:
     *  1. lê o corpo JSON da requisição;
     *  2. converte para PurchaseInput (snake_case → camelCase);
     *  3. valida as regras #[Assert\...] do DTO.
     * Se algo falhar, o Symfony responde 422 sozinho e este método nem é chamado.
     */
    #[Route('', name: 'create', methods: ['POST'])]
    public function create(
        #[MapRequestPayload(acceptFormat: 'json')] PurchaseInput $input,
    ): JsonResponse {
        // TODO (passo 5): chamar o PurchaseService para salvar a compra e retornar 201 Created.
        return $this->json([
            'message' => 'Payload válido. A gravação será implementada no passo 5.',
            'data' => $input,
        ], Response::HTTP_OK);
    }
}
