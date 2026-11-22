<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\ReportSummaryOutput;

/**
 * Contrato dos relatórios do dashboard.
 *
 * O controller depende desta interface, o que permite trocar a implementação por um mock nos testes.
 */
interface ReportServiceInterface
{
    public function summary(): ReportSummaryOutput;
}
