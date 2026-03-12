<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260312120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Link app_user.player_id to player(id) as a one-to-one relation.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE UNIQUE INDEX uniq_app_user_player_id ON app_user (player_id)');
        $this->addSql('ALTER TABLE app_user ADD CONSTRAINT FK_C250282499E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user DROP CONSTRAINT FK_C250282499E6F5DF');
        $this->addSql('DROP INDEX uniq_app_user_player_id');
    }
}
