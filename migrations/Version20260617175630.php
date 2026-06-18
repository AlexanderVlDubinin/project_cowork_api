<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260617175630 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE resources (id UUID NOT NULL, title VARCHAR(255) NOT NULL, type VARCHAR(50) NOT NULL, description TEXT DEFAULT NULL, is_active BOOLEAN DEFAULT true NOT NULL, price_per_hour INT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_resources_type ON resources (type)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE resources');
    }
}
