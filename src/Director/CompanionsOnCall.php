<?php

declare(strict_types=1);

namespace App\Director;

use App\Herding\BotRoster;
use App\Herding\ProtectedBots;
use App\World\Chat\BotSpeechDispatcher;
use App\World\GroupRepositoryInterface;
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
final readonly class CompanionsOnCall implements ProtectedBots
{
    /**
     * How far a booked companion may stray before being brought back. Well beyond the herder's
     * own staging radius, so the two do not fight over the same bot.
     */
    private const STILL_HERE_YARDS = 400.0;

    public function __construct(
        private CompanionRequests $requests,
        private WorldGatewayInterface $world,
        private BotRoster $roster,
        private BotSpeechDispatcher $speech,
        private GroupRepositoryInterface $groups,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Bring anyone waiting for this player into the world beside them.
     *
     * @param int[] $busy guids to leave alone whatever their distance — a companion walking
     *                    somewhere on purpose is not one who has wandered off
     *
     * @return string[] names newly brought in, for the listener to report
     */
    public function gather(Actor $player, array $busy = []): array
    {
        $arrived = [];

        foreach ($this->requests->forCharacter($player->guid) as $request) {
            if (\in_array($request['bot_guid'], $busy, true)) {
                continue;
            }

            $bot = $this->world->findActorByGuid($request['bot_guid']);

            if (null !== $bot && $bot->isOnline && $this->isWithReach($bot, $player)) {
                continue;
            }

            // Grouped is an answer in itself. Whatever the distance, a companion in the
            // player's party is not lost — and logging one out to put them somewhere better
            // takes the party down with them, which is a steep price for tidiness.
            if (null !== $bot && $bot->isOnline && $this->groups->isGrouped($request['bot_guid'])) {
                continue;
            }

            // Online but somewhere else entirely. A booked companion is not a bot in a crowd:
            // the mod's own random-bot manager will happily walk them into the next zone while
            // the player is logged out, and asking for someone in advance is pointless if they
            // then have to be found. Sending them away first is what lets them be placed again
            // — a bot already in the world cannot be moved by staging it.
            if (null !== $bot && $bot->isOnline) {
                $this->logger->info('Recalling {name} to {player} from {zone}', [
                    'name' => $request['bot_name'],
                    'player' => $player->name,
                    'zone' => $bot->zoneId,
                ]);

                $this->speech->logout($request['bot_guid']);
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
     * Whether a companion is near enough to count as already gathered.
     *
     * Generous on purpose: this is "are they here with the player", not "are they in formation".
     * A companion a little way off is walking back; one in the next zone has been taken by the
     * random-bot manager and is not coming back on their own.
     */
    private function isWithReach(Actor $bot, Actor $player): bool
    {
        // Distance and continent, deliberately not zone. Matching zone ids looked like a
        // stricter version of the same question and is a different one: a companion standing
        // ten yards away across a border is plainly still with you, and requiring the ids to
        // match meant every zone change logged them out and re-placed them — which disbanded
        // the party, because a bot that logs out leaves the group it was in.
        return $bot->position->mapId === $player->position->mapId
            && $bot->isWithin(self::STILL_HERE_YARDS, $player);
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
