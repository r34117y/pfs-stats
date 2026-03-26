<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260326110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replace player.utype and player.cached with nullable first_name, last_name, city, and slug columns.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE player DROP utype');
        $this->addSql('ALTER TABLE player DROP cached');
        $this->addSql('ALTER TABLE player ADD first_name VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE player ADD last_name VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE player ADD city VARCHAR(256) DEFAULT NULL');
        $this->addSql('ALTER TABLE player ADD slug VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE player DROP first_name');
        $this->addSql('ALTER TABLE player DROP last_name');
        $this->addSql('ALTER TABLE player DROP city');
        $this->addSql('ALTER TABLE player DROP slug');
        $this->addSql('ALTER TABLE player ADD utype VARCHAR(1) DEFAULT NULL');
        $this->addSql('ALTER TABLE player ADD cached VARCHAR(1) DEFAULT NULL');
    }
}
