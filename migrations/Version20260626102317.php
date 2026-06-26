<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260626102317 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE payment_transactions (id UUID NOT NULL, external_id VARCHAR(255) NOT NULL, status VARCHAR(30) NOT NULL, amount INT NOT NULL, payload JSONB DEFAULT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, booking_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8C58AD569F75D7B0 ON payment_transactions (external_id)');
        $this->addSql('CREATE INDEX IDX_8C58AD563301C60 ON payment_transactions (booking_id)');
        $this->addSql('ALTER TABLE payment_transactions ADD CONSTRAINT FK_8C58AD563301C60 FOREIGN KEY (booking_id) REFERENCES bookings (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE payment_transactions DROP CONSTRAINT FK_8C58AD563301C60');
        $this->addSql('DROP TABLE payment_transactions');
    }
}
