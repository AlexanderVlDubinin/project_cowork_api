<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261009134622 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Changing the GiST restrictions to account for a technical break.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs

        // 1. Removing the old strict restriction
        $this->addSql('ALTER TABLE bookings DROP CONSTRAINT no_overlapping_bookings');

        // 2. Adding service fields for indexing gaps
        $this->addSql('ALTER TABLE bookings ADD db_blocked_from TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL');
        $this->addSql('ALTER TABLE bookings ADD db_blocked_to TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL');

        // 3. Creating a new constraint by service fields.
        $this->addSql('ALTER TABLE bookings ADD CONSTRAINT no_overlapping_bookings
            EXCLUDE USING gist (
                resource_id WITH =,
                tsrange(db_blocked_from, db_blocked_to) WITH &&
            ) WHERE (status IN (\'pending\', \'confirmed\', \'checked_in\'));
        ');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs

        // 1. Deleting the new constraint
        $this->addSql('ALTER TABLE bookings DROP CONSTRAINT no_overlapping_bookings;');

        // 2. Removing the service fields for indexing gaps
        $this->addSql('ALTER TABLE bookings DROP COLUMN IF EXISTS db_blocked_from, DROP COLUMN IF EXISTS db_blocked_to');

        // 3. Re-adding the old constraint
        $this->addSql('ALTER TABLE bookings ADD CONSTRAINT no_overlapping_bookings
                EXCLUDE USING gist (
                    resource_id WITH =,
                    tsrange(started_at, ended_at) WITH &&
                ) WHERE (status IN (\'pending\', \'confirmed\', \'checked_in\'));
        ');

    }
}
