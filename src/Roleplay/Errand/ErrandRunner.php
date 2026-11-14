<?php

declare(strict_types=1);

namespace App\Roleplay\Errand;

use App\Knowledge\QuestLogInterface;
use App\World\Chat\BotSpeechDispatcher;
use App\World\WorldGatewayInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Keeps companions working through their errands, and knows when to stop.
 *
 * Runs on the listener's own loop rather than as a timer of its own: it needs the same fresh view
 * of the world as everything else, and one process is one thing to restart.
 *
 * Each pass is deliberately small — one swing per bot per interval. The server does the fighting;
 * this only keeps pointing.
 */
final readonly class ErrandRunner
{
    public function __construct(
        private ErrandBook $book,
        private BotSpeechDispatcher $speech,
        private QuestLogInterface $questLog,
        private WorldGatewayInterface $world,
        /** Roughly how long a low-level fight takes, so we do not interrupt one to start another. */
        #[Autowire(env: 'int:ANM_ERRAND_SWING_SECONDS')]
        private int $swingSeconds = 8,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * One pass over every outstanding errand.
     *
     * @return array<int, string> bot name => what just finished, for the listener to report
     */
    public function tick(): array
    {
        $finished = [];
        $now = microtime(true);

        foreach ($this->book->all() as $errand) {
            $bot = $this->world->findActorByGuid($errand->botGuid);

            if (null === $bot || !$bot->isOnline) {
                // Logged out from under us, most likely herded away.
                $this->book->finish($errand->botGuid);

                continue;
            }

            if (null !== $reason = $this->reasonToStop($errand)) {
                $this->book->finish($errand->botGuid);
                $finished[$bot->guid] = \sprintf('%s: %s', $bot->name, $reason);

                continue;
            }

            if (!$errand->readyToSwing($now, (float) $this->swingSeconds)) {
                continue;
            }

            $errand->lastSwingAt = $now;
            ++$errand->swings;

            // Queued and not awaited: a pass must not block the listener, and a swing that finds
            // nothing is normal — the player walks, and what is in reach changes.
            $this->speech->attack($errand->botGuid, $errand->what);

            $this->logger->debug('{bot} swings at {what} ({n})', [
                'bot' => $bot->name,
                'what' => $errand->what,
                'n' => $errand->swings,
            ]);
        }

        return $finished;
    }

    /**
     * Why this errand is over, or null if it is not.
     *
     * Quest progress is the real answer when the player named a quest: the server counts kills
     * and drops far more accurately than a model counting out loud, which it cannot do.
     */
    private function reasonToStop(Errand $errand): ?string
    {
        if (null !== $errand->questId) {
            foreach ($this->questLog->activeFor($errand->questOwnerGuid ?? $errand->botGuid) as $quest) {
                if ($quest['id'] !== $errand->questId) {
                    continue;
                }

                if ($quest['complete']) {
                    return \sprintf('"%s" is finished', $quest['title']);
                }

                return null;
            }

            // Gone from the log entirely: handed in while we were working, which also counts.
            return \sprintf('"%s" is no longer in the log', $errand->questTitle ?? 'the quest');
        }

        return $errand->exhausted() ? 'that is enough for now' : null;
    }

    /**
     * How far along, for the companion to answer honestly when asked.
     */
    public function progressOf(Errand $errand): ?string
    {
        if (null === $errand->questId) {
            return null;
        }

        foreach ($this->questLog->activeFor($errand->questOwnerGuid ?? $errand->botGuid) as $quest) {
            if ($quest['id'] !== $errand->questId) {
                continue;
            }

            foreach ($quest['needs'] as $need) {
                if ($need['have'] < $need['need']) {
                    return \sprintf('%s %d of %d', $need['what'], $need['have'], $need['need']);
                }
            }

            return 'everything it asks for';
        }

        return null;
    }
}
