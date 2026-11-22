<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ApiToken;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ApiToken>
 */
class ApiTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApiToken::class);
    }

    /** Busca pelo token recebido no cabeçalho; o hash é calculado aqui */
    public function findOneByPlainToken(string $plainToken): ?ApiToken
    {
        return $this->findOneBy(['tokenHash' => ApiToken::hash($plainToken)]);
    }

    public function save(ApiToken $token): void
    {
        $this->getEntityManager()->persist($token);
        $this->getEntityManager()->flush();
    }

    public function remove(ApiToken $token): void
    {
        $this->getEntityManager()->remove($token);
        $this->getEntityManager()->flush();
    }

    /** Apaga os tokens vencidos de todos os usuários; devolve quantos foram apagados */
    public function deleteExpired(\DateTimeImmutable $now = new \DateTimeImmutable()): int
    {
        return (int) $this->createQueryBuilder('t')
            ->delete()
            ->where('t.expiresAt <= :now')
            ->setParameter('now', $now)
            ->getQuery()
            ->execute();
    }
}
