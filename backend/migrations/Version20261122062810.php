<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261122062810 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cria a tabela individual_customers (clientes pessoa física, com CPF)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE individual_customers (full_name VARCHAR(255) NOT NULL, cpf VARCHAR(11) NOT NULL, id BIGINT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_individual_customers_cpf ON individual_customers (cpf)');
        $this->addSql('COMMENT ON TABLE individual_customers IS \'Dados específicos de clientes pessoa física\'');
        $this->addSql('COMMENT ON COLUMN individual_customers.full_name IS \'Nome completo da pessoa\'');
        $this->addSql('COMMENT ON COLUMN individual_customers.cpf IS \'CPF sem máscara (11 dígitos), único por cliente\'');
        $this->addSql('ALTER TABLE individual_customers ADD CONSTRAINT FK_76C1224DBF396750 FOREIGN KEY (id) REFERENCES customers (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE individual_customers DROP CONSTRAINT FK_76C1224DBF396750');
        $this->addSql('DROP TABLE individual_customers');
    }
}
