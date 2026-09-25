<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260923153728 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adding GiST restrictions to prevent crossing bookings (overbooking)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs

        // 1. Enabling the btree_gist extension (requires a DATABASE superuser - usually configured in Docker)
        $this->addSql('CREATE EXTENSION IF NOT EXISTS btree_gist;');

        // 2. Adding an exclusion restriction to the bookings table
        // Using "tsrange" for started_at and ended_at. The "&&" operator means "intersect".
        $this->addSql('ALTER TABLE bookings ADD CONSTRAINT no_overlapping_bookings
            EXCLUDE USING gist (
                resource_id WITH =,
                tsrange(started_at, ended_at) WITH &&
            );
        ');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs

        $this->addSql('ALTER TABLE bookings DROP CONSTRAINT no_overlapping_bookings;');

    }
}
