<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\ReportServiceInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Endpoints de relatórios do dashboard.
 *
 * O controller fica "magro": só devolve a resposta. Os cálculos ficam no ReportService,
 * e os erros são convertidos em JSON pelo ApiExceptionListener.
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
}
