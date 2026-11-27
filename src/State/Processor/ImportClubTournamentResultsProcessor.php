<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\User;
use App\Service\ClubTournamentResultsFileDecoder;
use App\Service\ClubTournamentResultsImportService;
use App\Service\ClubTournamentResultsParser;
use App\Service\DatasetVersionService;
use App\Service\UserOrganizationAdminChecker;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final readonly class ImportClubTournamentResultsProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private RequestStack $requestStack,
        private UserOrganizationAdminChecker $organizationAdminChecker,
        private ClubTournamentResultsFileDecoder $fileDecoder,
        private ClubTournamentResultsParser $parser,
        private ClubTournamentResultsImportService $importService,
        private ?DatasetVersionService $datasetVersion = null,
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
            $parsed = $this->parser->parse($this->fileDecoder->decode($raw), $standings !== null ? $this->fileDecoder->decode($standings) : null);
            $result = $this->importService->import($parsed, $organizationId);
        } catch (HttpExceptionInterface $exception) {
            return new Response($exception->getMessage(), $exception->getStatusCode());
        } catch (\Throwable $exception) {
            return new Response(
                'Nie udalo sie zaimportowac turnieju: ' . $exception->getMessage(),
                Response::HTTP_BAD_REQUEST,
            );
        }

        $message = sprintf('Zaimportowano turniej %s.', $parsed->name);
        try {
            $this->datasetVersion?->bumpVersion();
        } catch (\Throwable) {
            $message .= ' Turniej jest zapisany, ale odświeżenie statystyk wymaga pomocy administratora.';
        }

        return new JsonResponse([
            'message' => $message,
            'tournamentId' => $result->tournamentId,
            'playersCount' => $result->playersCount,
            'gamesCount' => $result->gamesCount,
            'createdPlayerIds' => $result->createdPlayerIds,
            'linkedPlayerIds' => $result->linkedPlayerIds,
        ]);
    }
}
