<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Product;
use App\Entity\PurchaseItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PurchaseItem>
 */
class PurchaseItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PurchaseItem::class);
    }

    /**
     * Indica se o produto já apareceu em alguma compra (usado para impedir que um produto vendido seja removido).
     */
    public function existsForProduct(Product $product): bool
    {
        return null !== $this->createQueryBuilder('purchaseItem')
            ->select('purchaseItem.id')
            ->where('purchaseItem.product = :product')
            ->setParameter('product', $product)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Indicadores dos produtos vendidos em todas as compras, calculados pelo banco (uma consulta só):
     * - total: soma de quantidade × valor unitário, ANTES do desconto (em centavos);
     * - unitsSold: quantidade de unidades vendidas;
     * - average: preço médio por unidade (total ÷ unidades), arredondado para o centavo mais próximo.
     *
     * O valor unitário vem de cada item (purchase_items.unit_price), que guarda o preço daquela venda.
     *
     * SQL nativo porque o DQL do Doctrine não tem ROUND. A divisão é feita em NUMERIC para não
     * perder as casas decimais antes de arredondar; o NULLIF evita divisão por zero sem itens.
     *
     * @return array{total: int, average: int, unitsSold: int}
     */
    public function productTotals(): array
    {
        $row = $this->getEntityManager()->getConnection()->fetchAssociative(<<<'SQL'
            SELECT
                COALESCE(SUM(quantity * unit_price), 0) AS total,
                COALESCE(ROUND(SUM(quantity * unit_price)::NUMERIC / NULLIF(SUM(quantity), 0)), 0) AS average,
                COALESCE(SUM(quantity), 0) AS units_sold
            FROM purchase_items
        SQL);

        return [
            'total' => (int) $row['total'],
            'average' => (int) $row['average'],
            'unitsSold' => (int) $row['units_sold'],
        ];
    }
}
