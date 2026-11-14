<?php

declare(strict_types=1);

namespace App\Herding;

use App\World\Chat\BotSpeechDispatcher;
use App\World\GroupRepositoryInterface;
use App\World\Model\Actor;
use App\World\WorldGatewayInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Decides which bots should be in the world, and brings them in.
 *
 * The first and smallest case: a bot the player has already invited to their party but which is
 * not logged in. Intent could not be more explicit — they picked that character — and the scope
 * is at most four. It also fixes a real bug: an offline party member shows as "Unknown" in the
 * party frame and never answers, which looks like our feature failing when it is really an empty
 * chair.
 *
 * This is step one of herding. The same mechanism later selects bots by proximity to where the
 * player is heading, but the selection rule is the hard part, not the logging in.
 */
final readonly class BotPresenceManager
{
    public function __construct(
        private WorldGatewayInterface $world,
        private GroupRepositoryInterface $groups,
        private BotSpeechDispatcher $dispatcher,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Bring any offline bot in this player's party into the world.
     *
     * @return Actor[] the bots asked for, which is not the same as the bots that arrived —
     *                 logging in is asynchronous and the caller should re-read the world to see
     *                 what actually happened
     */
    public function wakeParty(Actor $player): array
    {
        $woken = [];

        foreach ($this->groups->memberGuidsWith($player->guid) as $guid) {
            $member = $this->world->findActorByGuid($guid);

            if (null === $member || !$member->isBot || $member->isOnline) {
                continue;
            }

            $this->dispatcher->login($member->guid);
            $this->logger->info('Waking party member {name} ({guid})', [
                'name' => $member->name,
                'guid' => $member->guid,
            ]);

            $woken[] = $member;
        }

        return $woken;
    }

    /**
     * Everyone grouped with this player, online or not, so a caller can show the party as the
     * game shows it — including the empty chairs.
     *
     * @return Actor[]
     */
    public function partyMembers(Actor $player): array
    {
        $members = [];

        foreach ($this->groups->memberGuidsWith($player->guid) as $guid) {
            if (null !== $member = $this->world->findActorByGuid($guid)) {
                $members[] = $member;
            }
        }

        return $members;
    }

    /**
     * Offline party members, without doing anything about them — so a caller can show the player
     * who is missing before acting.
     *
     * @return Actor[]
     */
    public function sleepingPartyMembers(Actor $player): array
    {
        return array_values(array_filter(
            $this->partyMembers($player),
            static fn (Actor $m): bool => $m->isBot && !$m->isOnline,
        ));
    }
}
