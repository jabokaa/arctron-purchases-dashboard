<?php

declare(strict_types=1);

namespace App\Seeder;

use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Version\MigrationFactory;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;

/**
 * Cria as migrations como o Doctrine faz e, nas que implementam SeederAwareMigration, entrega o DatabaseSeeder.
 *
 * As migrations não são serviços do Symfony (ficam fora do src/), então esta é a forma de passar um serviço a elas.
 * O seeder só é montado quando uma migration pede (closure), para não carregar os seeders em toda migration.
 */
#[AsDecorator('doctrine.migrations.migrations_factory')]
final class SeederMigrationFactory implements MigrationFactory
{
    /**
     * @param \Closure(): DatabaseSeeder $seeder
     */
    public function __construct(
        #[AutowireDecorated]
        private readonly MigrationFactory $inner,
        #[AutowireServiceClosure(DatabaseSeeder::class)]
        private readonly \Closure $seeder,
    ) {
    }

    public function createVersion(string $migrationClassName): AbstractMigration
    {
        $migration = $this->inner->createVersion($migrationClassName);

        if ($migration instanceof SeederAwareMigration) {
            $migration->setSeeder(($this->seeder)());
        }

        return $migration;
    }
}
