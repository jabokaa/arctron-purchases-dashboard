<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Dto\ReportTimelineOutput;
use App\Enum\TimelineInterval;
use App\Repository\PurchaseItemRepository;
use App\Repository\PurchaseRepository;
use App\Service\ReportService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Testes unitários do ReportService::timeline() (evolução das compras no tempo).
 *
 * O que é testado: se o service repassa o intervalo ao repositório e transforma cada período
 * devolvido pelo banco no formato da API: rótulo (2026-11-21, 2026-W47, 2026-11), data de início,
 * data de fim do período e os totais, na mesma ordem.
 *
 * Como é testado:
 * - É um teste unitário puro (TestCase): o Symfony não é iniciado e o banco não é acessado.
 * - O PurchaseRepository é um MOCK (quando o teste confere a chamada) ou STUB (quando só devolve dados).
 *   O agrupamento por período em si é feito pelo banco e testado no ReportTimelineTest (integração).
 */
final class ReportTimelineServiceTest extends TestCase
{
    /**
     * O intervalo pedido deve ser repassado ao repositório.
     * Espera uma única chamada a timeline() com o mesmo intervalo, e o intervalo na resposta.
     */
    public function testPassesIntervalToRepository(): void
    {
        $purchases = $this->createMock(PurchaseRepository::class);
        $purchases->expects($this->once())
            ->method('timeline')
            ->with(TimelineInterval::Week)
            ->willReturn([]);

        $result = (new ReportService($purchases, $this->createStub(PurchaseItemRepository::class)))->timeline(TimelineInterval::Week);

        self::assertSame('week', $result->interval);
    }

    /**
     * Nenhuma compra (o repositório devolve lista vazia).
     * Espera a lista de períodos vazia.
     */
    public function testReturnsEmptyListWhenThereAreNoPurchases(): void
    {
        self::assertSame([], $this->timeline(TimelineInterval::Day, [])->data);
    }

    /**
     * Dois períodos, um deles sem compras.
     * Espera os totais de cada período no lugar certo e na mesma ordem do repositório.
     */
    public function testKeepsTotalsAndOrderOfEachPeriod(): void
    {
        $data = $this->timeline(TimelineInterval::Month, [
            self::period('2026-10-01', count: 0, amount: 0, discount: 0),
            self::period('2026-11-01', count: 3, amount: 29409, discount: 600),
        ])->data;

        self::assertSame([
            ['period' => '2026-10', 'start' => '2026-10-01', 'end' => '2026-10-31', 'purchases_count' => 0, 'amount' => 0, 'discount' => 0],
            ['period' => '2026-11', 'start' => '2026-11-01', 'end' => '2026-11-30', 'purchases_count' => 3, 'amount' => 29409, 'discount' => 600],
        ], $data);
    }

    /**
     * Para cada caso do provider, confere o rótulo e as datas de início e fim do período,
     * a partir da data de início devolvida pelo banco.
     */
    #[DataProvider('periodLabelProvider')]
    public function testBuildsPeriodLabelAndDates(TimelineInterval $interval, string $periodStart, string $label, string $start, string $end): void
    {
        $period = $this->timeline($interval, [self::period($periodStart)])->data[0];

        self::assertSame(['period' => $label, 'start' => $start, 'end' => $end], array_intersect_key($period, ['period' => 1, 'start' => 1, 'end' => 1]));
    }

    /**
     * @return iterable<string, array{TimelineInterval, string, string, string, string}>
     */
    public static function periodLabelProvider(): iterable
    {
        // Dia: rótulo é a própria data; começa e termina no mesmo dia
        yield 'dia' => [TimelineInterval::Day, '2026-11-21', '2026-11-21', '2026-11-21', '2026-11-21'];

        // Semana: rótulo no padrão ISO (ano-Wnúmero); vai de segunda a domingo
        yield 'semana' => [TimelineInterval::Week, '2026-11-16', '2026-W47', '2026-11-16', '2026-11-22'];

        // Semana que atravessa o ano: 28/12/2026 a 03/01/2027 é a semana 53 de 2026 (ano ISO, não o do calendário)
        yield 'semana na virada do ano' => [TimelineInterval::Week, '2026-12-28', '2026-W53', '2026-12-28', '2027-01-03'];

        // Primeira semana ISO de 2027 começa em 04/01
        yield 'primeira semana do ano' => [TimelineInterval::Week, '2027-01-04', '2027-W01', '2027-01-04', '2027-01-10'];

        // Mês: rótulo ano-mês; termina no último dia do mês
        yield 'mês de 30 dias' => [TimelineInterval::Month, '2026-11-01', '2026-11', '2026-11-01', '2026-11-30'];

        // Mês de 31 dias
        yield 'mês de 31 dias' => [TimelineInterval::Month, '2026-12-01', '2026-12', '2026-12-01', '2026-12-31'];

        // Fevereiro de ano não bissexto termina no dia 28
        yield 'fevereiro' => [TimelineInterval::Month, '2027-02-01', '2027-02', '2027-02-01', '2027-02-28'];

        // Fevereiro de ano bissexto termina no dia 29
        yield 'fevereiro bissexto' => [TimelineInterval::Month, '2028-02-01', '2028-02', '2028-02-01', '2028-02-29'];
    }

    // =========================================================================
    // Auxiliares
    // =========================================================================

    /**
     * @param list<array{periodStart: \DateTimeImmutable, count: int, amount: int, discount: int}> $periods
     */
    private function timeline(TimelineInterval $interval, array $periods): ReportTimelineOutput
    {
        $purchases = $this->createStub(PurchaseRepository::class);
        $purchases->method('timeline')->willReturn($periods);

        return (new ReportService($purchases, $this->createStub(PurchaseItemRepository::class)))->timeline($interval);
    }

    /**
     * Período no formato devolvido pelo repositório.
     *
     * @return array{periodStart: \DateTimeImmutable, count: int, amount: int, discount: int}
     */
    private static function period(string $start, int $count = 1, int $amount = 1000, int $discount = 0): array
    {
        return [
            'periodStart' => new \DateTimeImmutable($start, new \DateTimeZone('UTC')),
            'count' => $count,
            'amount' => $amount,
            'discount' => $discount,
        ];
    }
}
