<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ProductExternalId;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProductExternalId>
 */
class ProductExternalIdRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProductExternalId::class);
    }
}
