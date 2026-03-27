<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class PlayerSlugResolver
{public function __construct(
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private Connection $connection,
    ) {
    }

    /**
     * @throws Exception
     */
    public function resolveLegacyPlayerId(string $slug): ?int
    {
        $normalizedSlug = trim($slug);
        if ($normalizedSlug === '') {
            return null;
        }

        $value = $this->connection->fetchOne(
            'SELECT id from player where slug = :slug',
            [
                'slug' => $normalizedSlug,
            ],
        );

        if ($value === false || $value === null) {
            return null;
        }

        return (int) $value;
    }
}
