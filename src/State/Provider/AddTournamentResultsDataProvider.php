<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\UserAdmin\AddTournamentResultsData;
use App\ApiResource\UserAdmin\UserAdminOrganization;
use App\Service\UserAdminRecentTournamentImportsService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class AddTournamentResultsDataProvider implements ProviderInterface
{
    use UserAdminContextTrait;

    public function __construct(
        private Security $security,
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private Connection $connection,
        private UserAdminRecentTournamentImportsService $recentTournamentImportsService,
    ) {
    }

    /**
     * @throws Exception
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): AddTournamentResultsData
    {
        $adminContext = $this->getAdminContext(
            $this->getAuthenticatedUser($this->security),
            $this->connection,
        );

        return new AddTournamentResultsData(
            $adminContext['profile'],
            'Dodaj wyniki turnieju',
            $adminContext['organizations'],
            $this->recentTournamentImportsService->getRecentImportsForOrganizations(
                array_map(
                    static fn (UserAdminOrganization $organization): int => $organization->id,
                    $adminContext['organizations'],
                ),
            ),
        );
    }
}
