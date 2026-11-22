<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Product;
use App\Enum\ProductReportSort;
use App\Enum\SortOrder;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
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
        return $this->createQueryBuilder('product')
            ->join('product.externalIds', 'externalId')
            ->join('externalId.salesChannel', 'salesChannel')
            ->where('salesChannel.code = :channel')
            ->andWhere('externalId.externalId = :externalId')
            ->setParameter('channel', $salesChannelCode)
            ->setParameter('externalId', $externalId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Ranking dos produtos pelas vendas, calculado pelo banco a partir dos itens das compras:
     * - quantitySold: unidades vendidas;
     * - purchasesCount: em quantas compras diferentes o produto aparece;
     * - total: valor vendido, já com o desconto dos itens (soma de purchase_items.total_price), em centavos;
     * - average: preço médio efetivo por unidade (total ÷ unidades), arredondado para o centavo mais próximo.
     * Só entram produtos que foram vendidos.
     *
     * SQL nativo porque o DQL do Doctrine não tem ROUND. O critério e a direção da ordenação vêm de
     * enums com uma lista fixa de expressões, então nenhum texto do usuário entra no SQL.
     * Em caso de empate, ordena pelo id do produto, para a ordem não mudar entre as páginas.
     *
     * @return array{
     *     items: list<array{id: int, name: string, internalCode: string|null, quantitySold: int, purchasesCount: int, total: int, average: int}>,
     *     total: int
     * }
     */
    public function salesRanking(ProductReportSort $sort, SortOrder $order, int $page, int $limit): array
    {
        $connection = $this->getEntityManager()->getConnection();

        $rows = $connection->fetchAllAssociative(
            <<<SQL
                SELECT
                    products.id,
                    products.name,
                    products.internal_code,
                    SUM(purchase_items.quantity)                  AS quantity_sold,
                    COUNT(DISTINCT purchase_items.purchase_id)    AS purchases_count,
                    SUM(purchase_items.total_price)               AS total,
                    ROUND(SUM(purchase_items.total_price)::NUMERIC / SUM(purchase_items.quantity)) AS average
                FROM products
                JOIN purchase_items ON purchase_items.product_id = products.id
                GROUP BY products.id
                ORDER BY {$sort->sql()} {$order->sql()}, products.id ASC
                LIMIT :limit OFFSET :offset
            SQL,
            ['limit' => $limit, 'offset' => ($page - 1) * $limit],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        return [
            'items' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'name' => $row['name'],
                'internalCode' => $row['internal_code'],
                'quantitySold' => (int) $row['quantity_sold'],
                'purchasesCount' => (int) $row['purchases_count'],
                'total' => (int) $row['total'],
                'average' => (int) $row['average'],
            ], $rows),
            // Total de produtos no ranking (vendidos pelo menos uma vez), para a paginação
            'total' => (int) $connection->fetchOne('SELECT COUNT(DISTINCT product_id) FROM purchase_items'),
        ];
    }
}
