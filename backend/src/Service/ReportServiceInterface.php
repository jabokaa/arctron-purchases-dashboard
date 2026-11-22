<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\CustomerReportFilterInput;
use App\Dto\ReportRankingOutput;
use App\Dto\ReportSummaryOutput;
use App\Dto\ReportTimelineOutput;
use App\Enum\TimelineInterval;

/**
 * Contrato dos relatórios do dashboard.
 *
 * O controller depende desta interface, o que permite trocar a implementação por um mock nos testes.
 */
interface ReportServiceInterface
{
    public function summary(): ReportSummaryOutput;

    public function timeline(TimelineInterval $interval): ReportTimelineOutput;

    public function customers(CustomerReportFilterInput $filter): ReportRankingOutput;
}
