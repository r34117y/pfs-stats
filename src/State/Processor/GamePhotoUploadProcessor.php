<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\GamePhoto\GamePhotoUploadResponse;
use App\Entity\GamePhoto;
use App\Entity\TournamentGame;
use App\Entity\User;
use App\Repository\TournamentGameRepository;
use App\Service\GamePhotoStorageService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class GamePhotoUploadProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private RequestStack $requestStack,
        private TournamentGameRepository $tournamentGameRepository,
        private GamePhotoStorageService $gamePhotoStorageService,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): GamePhotoUploadResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException('Unauthorized.');
        }

        $player = $user->getPlayer();
        if (null === $player || null === $player->getId()) {
            throw new AccessDeniedHttpException('Only linked players can upload game photos.');
        }

        $gameId = filter_var($uriVariables['gameId'] ?? null, FILTER_VALIDATE_INT);
        if (!is_int($gameId) || $gameId <= 0) {
            throw new BadRequestHttpException('Invalid game ID.');
        }

        $game = $this->tournamentGameRepository->find($gameId);
        if (!$game instanceof TournamentGame) {
            throw new NotFoundHttpException('Tournament game not found.');
        }

        if (!$this->canUploadForGame($game, $player->getId())) {
            throw new AccessDeniedHttpException('Only participating players can upload photos for this game.');
        }

        $request = $this->requestStack->getCurrentRequest();
        $uploadedFile = $request?->files->get('photo');
        if (!$uploadedFile instanceof UploadedFile) {
            throw new BadRequestHttpException('Photo file is required.');
        }

        $category = mb_strtolower(trim((string) $request?->request->get('category', '')));
        if (!GamePhoto::isValidCategory($category)) {
            throw new BadRequestHttpException(sprintf(
                'Invalid category. Allowed values: %s.',
                implode(', ', GamePhoto::allowedCategories())
            ));
        }

        $photoPath = $this->gamePhotoStorageService->storeCompressedPhoto($uploadedFile, $gameId);

        $photo = (new GamePhoto())
            ->setTournamentGame($game)
            ->setUploadedByPlayer($player)
            ->setCategory($category)
            ->setPath($photoPath)
            ->setUploadedAt(new \DateTimeImmutable());

        $this->entityManager->persist($photo);
        $this->entityManager->flush();

        return new GamePhotoUploadResponse(
            'Photo uploaded successfully.',
            $photo->getId() ?? 0,
            $gameId,
            $photo->getCategory(),
            $photo->getPath(),
            $photo->getUploadedAt()->format(DATE_ATOM),
        );
    }

    private function canUploadForGame(TournamentGame $game, int $playerId): bool
    {
        return $game->getPlayer1()?->getId() === $playerId || $game->getPlayer2()?->getId() === $playerId;
    }
}
