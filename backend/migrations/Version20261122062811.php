<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261122062811 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cria a tabela company_customers (clientes pessoa jurídica, com CNPJ)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE company_customers (legal_name VARCHAR(255) NOT NULL, trade_name VARCHAR(255) DEFAULT NULL, cnpj VARCHAR(14) NOT NULL, id BIGINT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_company_customers_cnpj ON company_customers (cnpj)');
        $this->addSql('COMMENT ON TABLE company_customers IS \'Dados específicos de clientes pessoa jurídica\'');
        $this->addSql('COMMENT ON COLUMN company_customers.legal_name IS \'Razão social da empresa\'');
        $this->addSql('COMMENT ON COLUMN company_customers.trade_name IS \'Nome fantasia da empresa (opcional)\'');
        $this->addSql('COMMENT ON COLUMN company_customers.cnpj IS \'CNPJ sem máscara (14 caracteres, aceita o formato alfanumérico), único por cliente\'');
        $this->addSql('ALTER TABLE company_customers ADD CONSTRAINT FK_5148C4D1BF396750 FOREIGN KEY (id) REFERENCES customers (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE company_customers DROP CONSTRAINT FK_5148C4D1BF396750');
        $this->addSql('DROP TABLE company_customers');
    }
}
