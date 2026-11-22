<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\CustomerReportFilterInput;
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

    /**
     * Ranking dos clientes: quantidade de compras, valor total e valor médio por cliente, paginado.
     * Parâmetros: page, limit (máx. 100), sort (total, average, count) e order (asc, desc).
     * Sem parâmetros: os 20 clientes com maior valor total. Top 5 da home: ?limit=5.
     * Parâmetro inválido → 422.
     */
    #[Route('/customers', name: 'customers', methods: ['GET'])]
    public function customers(
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        CustomerReportFilterInput $filter = new CustomerReportFilterInput(),
    ): JsonResponse {
        return $this->json($this->reportService->customers($filter));
    }
}
