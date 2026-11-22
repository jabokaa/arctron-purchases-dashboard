<?php

declare(strict_types=1);

namespace App\Repository;

use App\Dto\PurchaseFilterInput;
use App\Entity\CompanyCustomer;
use App\Entity\IndividualCustomer;
use App\Entity\Purchase;
use App\Enum\TimelineInterval;
use App\Util\DocumentNumber;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\OffsetPaginator;
use Doctrine\ORM\Tools\Pagination\Window;
use Doctrine\ORM\Tools\Pagination\WindowPage;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Purchase>
 */
class PurchaseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Purchase::class);
    }

    /**
     * Indicadores de todas as compras, calculados pelo banco (uma consulta só), em centavos:
     * quantidade de compras, soma e média dos valores, soma e média dos descontos.
     *
     * SQL nativo porque o DQL do Doctrine não tem ROUND: as médias são arredondadas
     * para o centavo mais próximo no próprio PostgreSQL (meio centavo arredonda para cima).
     * Sem compras, SUM e AVG devolvem NULL; o COALESCE troca por 0.
     *
     * @return array{count: int, amountTotal: int, amountAverage: int, discountTotal: int, discountAverage: int}
     */
    public function totals(): array
    {
        $row = $this->getEntityManager()->getConnection()->fetchAssociative(<<<'SQL'
            SELECT
                COUNT(*)                         AS count,
                COALESCE(SUM(amount), 0)         AS amount_total,
                COALESCE(ROUND(AVG(amount)), 0)  AS amount_average,
                COALESCE(SUM(discount), 0)       AS discount_total,
                COALESCE(ROUND(AVG(discount)), 0) AS discount_average
            FROM purchases
        SQL);

        return [
            'count' => (int) $row['count'],
            'amountTotal' => (int) $row['amount_total'],
            'amountAverage' => (int) $row['amount_average'],
            'discountTotal' => (int) $row['discount_total'],
            'discountAverage' => (int) $row['discount_average'],
        ];
    }

    /**
     * Compras agrupadas por período (dia, semana ou mês), do mais antigo para o mais recente.
     *
     * Como funciona (SQL nativo do PostgreSQL, uma consulta só):
     * 1. bounds: início do primeiro e do último período com compras (date_trunc arredonda a data
     *    para o início do dia, da semana — segunda-feira — ou do mês);
     * 2. periods: todos os períodos entre esses dois (generate_series), inclusive os sem compras;
     * 3. junta as compras em cada período; período sem compras fica com 0 (COALESCE).
     * Sem nenhuma compra, devolve lista vazia.
     *
     * As datas são as do banco (UTC).
     *
     * @return list<array{periodStart: \DateTimeImmutable, count: int, amount: int, discount: int}>
     */
    public function timeline(TimelineInterval $interval): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(<<<'SQL'
            WITH bounds AS (
                SELECT
                    date_trunc(:interval, MIN(purchased_at)) AS first_period,
                    date_trunc(:interval, MAX(purchased_at)) AS last_period
                FROM purchases
            ),
            periods AS (
                SELECT generate_series(first_period, last_period, CAST('1 ' || :interval AS INTERVAL)) AS period_start
                FROM bounds
                WHERE first_period IS NOT NULL
            )
            SELECT
                periods.period_start,
                COUNT(purchases.id)                AS count,
                COALESCE(SUM(purchases.amount), 0)   AS amount,
                COALESCE(SUM(purchases.discount), 0) AS discount
            FROM periods
            LEFT JOIN purchases ON date_trunc(:interval, purchases.purchased_at) = periods.period_start
            GROUP BY periods.period_start
            ORDER BY periods.period_start
        SQL, ['interval' => $interval->value]);

        return array_map(static fn (array $row): array => [
            'periodStart' => new \DateTimeImmutable($row['period_start'], new \DateTimeZone('UTC')),
            'count' => (int) $row['count'],
            'amount' => (int) $row['amount'],
            'discount' => (int) $row['discount'],
        ], $rows);
    }

    /**
     * Busca uma compra pelo id, já com canal, cliente, itens e produtos (uma consulta só).
     * Retorna null se a compra não existir.
     */
    public function findWithDetails(int $id): ?Purchase
    {
        return $this->createQueryBuilder('purchase')
            ->addSelect('salesChannel', 'customer', 'purchaseItem', 'product')
            ->join('purchase.salesChannel', 'salesChannel')
            ->join('purchase.customer', 'customer')
            ->leftJoin('purchase.items', 'purchaseItem')
            ->leftJoin('purchaseItem.product', 'product')
            ->where('purchase.id = :id')
            ->setParameter('id', $id)
            ->orderBy('purchaseItem.id', 'ASC')
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Busca compras pelos filtros, paginadas, das mais recentes para as mais antigas.
     *
     * Já carrega junto (fetch join) canal, cliente, itens e produtos, para montar a resposta
     * sem uma consulta extra por compra.
     *
     * @return array{items: list<Purchase>, total: int}
     */
    public function search(PurchaseFilterInput $filter): array
    {
        $queryBuilder = $this->createQueryBuilder('purchase')
            ->addSelect('salesChannel', 'customer', 'purchaseItem', 'product')
            ->join('purchase.salesChannel', 'salesChannel')
            ->join('purchase.customer', 'customer')
            ->leftJoin('purchase.items', 'purchaseItem')
            ->leftJoin('purchaseItem.product', 'product')
            // Os dados próprios de cada tipo de cliente ficam em tabelas separadas (herança JOINED):
            // estes joins permitem filtrar por nome/documento de pessoa física e jurídica.
            ->leftJoin(IndividualCustomer::class, 'individualCustomer', 'WITH', 'individualCustomer.id = customer.id')
            ->leftJoin(CompanyCustomer::class, 'companyCustomer', 'WITH', 'companyCustomer.id = customer.id')
            ->orderBy('purchase.purchasedAt', 'DESC')
            ->addOrderBy('purchase.id', 'DESC');

        $this->applyFilters($queryBuilder, $filter);

        $page = $this->paginate($queryBuilder, $filter->page, $filter->perPage);

        return [
            'items' => array_values($page->getItems()),
            'total' => $page->getTotalCount(),
        ];
    }

    /**
     * O OffsetPaginator faz a paginação certa mesmo com o join dos itens (que repete a compra em várias linhas)
     * e conta o total de compras que batem com os filtros.
     *
     * @return WindowPage<Purchase>
     */
    private function paginate(QueryBuilder $queryBuilder, int $pageNumber, int $pageSize): WindowPage
    {
        return (new OffsetPaginator(fetchJoinCollection: true))
            ->paginate($queryBuilder, Window::fromPageNumberAndSize($pageNumber, $pageSize));
    }

    private function applyFilters(QueryBuilder $queryBuilder, PurchaseFilterInput $filter): void
    {
        if (self::filled($filter->referenceCode)) {
            $queryBuilder->andWhere('LOWER(purchase.referenceCode) LIKE :referenceCode')
                ->setParameter('referenceCode', self::contains($filter->referenceCode));
        }

        if (self::filled($filter->customerName)) {
            $queryBuilder->andWhere(
                'LOWER(individualCustomer.fullName) LIKE :customerName'
                .' OR LOWER(companyCustomer.legalName) LIKE :customerName'
                .' OR LOWER(companyCustomer.tradeName) LIKE :customerName',
            )
                ->setParameter('customerName', self::contains($filter->customerName));
        }

        if (self::filled($filter->customerEmail)) {
            $queryBuilder->andWhere('LOWER(customer.email) LIKE :customerEmail')
                ->setParameter('customerEmail', self::contains($filter->customerEmail));
        }

        if (self::filled($filter->customerDocument)) {
            // Tira a máscara (mantendo letras do CNPJ alfanumérico) e compara com CPF ou CNPJ
            $queryBuilder->andWhere('individualCustomer.cpf = :document OR companyCustomer.cnpj = :document')
                ->setParameter('document', DocumentNumber::normalizeCnpj($filter->customerDocument));
        }

        if (null !== $startDate = $filter->startDate()) {
            $queryBuilder->andWhere('purchase.purchasedAt >= :dateFrom')
                ->setParameter('dateFrom', $startDate);
        }

        if (null !== $endDate = $filter->endDateExclusive()) {
            $queryBuilder->andWhere('purchase.purchasedAt < :dateTo')
                ->setParameter('dateTo', $endDate);
        }

        if (null !== $filter->amountMin) {
            $queryBuilder->andWhere('purchase.amount >= :amountMin')
                ->setParameter('amountMin', $filter->amountMin);
        }

        if (null !== $filter->amountMax) {
            $queryBuilder->andWhere('purchase.amount <= :amountMax')
                ->setParameter('amountMax', $filter->amountMax);
        }
    }

    private static function filled(?string $value): bool
    {
        return null !== $value && '' !== trim($value);
    }

    /**
     * Monta o padrão do LIKE para "contém", sem diferenciar maiúsculas.
     * Escapa % e _ digitados pelo usuário, para não virarem curingas.
     */
    private static function contains(string $value): string
    {
        return '%'.addcslashes(mb_strtolower(trim($value)), '%_\\').'%';
    }
}
