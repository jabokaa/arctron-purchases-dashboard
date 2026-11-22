<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Seeder\DatabaseSeeder;
use App\Seeder\SeederAwareMigration;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Dados iniciais: usuário padrão (DEFAULT_USER_* do .env), 11 produtos de exemplo com imagem e 40 compras de exemplo.
 *
 * Os seeders rodam pela aplicação (Doctrine ORM e services), com o mapeamento atual das entidades.
 * Por isso esta migration deve continuar sendo a última que mexe em dados: se uma migration futura
 * adicionar uma coluna obrigatória numa dessas tabelas, ela precisa ter uma data anterior a esta, ou ter valor padrão.
 */
final class Version20261122130000 extends AbstractMigration implements SeederAwareMigration
{
    private ?DatabaseSeeder $seeder = null;

    public function setSeeder(DatabaseSeeder $seeder): void
    {
        $this->seeder = $seeder;
    }

    public function getDescription(): string
    {
        return 'Dados iniciais: usuário padrão, produtos e compras de exemplo';
    }

    public function up(Schema $schema): void
    {
        if (null === $this->seeder) {
            throw new \LogicException('DatabaseSeeder não foi entregue à migration (ver App\Seeder\SeederMigrationFactory).');
        }

        $this->seeder->run();
    }

    public function down(Schema $schema): void
    {
        // Os dados de exemplo ficam no banco: apagar daqui levaria junto o que foi cadastrado em cima deles.
        $this->write('Os dados iniciais não são removidos.');
    }
}
