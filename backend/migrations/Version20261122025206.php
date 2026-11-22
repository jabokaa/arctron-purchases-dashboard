<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Dados iniciais (seed) das tabelas de domínio.
 *
 * As compras precisam de um canal de venda:
 * - API: compras recebidas pelo endpoint POST /api/purchases;
 * - WOOCOMMERCE: pedidos recebidos pelo webhook POST /api/webhooks/woocommerce.
 *
 * ON CONFLICT (code) DO NOTHING evita erro caso algum registro já exista.
 */
final class Version20261122025206 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Insere os dados iniciais de sales_channels (API e WOOCOMMERCE)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO sales_channels (code, name, created_at, updated_at) VALUES
                ('API', 'API', NOW(), NOW()),
                ('WOOCOMMERCE', 'WooCommerce', NOW(), NOW())
            ON CONFLICT (code) DO NOTHING
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM sales_channels WHERE code IN ('API', 'WOOCOMMERCE')");
    }
}
