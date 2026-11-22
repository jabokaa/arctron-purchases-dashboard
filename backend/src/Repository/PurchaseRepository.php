<?php

declare(strict_types=1);

namespace App\Repository;

use App\Dto\PurchaseFilterInput;
use App\Entity\CompanyCustomer;
use App\Entity\IndividualCustomer;
use App\Entity\Purchase;
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
     * Busca uma compra pelo id, já com canal, cliente, itens e produtos (uma consulta só).
     * Retorna null se a compra não existir.
     */
    public function findWithDetails(int $id): ?Purchase
    {
        return $this->createQueryBuilder('purchase')
            ->addSelect('salesChannel', 'customer', 'item', 'product')
            ->join('purchase.salesChannel', 'salesChannel')
            ->join('purchase.customer', 'customer')
            ->leftJoin('purchase.items', 'item')
            ->leftJoin('item.product', 'product')
            ->where('purchase.id = :id')
            ->setParameter('id', $id)
            ->orderBy('item.id', 'ASC')
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
            ->addSelect('salesChannel', 'customer', 'item', 'product')
            ->join('purchase.salesChannel', 'salesChannel')
            ->join('purchase.customer', 'customer')
            ->leftJoin('purchase.items', 'item')
            ->leftJoin('item.product', 'product')
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
