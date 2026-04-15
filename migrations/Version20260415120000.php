<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260415120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add is_admin column to player_organization.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE player_organization ADD is_admin BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE player_organization DROP is_admin');
    }
}
