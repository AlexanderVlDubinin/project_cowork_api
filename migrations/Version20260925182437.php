<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260925182437 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Changing the GiST restrictions to account for booking statuses.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs

        // 1. Removing the old strict restriction
        $this->addSql('ALTER TABLE bookings DROP CONSTRAINT no_overlapping_bookings;');

        // 2. Creating a new partial constraint with the WHERE section.
        $this->addSql('ALTER TABLE bookings ADD CONSTRAINT no_overlapping_bookings
                EXCLUDE USING gist (
                    resource_id WITH =,
                    tsrange(started_at, ended_at) WITH &&
                ) WHERE (status IN (\'pending\', \'confirmed\', \'checked_in\'));
        ');

    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs

        // Deleting the new partial constraint
        $this->addSql('ALTER TABLE bookings DROP CONSTRAINT no_overlapping_bookings;');

        // Re-adding the old strict constraint
        $this->addSql('ALTER TABLE bookings ADD CONSTRAINT no_overlapping_bookings
            EXCLUDE USING gist (
                resource_id WITH =,
                tsrange(started_at, ended_at) WITH &&
            );
        ');

    }
}
