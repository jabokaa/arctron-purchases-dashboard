<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261122084456 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona a coluna image_url (imagem do produto) em products';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE products ADD image_url VARCHAR(2048) DEFAULT NULL');
        $this->addSql('COMMENT ON COLUMN products.image_url IS \'Endereço (URL) da imagem do produto, opcional\'');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE products DROP image_url');
    }
}
