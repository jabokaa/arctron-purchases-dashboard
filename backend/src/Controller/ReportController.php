<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\TimelineFilterInput;
use App\Service\ReportServiceInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Endpoints de relatórios do dashboard.
 *
 * O controller fica "magro": só recebe a requisição e devolve a resposta. Os cálculos ficam
 * no ReportService, e os erros são convertidos em JSON pelo ApiExceptionListener.
 */
#[Route('/api/reports', name: 'api_reports_', format: 'json')]
final class ReportController extends AbstractController
{
    public function __construct(
        private readonly ReportServiceInterface $reportService,
    ) {
    }

    /**
     * Indicadores gerais: quantidade de compras, valor total e médio das compras,
     * dos descontos e dos produtos.
     */
    #[Route('/summary', name: 'summary', methods: ['GET'])]
    public function summary(): JsonResponse
    {
        return $this->json($this->reportService->summary());
    }

    /**
     * Evolução das compras no tempo, agrupadas por dia, semana ou mês (?interval=day|week|month).
     *
     * Sem o parâmetro, usa "day". Intervalo inválido → 422
     * (por padrão o #[MapQueryString] usaria 404, por isso o validationFailedStatusCode).
     */
    #[Route('/timeline', name: 'timeline', methods: ['GET'])]
    public function timeline(
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        TimelineFilterInput $filter = new TimelineFilterInput(),
    ): JsonResponse {
        return $this->json($this->reportService->timeline($filter->interval()));
    }
}
