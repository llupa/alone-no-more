<?php

declare(strict_types=1);

namespace App\Roleplay\Travel;

use App\Roleplay\Tool\PlayerbotCommandRunner;
use App\World\Exception\WorldGatewayException;
use App\World\WorldGatewayInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Watches companions who are walking somewhere, and tells them to wait when they arrive.
 *
 * Runs on the listener's loop, which already refreshes positions every pass — so arrival is
 * simply a distance check against a table that is seconds old, with nothing new to poll.
 */
final class Journeys
{
    /** @var array<int, Journey> */
    private array $walking = [];

    public function __construct(
        private readonly WorldGatewayInterface $world,
        private readonly PlayerbotCommandRunner $runner,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function start(Journey $journey): void
    {
        $this->walking[$journey->botGuid] = $journey;
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

                // Already holding position: the walk was driven by `stay` anchored at the
                // destination, so the companion stops there of its own accord. Re-anchoring on
                // the spot it actually reached settles it exactly where it stands, instead of
                // leaving it shuffling against a target z we guessed from its own height at
                // the other end of the walk.
                try {
                    $this->runner->anchorAt($bot->name, $bot->position->x, $bot->position->y, $bot->position->z);
                } catch (WorldGatewayException $e) {
                    $this->logger->warning('{bot} would not settle: {why}', ['bot' => $bot->name, 'why' => $e->getMessage()]);
                }

                continue;
            }

            if ($journey->gaveUp($now)) {
                unset($this->walking[$guid]);
                $news[$guid] = \sprintf('%s could not reach %s', $bot->name, $journey->towards);

                // Following was switched off to let them walk. A failed journey must not leave
                // a companion standing in a field for the rest of the evening.
                $this->tell($bot->name, 'follow');

                continue;
            }

            // Still walking. The anchor does the moving; this drags it along the route, a leg
            // at a time, and re-sends it every pass.
            //
            // Both halves are load-bearing. Anything beyond `Playerbots.ReactDistance` is
            // discarded by the mod and replaced with wherever the bot is standing, so the leg
            // has to stay short. And the anchor is destroyed behind our back —
            // `PlayerbotAI::ChangeEngineOnNonCombat()` resets the stay position whenever a bot
            // leaves combat — so re-sending only on arrival deadlocked the moment anything
            // attacked: the companion could not reach a leg whose anchor no longer existed.
            // Re-sending cannot be unconditional either: each one makes the companion whisper
            // "Position stay is set" at the player. So it goes out when a leg is walked, or
            // when nothing has moved for a while — the only outward sign that the anchor was
            // taken away.
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

            if (!$journey->needsAnchor($bot->position, $now)) {
                $journey->wasAt = $bot->position;

                continue;
            }

            $leg = $journey->nextLeg($bot->position);

            try {
                $this->runner->anchorAt($bot->name, $leg->x, $leg->y, $leg->z);
                $journey->anchor = $leg;
                $journey->anchoredAt = $now;
                $journey->wasAt = $bot->position;
            } catch (WorldGatewayException $e) {
                $this->logger->warning('Could not set {bot} on the next leg: {why}', ['bot' => $bot->name, 'why' => $e->getMessage()]);
            }
        }

        return $news;
    }
}
