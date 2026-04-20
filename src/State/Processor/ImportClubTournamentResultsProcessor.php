<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\User;
use App\Service\ClubTournamentResultsFileDecoder;
use App\Service\ClubTournamentResultsImportService;
use App\Service\ClubTournamentResultsParser;
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
            $parsed = $this->parser->parse($this->fileDecoder->decode($raw));
            $result = $this->importService->import($parsed, $organizationId);
        } catch (HttpExceptionInterface $exception) {
            return new Response($exception->getMessage(), $exception->getStatusCode());
        } catch (\Throwable $exception) {
            return new Response(
                'Nie udalo sie zaimportowac turnieju: ' . $exception->getMessage(),
                Response::HTTP_BAD_REQUEST,
            );
        }

        return new JsonResponse([
            'message' => sprintf('Zaimportowano turniej %s.', $parsed->name),
            'tournamentId' => $result->tournamentId,
            'legacyTournamentId' => $result->legacyTournamentId,
            'playersCount' => $result->playersCount,
            'gamesCount' => $result->gamesCount,
            'createdPlayerIds' => $result->createdPlayerIds,
            'linkedPlayerIds' => $result->linkedPlayerIds,
        ]);
    }
}
