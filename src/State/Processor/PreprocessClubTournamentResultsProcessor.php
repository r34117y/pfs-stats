<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\User;
use App\Service\ClubTournamentExistingTournamentFinder;
use App\Service\ClubTournamentResultsPreprocessor;
use App\Service\UserOrganizationAdminChecker;
use Symfony\Bundle\SecurityBundle\Security;
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
        private ClubTournamentExistingTournamentFinder $existingTournamentFinder,
        private UserOrganizationAdminChecker $organizationAdminChecker,
        private Environment $twig,
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
        $this->organizationAdminChecker->assertOrganizationAdmin($user, $organizationId);

        $uploadedFile = $request?->files->get('results');
        if (!$uploadedFile instanceof UploadedFile) {
            return new Response('Results file is required.', Response::HTTP_BAD_REQUEST);
        }

        $raw = file_get_contents($uploadedFile->getPathname());
        if ($raw === false) {
            return new Response('Could not read uploaded results file.', Response::HTTP_BAD_REQUEST);
        }

        try {
            $standingsFile = $request?->files->get('standings');
            $standings = null;
            if ($standingsFile instanceof UploadedFile) {
                $standings = file_get_contents($standingsFile->getPathname());
                if ($standings === false) {
                    throw new \RuntimeException('Could not read final standings file.');
                }
            }
            $preview = $this->preprocessor->preprocess($raw, $standings, $request?->request->get('sourceDirectory'));
        } catch (\Throwable $exception) {
            return new Response(
                'Nie udalo sie przetworzyc pliku: ' . $exception->getMessage(),
                Response::HTTP_BAD_REQUEST,
            );
        }

        $existingTournamentId = $this->existingTournamentFinder->findExistingTournamentId(
            $organizationId,
            $preview->results->getDateCode(),
            $preview->results->name,
        );
        if ($existingTournamentId !== null) {
            return new Response(
                sprintf('Tournament already exists with id %d.', $existingTournamentId),
                Response::HTTP_CONFLICT,
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
}
