<?php

declare(strict_types=1);

namespace App\Tests\Api;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;

final readonly class DevDataLookup
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @throws Exception
     */
    public function findOrganizationId(): ?int
    {
        $id = $this->connection->fetchOne('SELECT id FROM organization ORDER BY id LIMIT 1');

        return $id === false ? null : (int) $id;
    }

    /**
     * @throws Exception
     */
    public function hasClubRows(): bool
    {
        $count = $this->connection->fetchOne(
            "SELECT COUNT(*)
             FROM organization
             WHERE code IS NOT NULL
               AND code <> ''
               AND code <> 'PFS'",
        );

        return (int) $count > 0;
    }

    /**
     * @throws Exception
     */
    public function hasAnnotatedGameRows(): bool
    {
        return $this->findAnnotatedGameId() !== null;
    }

    /**
     * @throws Exception
     */
    public function hasRankingRows(): bool
    {
        $count = $this->connection->fetchOne(
            "SELECT COUNT(*)
             FROM ranking
             WHERE rtype = 'f'",
        );

        return (int) $count > 0;
    }

    /**
     * @throws Exception
     */
    public function hasDefaultOrganizationPlayerRows(): bool
    {
        $count = $this->connection->fetchOne(
            "SELECT COUNT(*)
             FROM player_organization po
             INNER JOIN player p ON p.id = po.player_id
             WHERE po.organization_id = 21
               AND p.slug IS NOT NULL
               AND p.slug <> ''",
        );

        return (int) $count > 0;
    }

    /**
     * @throws Exception
     */
    public function hasDefaultOrganizationTournamentRows(): bool
    {
        $count = $this->connection->fetchOne(
            "SELECT COUNT(*)
             FROM tournament t
             INNER JOIN player p ON p.id = t.winner_player_id
             WHERE t.organization_id = 21",
        );

        return (int) $count > 0;
    }

    /**
     * @return array{id:int, slug:string}|null
     * @throws Exception
     */
    public function findPlayer(): ?array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT id, slug
             FROM player
             WHERE slug IS NOT NULL
               AND slug <> ''
             ORDER BY id
             LIMIT 1",
        );

        if ($row === false) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'slug' => (string) $row['slug'],
        ];
    }

    /**
     * @return array{id:int, slug:string}|null
     * @throws Exception
     */
    public function findPfsPlayer(): ?array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT p.id, p.slug
             FROM player_organization po
             INNER JOIN organization o ON o.id = po.organization_id
             INNER JOIN player p ON p.id = po.player_id
             WHERE o.code = 'PFS'
               AND p.slug IS NOT NULL
               AND p.slug <> ''
             ORDER BY p.id
             LIMIT 1",
        );

        if ($row === false) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'slug' => (string) $row['slug'],
        ];
    }

    /**
     * @throws Exception
     */
    public function findTournamentId(): ?int
    {
        $id = $this->connection->fetchOne(
            'SELECT t.id
             FROM tournament t
             INNER JOIN tournament_result tr ON tr.tournament_id = t.id
             GROUP BY t.id, t.dt
             ORDER BY t.dt DESC, t.id DESC
             LIMIT 1',
        );

        return $id === false ? null : (int) $id;
    }

    /**
     * @return array{tournamentId:int, playerId:int, playerSlug:string}|null
     * @throws Exception
     */
    public function findTournamentPlayer(): ?array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT tr.tournament_id, p.id AS player_id, p.slug
             FROM tournament_result tr
             INNER JOIN player p ON p.id = tr.player_id
             WHERE p.slug IS NOT NULL
               AND p.slug <> ''
             ORDER BY tr.tournament_id DESC, tr.place ASC
             LIMIT 1",
        );

        if ($row === false) {
            return null;
        }

        return [
            'tournamentId' => (int) $row['tournament_id'],
            'playerId' => (int) $row['player_id'],
            'playerSlug' => (string) $row['slug'],
        ];
    }

    /**
     * Returns the route identifier used by GET /api/games/{id}.
     *
     * @throws Exception
     */
    public function findAnnotatedGameId(): ?string
    {
        $id = $this->connection->fetchOne(
            "SELECT CONCAT(h.tournament_id, '-', h.round_no, '-', h.player1_id) AS game_key
             FROM tournament_game h
             WHERE h.gcg IS NOT NULL
               AND h.gcg <> ''
               AND h.tournament_id IS NOT NULL
               AND h.player1_id IS NOT NULL
             ORDER BY h.tournament_id DESC, h.round_no ASC, h.player1_id ASC
             LIMIT 1",
        );

        return $id === false ? null : (string) $id;
    }

    /**
     * @return array{id:int, email:string, playerId:int, organizationId:int}|null
     * @throws Exception
     */
    public function findOrganizationAdminUser(): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT u.id, u.email, u.player_id, po.organization_id
             FROM app_user u
             INNER JOIN player_organization po ON po.player_id = u.player_id
             WHERE po.is_admin = true
             ORDER BY u.id
             LIMIT 1',
        );

        if ($row === false) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'email' => (string) $row['email'],
            'playerId' => (int) $row['player_id'],
            'organizationId' => (int) $row['organization_id'],
        ];
    }
}
