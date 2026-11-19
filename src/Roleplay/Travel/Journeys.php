<?php

declare(strict_types=1);

namespace App\Roleplay\Travel;

use App\Roleplay\Tool\PlayerbotCommandRunner;
use App\World\Exception\WorldGatewayException;
use App\World\WorldGatewayInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Watches companions who are walking somewhere, so that arriving is something they can say.
 *
 * The walking itself belongs to mod-anm-bridge now — see {@see LeadOrders} for why it had to
 * move inside the worldserver. All that is left out here is noticing, which the listener's loop
 * already has the data for: it refreshes every position each pass, so arrival is a distance
 * check against a table that is seconds old, with nothing new to poll.
 */
final class Journeys
{
    /** @var array<int, Journey> */
    private array $walking = [];

    public function __construct(
        private readonly WorldGatewayInterface $world,
        private readonly PlayerbotCommandRunner $runner,
        private readonly LeadOrders $leads,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function start(Journey $journey): void
    {
        $this->walking[$journey->botGuid] = $journey;
    }

    /**
     * Who is walking somewhere right now.
     *
     * Asked for by the listener so that {@see \App\Director\CompanionsOnCall} does not haul a
     * companion back while they are leading: walking ahead to an NPC looks exactly like having
     * wandered off, and the recall teleported one mid-journey — "did you bug out and fly to
     * Goldshire?".
     *
     * @return int[]
     */
    public function walkingGuids(): array
    {
        return array_keys($this->walking);
    }

    public function cancel(int $botGuid): void
    {
        unset($this->walking[$botGuid]);
    }

    /**
     * Say something to a bot, and shrug if it does not land.
     *
     * Nothing here is worth interrupting the listener for: a companion that fails to hold
     * position is a smaller problem than a loop that stops running.
     */
    private function tell(string $botName, string $command): void
    {
        try {
            $this->runner->run($botName, $command);
        } catch (WorldGatewayException $e) {
            $this->logger->warning('{bot} did not take "{command}": {why}', [
                'bot' => $botName,
                'command' => $command,
                'why' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array<int, string> bot guid => what to report, for arrivals and for giving up
     */
    public function tick(): array
    {
        $news = [];
        $now = microtime(true);

        foreach ($this->walking as $guid => $journey) {
            $bot = $this->world->findActorByGuid($guid);

            if (null === $bot || !$bot->isOnline) {
                unset($this->walking[$guid]);

                continue;
            }

            if ($journey->hasArrived($bot->position)) {
                unset($this->walking[$guid]);
                $news[$guid] = \sprintf('%s reached %s and is waiting there', $bot->name, $journey->towards);

                // Nothing to send. The module stops the companion where it arrives and forgets
                // the destination itself, which is the whole reason this is a watcher now.
                continue;
            }

            // The module decides when a walk has failed, and has already stopped the companion
            // and put them back to following by the time it says so.
            if (null !== $why = $this->leads->failure($guid)) {
                unset($this->walking[$guid]);
                $news[$guid] = \sprintf('%s could not reach %s: %s', $bot->name, $journey->towards, $why);

                continue;
            }

            // Nothing heard from the module for far too long. It may still be driving the walk,
            // so end it there before turning following back on, or the two pull the companion
            // in different directions.
            if ($journey->gaveUp($now)) {
                unset($this->walking[$guid]);
                $news[$guid] = \sprintf('%s could not reach %s', $bot->name, $journey->towards);

                $this->leads->stop($guid);
                $this->tell($bot->name, 'follow');

                continue;
            }

            // Still walking, and nothing to do about it but keep count. Every attempt to
            // help from out here made things worse: re-sent destinations were dropped by
            // `IsDuplicateMove`, and re-anchoring whispered "Position stay is set" at the
            // player once a second.
            $togo = hypot(
                $bot->position->x - $journey->destination->x,
                $bot->position->y - $journey->destination->y,
            );

            $this->logger->info('{bot} is {togo} yards from {towards} ({elapsed}s in)', [
                'bot' => $bot->name,
                'togo' => (int) round($togo),
                'towards' => $journey->towards,
                'elapsed' => (int) round($now - $journey->startedAt),
            ]);
        }

        return $news;
    }
}
