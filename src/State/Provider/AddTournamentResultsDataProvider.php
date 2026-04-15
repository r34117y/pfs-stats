<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\UserAdmin\AddTournamentResultsData;
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
            'Przygotuj pliki z wynikami turnieju i wybierz organizację, do której mają trafić.',
            $adminContext['organizations'],
            [],
        );
    }
}
