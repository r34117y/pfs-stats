<?php

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\TournamentRound\TournamentRound;
use App\ApiResource\TournamentRound\TournamentRoundResponse;
use App\Service\TournamentRoundTokenAuthorizer;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

final readonly class TournamentRoundProcessor implements ProcessorInterface
{
    public function __construct(
        private TournamentRoundTokenAuthorizer $tournamentRoundTokenAuthorizer,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): TournamentRoundResponse
    {
        if (!$data instanceof TournamentRound) {
            throw new BadRequestHttpException('Invalid payload.');
        }

        if (!$this->tournamentRoundTokenAuthorizer->isAuthorized($data->token)) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized.');
        }

        return new TournamentRoundResponse('Authorized.');
    }
}
