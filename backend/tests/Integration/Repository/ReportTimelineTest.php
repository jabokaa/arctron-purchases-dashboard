<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\IndividualCustomer;
use App\Entity\Purchase;
use App\Entity\SalesChannel;
use App\Enum\TimelineInterval;
use App\Repository\PurchaseRepository;
use App\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Testes do PurchaseRepository::timeline() (compras agrupadas por dia, semana ou mês).
 *
 * O que é testado: se o banco agrupa cada compra no período certo (incluindo os limites, como
 * 23:59:59 e a virada para segunda-feira), soma quantidade, valor e desconto de cada período,
 * preenche com zeros os períodos sem compras e devolve tudo do mais antigo para o mais recente.
 *
 * Por que fica na pasta de integração: o agrupamento é feito pelo PostgreSQL
 * (date_trunc e generate_series); só o banco de teste real (app_test) prova que a consulta está certa.
 *
 * Como é testado: antes de cada teste o banco é esvaziado (IntegrationTestCase) e cada teste
 * grava, direto pelo Doctrine, as compras de que precisa (datas em UTC, como no banco).
 */
final class ReportTimelineTest extends IntegrationTestCase
{
    /**
     * Banco sem compras.
     * Espera lista vazia em qualquer intervalo (não há primeiro nem último período).
     */
    public function testReturnsEmptyListWhenThereAreNoPurchases(): void
    {
        foreach (TimelineInterval::cases() as $interval) {
            self::assertSame([], $this->repository()->timeline($interval), $interval->value);
        }
    }

    /**
     * Compras em 3 dias diferentes, com um dia sem compras no meio.
     * Espera um período por dia, do primeiro ao último, com o dia vazio zerado,
     * e as compras do mesmo dia somadas.
     */
    public function testGroupsByDayAndFillsDaysWithoutPurchases(): void
    {
        $this->createPurchases([
            ['P1', '2026-11-01 09:00:00', 5000, 500],
            ['P2', '2026-11-01 18:00:00', 2000, 0],
            ['P3', '2026-11-03 12:00:00', 3000, 100],
        ]);

        self::assertSame([
            ['2026-11-01', 2, 7000, 500],
            ['2026-11-02', 0, 0, 0],
            ['2026-11-03', 1, 3000, 100],
        ], $this->periods(TimelineInterval::Day));
    }

    /**
     * Limite do dia: 23:59:59 ainda é o mesmo dia; 00:00:00 já é o dia seguinte.
     */
    public function testDayBoundary(): void
    {
        $this->createPurchases([
            ['P1', '2026-11-01 23:59:59', 1000, 0],
            ['P2', '2026-11-02 00:00:00', 2000, 0],
        ]);

        self::assertSame([
            ['2026-11-01', 1, 1000, 0],
            ['2026-11-02', 1, 2000, 0],
        ], $this->periods(TimelineInterval::Day));
    }

    /**
     * Semanas começam na segunda-feira (padrão ISO):
     * a compra de domingo (01/11) fica na semana de 26/10; a de segunda (02/11) abre a semana seguinte.
     * A semana de 09/11, sem compras, aparece zerada.
     */
    public function testGroupsByWeekStartingOnMonday(): void
    {
        $this->createPurchases([
            ['P1', '2026-10-26 08:00:00', 1000, 0],   // segunda
            ['P2', '2026-11-01 22:00:00', 2000, 200], // domingo da mesma semana
            ['P3', '2026-11-02 00:00:00', 3000, 0],   // segunda da semana seguinte
            ['P4', '2026-11-16 10:00:00', 4000, 0],
        ]);

        self::assertSame([
            ['2026-10-26', 2, 3000, 200],
            ['2026-11-02', 1, 3000, 0],
            ['2026-11-09', 0, 0, 0],
            ['2026-11-16', 1, 4000, 0],
        ], $this->periods(TimelineInterval::Week));
    }

    /**
     * Agrupamento por mês, com um mês sem compras no meio e a virada do ano.
     * O último instante do mês (31/10 23:59:59) fica em outubro.
     */
    public function testGroupsByMonthAcrossYears(): void
    {
        $this->createPurchases([
            ['P1', '2026-10-31 23:59:59', 1000, 0],
            ['P2', '2026-10-01 00:00:00', 500, 50],
            ['P3', '2026-12-15 12:00:00', 2000, 0],
            ['P4', '2027-01-01 00:00:00', 3000, 300],
        ]);

        self::assertSame([
            ['2026-10-01', 2, 1500, 50],
            ['2026-11-01', 0, 0, 0],
            ['2026-12-01', 1, 2000, 0],
            ['2027-01-01', 1, 3000, 300],
        ], $this->periods(TimelineInterval::Month));
    }

    /**
     * Todas as compras no mesmo período.
     * Espera um único período com tudo somado.
     */
    public function testSinglePeriod(): void
    {
        $this->createPurchases([
            ['P1', '2026-11-05 10:00:00', 1000, 100],
            ['P2', '2026-11-20 10:00:00', 2000, 200],
        ]);

        self::assertSame([['2026-11-01', 2, 3000, 300]], $this->periods(TimelineInterval::Month));
    }

    // =========================================================================
    // Auxiliares
    // =========================================================================

    private function repository(): PurchaseRepository
    {
        return static::getContainer()->get(PurchaseRepository::class);
    }

    /**
     * Linha do tempo em formato compacto para comparar: [início do período, compras, valor, desconto].
     *
     * @return list<array{string, int, int, int}>
     */
    private function periods(TimelineInterval $interval): array
    {
        return array_map(
            static fn (array $period): array => [$period['periodStart']->format('Y-m-d'), $period['count'], $period['amount'], $period['discount']],
            $this->repository()->timeline($interval),
        );
    }

    /**
     * Grava as compras direto pelo Doctrine. Para a linha do tempo só importam data, valor e desconto,
     * então as compras não têm itens.
     *
     * @param list<array{string, string, int, int}> $purchases [código, data e hora em UTC, valor, desconto]
     */
    private function createPurchases(array $purchases): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $channel = $em->getRepository(SalesChannel::class)->findOneBy(['code' => SalesChannel::API]);
        $customer = (new IndividualCustomer())->setFullName('João Silva')->setCpf('52998224725')->setEmail('joao@email.com');
        $em->persist($customer);

        foreach ($purchases as [$referenceCode, $purchasedAt, $amount, $discount]) {
            $em->persist(
                (new Purchase())
                    ->setReferenceCode($referenceCode)
                    ->setCustomer($customer)
                    ->setSalesChannel($channel)
                    ->setAmount($amount)
                    ->setDiscount($discount)
                    ->setPurchasedAt(new \DateTimeImmutable($purchasedAt, new \DateTimeZone('UTC'))),
            );
        }

        $em->flush();
        $em->clear();
    }
}
