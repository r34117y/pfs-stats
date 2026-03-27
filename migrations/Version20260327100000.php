<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260327100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add nullable bio column to player.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE player ADD bio TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE player DROP bio');
    }
}
