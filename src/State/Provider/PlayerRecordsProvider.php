<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\PlayerRecords\PlayerRecordsTable;
use App\Service\PlayerRecordsService;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PlayerRecordsProvider implements ProviderInterface
{
    public function __construct(
        private PlayerRecordsService $playerRecordsService,
        private RequestStack $requestStack,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PlayerRecordsTable
    {
        $rawPlayerId = $uriVariables['id'] ?? $uriVariables['playerId'] ?? null;
        $playerId = is_numeric($rawPlayerId) ? (int) $rawPlayerId : 0;

        if ($playerId <= 0) {
            throw new NotFoundHttpException('Player not found.');
        }

        $recordType = (string) ($operation->getExtraProperties()['recordType'] ?? '');
        $request = $this->requestStack->getCurrentRequest();
        $limit = (int) ($request?->query->get('limit', 10) ?? 10);
        $min = $request?->query->has('min') ? (int) $request->query->get('min') : null;

        return $this->playerRecordsService->getRecords($playerId, $recordType, $limit, $min);
    }
}
