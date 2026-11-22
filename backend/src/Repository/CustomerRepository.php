<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Customer;
use App\Enum\CustomerReportSort;
use App\Enum\SortOrder;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Customer>
 */
class CustomerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Customer::class);
    }

    /**
     * Ranking dos clientes pelas compras: quantidade de compras, valor total e valor médio por compra
     * (em centavos, média arredondada para o centavo mais próximo). Só entram clientes com compras.
     *
     * SQL nativo porque o DQL do Doctrine não tem ROUND. O critério e a direção da ordenação vêm de
     * enums com uma lista fixa de expressões, então nenhum texto do usuário entra no SQL.
     * Em caso de empate, ordena pelo id do cliente, para a ordem não mudar entre as páginas.
     *
     * @return array{
     *     items: list<array{id: int, personType: string, email: string|null, fullName: string|null, cpf: string|null,
     *                       legalName: string|null, tradeName: string|null, cnpj: string|null,
     *                       purchasesCount: int, total: int, average: int}>,
     *     total: int
     * }
     */
    public function purchaseRanking(CustomerReportSort $sort, SortOrder $order, int $page, int $limit): array
    {
        $connection = $this->getEntityManager()->getConnection();

        $rows = $connection->fetchAllAssociative(
            <<<SQL
                SELECT
                    customers.id,
                    customers.person_type,
                    customers.email,
                    individual_customers.full_name,
                    individual_customers.cpf,
                    company_customers.legal_name,
                    company_customers.trade_name,
                    company_customers.cnpj,
                    COUNT(purchases.id)           AS purchases_count,
                    SUM(purchases.amount)         AS total,
                    ROUND(AVG(purchases.amount))  AS average
                FROM customers
                JOIN purchases ON purchases.customer_id = customers.id
                LEFT JOIN individual_customers ON individual_customers.id = customers.id
                LEFT JOIN company_customers ON company_customers.id = customers.id
                GROUP BY customers.id, individual_customers.id, company_customers.id
                ORDER BY {$sort->sql()} {$order->sql()}, customers.id ASC
                LIMIT :limit OFFSET :offset
            SQL,
            ['limit' => $limit, 'offset' => ($page - 1) * $limit],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        return [
            'items' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'personType' => $row['person_type'],
                'email' => $row['email'],
                'fullName' => $row['full_name'],
                'cpf' => $row['cpf'],
                'legalName' => $row['legal_name'],
                'tradeName' => $row['trade_name'],
                'cnpj' => $row['cnpj'],
                'purchasesCount' => (int) $row['purchases_count'],
                'total' => (int) $row['total'],
                'average' => (int) $row['average'],
            ], $rows),
            // Total de clientes no ranking (com pelo menos uma compra), para a paginação
            'total' => (int) $connection->fetchOne('SELECT COUNT(DISTINCT customer_id) FROM purchases'),
        ];
    }
}
