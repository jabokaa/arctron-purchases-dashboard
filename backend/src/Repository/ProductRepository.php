<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Product;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Product>
 */
class ProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Product::class);
    }

    /**
     * Busca um produto pelo código que ele tem em um canal de venda.
     * O par (canal, external_id) é único no banco, então retorna no máximo um produto.
     */
    public function findOneBySalesChannelAndExternalId(string $salesChannelCode, string $externalId): ?Product
    {
        return $this->createQueryBuilder('p')
            ->join('p.externalIds', 'e')
            ->join('e.salesChannel', 'c')
            ->where('c.code = :channel')
            ->andWhere('e.externalId = :externalId')
            ->setParameter('channel', $salesChannelCode)
            ->setParameter('externalId', $externalId)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
