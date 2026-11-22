<?php

declare(strict_types=1);

namespace App\Seeder;

use App\DataFixtures\DefaultUserFixtures;
use App\DataFixtures\ProductFixtures;
use App\DataFixtures\PurchaseFixtures;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Cadastra os dados iniciais do sistema: usuário padrão, produtos de exemplo e compras de exemplo.
 *
 * É chamado pela migration Version20261122130000. Por estar numa migration, roda uma única vez por banco
 * (o Doctrine registra a migration executada em doctrine_migration_versions). Os seeders também conferem o
 * que já existe antes de cadastrar, então rodar de novo não duplica nada.
 */
final class DatabaseSeeder
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DefaultUserFixtures $users,
        private readonly ProductFixtures $products,
        private readonly PurchaseFixtures $purchases,
    ) {
    }

    public function run(): void
    {
        // A ordem importa: as compras usam os produtos cadastrados antes
        $this->users->load($this->em);
        $this->products->load($this->em);
        $this->purchases->load($this->em);
        $this->em->clear();
    }
}
