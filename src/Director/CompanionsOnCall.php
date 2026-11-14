<?php

declare(strict_types=1);

namespace App\Director;

use App\Herding\BotRoster;
use App\World\Chat\BotSpeechDispatcher;
use App\World\Model\Actor;
use App\World\WorldGatewayInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Makes good on a companion asked for in advance.
 *
 * Two jobs, both on the listener's loop. Bring a requested companion online next to the player
 * if they are not already there; and tell the herder never to send them away again, because a
 * crowd is churn by design and the whole point of asking for someone is that they stay.
 */
final readonly class CompanionsOnCall
{
    public function __construct(
        private CompanionRequests $requests,
        private WorldGatewayInterface $world,
        private BotRoster $roster,
        private BotSpeechDispatcher $speech,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Bring anyone waiting for this player into the world beside them.
     *
     * @return string[] names newly brought in, for the listener to report
     */
    public function gather(Actor $player): array
    {
        $arrived = [];

        foreach ($this->requests->forCharacter($player->guid) as $request) {
            $bot = $this->world->findActorByGuid($request['bot_guid']);

            if (null !== $bot && $bot->isOnline) {
                continue;
            }

            // Placed before logging in, exactly as the herder does it — a bot that logs in where
            // it last stood would have to run across a continent, or pop into view on arrival.
            if (!$this->roster->placeDormant($request['bot_guid'], $player->position, $player->zoneId)) {
                $this->logger->warning('Could not place {name} beside {player}', [
                    'name' => $request['bot_name'],
                    'player' => $player->name,
                ]);

                continue;
            }

            $this->speech->login($request['bot_guid']);
            $arrived[] = $request['bot_name'];
        }

        return $arrived;
    }

    /**
     * Guids the herder must leave alone, whatever the distance.
     *
     * @return int[]
     */
    public function protectedFor(Actor $player): array
    {
        return array_map(
            static fn (array $r): int => $r['bot_guid'],
            $this->requests->forCharacter($player->guid),
        );
    }
}
