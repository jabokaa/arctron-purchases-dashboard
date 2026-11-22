<?php

declare(strict_types=1);

namespace App\Seeder;

/**
 * Migration que precisa do DatabaseSeeder. O SeederMigrationFactory entrega o seeder ao criar a migration.
 */
interface SeederAwareMigration
{
    public function setSeeder(DatabaseSeeder $seeder): void;
}
