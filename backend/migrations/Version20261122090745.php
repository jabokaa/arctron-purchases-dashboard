<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261122090745 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Comentário da coluna products.image_url: passa a guardar o caminho da imagem enviada';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('COMMENT ON COLUMN products.image_url IS \'Caminho público da imagem enviada (/uploads/products/...) ou endereço externo, opcional\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('COMMENT ON COLUMN products.image_url IS \'Endereço (URL) da imagem do produto, opcional\'');
    }
}
