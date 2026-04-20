<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\User;
use App\Service\ClubTournamentResultsPreprocessor;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Twig\Environment;

final readonly class PreprocessClubTournamentResultsProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private RequestStack $requestStack,
        private ClubTournamentResultsPreprocessor $preprocessor,
        private Environment $twig,
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private Connection $connection,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Response
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException('Unauthorized.');
        }

        $request = $this->requestStack->getCurrentRequest();
        $organizationId = (int) $request?->request->get('organizationId', 0);
        if ($organizationId <= 0) {
            return new Response('Organization id must be a positive integer.', Response::HTTP_BAD_REQUEST);
        }
        $this->assertOrganizationAdmin($user, $organizationId);

        $uploadedFile = $request?->files->get('results');
        if (!$uploadedFile instanceof UploadedFile) {
            return new Response('Results file is required.', Response::HTTP_BAD_REQUEST);
        }

        $raw = file_get_contents($uploadedFile->getPathname());
        if ($raw === false) {
            return new Response('Could not read uploaded results file.', Response::HTTP_BAD_REQUEST);
        }

        try {
            $preview = $this->preprocessor->preprocess($raw);
        } catch (\Throwable $exception) {
            return new Response(
                'Nie udalo sie przetworzyc pliku: ' . $exception->getMessage(),
                Response::HTTP_BAD_REQUEST,
            );
        }

        return new Response(
            $this->twig->render('user/_club_tournament_results_preview.html.twig', [
                'preview' => $preview,
            ]),
            Response::HTTP_OK,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }

    /**
     * @throws Exception
     */
    private function assertOrganizationAdmin(User $user, int $organizationId): void
    {
        $playerId = $user->getPlayerId();
        if ($playerId === null) {
            throw new AccessDeniedHttpException('Organization admin role is required.');
        }

        $isAdmin = (bool) $this->connection->fetchOne(
            'SELECT 1
            FROM player_organization
            WHERE player_id = :playerId
                AND organization_id = :organizationId
                AND is_admin = true',
            [
                'playerId' => $playerId,
                'organizationId' => $organizationId,
            ],
        );

        if (!$isAdmin) {
            throw new AccessDeniedHttpException('Organization admin role is required.');
        }
    }
}
