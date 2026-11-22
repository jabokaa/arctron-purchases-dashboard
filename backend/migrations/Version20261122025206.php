<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Dados iniciais (seed) das tabelas de domínio.
 *
 * O endpoint de compras não recebe canal de venda, mas o banco exige um.
 * - sales_channels: API é o canal usado pelo endpoint (outros canais serão adicionados quando houver integração).
 *
 * ON CONFLICT (code) DO NOTHING evita erro caso algum registro já exista.
 */
final class Version20261122025206 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Insere os dados iniciais de sales_channels (API)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO sales_channels (code, name) VALUES
                ('API', 'API')
            ON CONFLICT (code) DO NOTHING
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM sales_channels WHERE code = 'API'");
    }
}
