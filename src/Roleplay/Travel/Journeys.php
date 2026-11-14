<?php

declare(strict_types=1);

namespace App\Roleplay\Travel;

use App\Roleplay\Tool\PlayerbotCommandRunner;
use App\World\Exception\WorldGatewayException;
use App\World\WorldGatewayInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

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
        /** How often to re-send the destination while a companion is still on its way. */
        #[Autowire(env: 'int:ANM_JOURNEY_NUDGE_SECONDS')]
        private readonly int $nudgeSeconds = 5,
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

                // Hold position. Following is already off — it was switched off to let them
                // walk — so this is belt and braces, and it is what the player asked for: wait
                // here. `follow` puts them right again whenever they say so.
                $this->tell($bot->name, 'stay');

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

            // Still walking: point them at it again. One `go` is not enough, because the follow
            // strategy keeps pulling them back toward the player.
            if ($journey->needsNudge($now, (float) $this->nudgeSeconds)) {
                $journey->lastNudgeAt = $now;

                try {
                    $this->runner->goTo(
                        $bot->name,
                        $journey->destination->x,
                        $journey->destination->y,
                        $bot->position->z,
                    );
                } catch (WorldGatewayException $e) {
                    $this->logger->warning('Could not nudge {bot} onward: {why}', ['bot' => $bot->name, 'why' => $e->getMessage()]);
                }
            }
        }

        return $news;
    }
}
