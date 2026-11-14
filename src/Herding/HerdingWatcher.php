<?php

declare(strict_types=1);

namespace App\Herding;

use App\World\Model\Actor;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Follows the player and keeps the world populated around them.
 *
 * The hard part is not staging a crowd — it is deciding when. A zone boundary is a line on a
 * map, not an intention: ride along it, or step into a cave that counts as its own zone, and a
 * naive watcher tears the world down and rebuilds it every few seconds. So an arrival has to
 * persist before it is believed.
 *
 * Once settled, the old zone is emptied and the new one filled. Bots are never discarded — they
 * go dormant and become available to be staged wherever the player turns up next, which is the
 * whole point of a pool.
 */
final class HerdingWatcher
{
    private ?int $currentZone = null;
    private ?int $pendingZone = null;
    private int $pendingSince = 0;
    private int $lastStagedAt = 0;

    /** @var array<int, array{name: string, readyAt: int}> bots woken, waiting to be given something to do */
    private array $toRouse = [];

    public function __construct(
        private readonly BotStagerInterface $stager,
        /**
         * Everyone with a claim on a bot. The herder does not ask why — a companion mid
         * conversation and one asked for by name are protected for quite different reasons,
         * and neither reason is the crowd layer's business.
         *
         * @var iterable<ProtectedBots>
         */
        #[AutowireIterator(ProtectedBots::class)]
        private readonly iterable $claims,
        /** How long an arrival must hold before we believe it. */
        #[Autowire(env: 'int:ANM_HERD_SETTLE_SECONDS')]
        private readonly int $settleSeconds = 60,
        /** Top up the crowd now and then: bots die, wander off, or get dismissed elsewhere. */
        #[Autowire(env: 'int:ANM_HERD_REFRESH_SECONDS')]
        private readonly int $refreshSeconds = 180,
        /** Beyond this, a bot is somewhere the player no longer is. */
        #[Autowire(env: 'float:ANM_HERD_DISMISS_BEYOND')]
        private readonly float $dismissBeyond = 1200.0,
        /**
         * How long to let a bot finish arriving before telling it what to do.
         *
         * An order sent to a character that is still logging in is simply dropped, and the bot
         * then stands still forever. The listener ticks once a second, so "next tick" was far
         * too soon — which is exactly how fifty people ended up standing in a clearing.
         */
        #[Autowire(env: 'int:ANM_HERD_ROUSE_DELAY_SECONDS')]
        private readonly int $rouseDelaySeconds = 10,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Called on every poll. Returns what it did, or null when it decided to do nothing —
     * which is most of the time, and should be.
     */
    public function tick(Actor $player): ?StagingResult
    {
        $now = time();

        $this->rouseWhoeverIsReady($now);

        // First sighting: adopt the zone without waiting, so a fresh listener populates at once
        // rather than leaving the player alone for a minute.
        if (null === $this->currentZone) {
            $this->currentZone = $player->zoneId;

            return $this->restage($player, $now, 'first sighting');
        }

        if ($player->zoneId !== $this->currentZone) {
            if ($player->zoneId !== $this->pendingZone) {
                // A new destination. Start the clock; say nothing yet.
                $this->pendingZone = $player->zoneId;
                $this->pendingSince = $now;

                return null;
            }

            if ($now - $this->pendingSince < $this->settleSeconds) {
                return null;
            }

            // They have stayed. Treat it as somewhere they meant to be.
            $this->logger->info('{player} settled in zone {zone}', [
                'player' => $player->name,
                'zone' => $player->zoneId,
            ]);

            $this->currentZone = $player->zoneId;
            $this->pendingZone = null;

            return $this->restage($player, $now, 'moved zone');
        }

        // Back where they started before the clock ran out — forget the excursion.
        $this->pendingZone = null;

        if ($now - $this->lastStagedAt >= $this->refreshSeconds) {
            // Re-order everyone nearby, not only the newcomers. A bot left idle by an earlier
            // pass has nothing in its state we can cheaply tell apart from one that is simply
            // between tasks, so the cheap and reliable answer is to tell them all again.
            $this->stager->rouse($this->stager->namesNear($player));

            return $this->restage($player, $now, 'periodic top-up');
        }

        return null;
    }

    /**
     * Send orders to any bot that has had long enough to arrive.
     */
    private function rouseWhoeverIsReady(int $now): void
    {
        if ([] === $this->toRouse) {
            return;
        }

        $ready = array_values(array_filter($this->toRouse, static fn (array $b): bool => $now >= $b['readyAt']));

        if ([] === $ready) {
            return;
        }

        $this->toRouse = array_values(array_filter($this->toRouse, static fn (array $b): bool => $now < $b['readyAt']));

        $this->stager->rouse(array_column($ready, 'name'));
    }

    /**
     * Forget where the player was, so the next tick repopulates from scratch. Used when they log
     * out: whatever we knew about their whereabouts is stale.
     */
    public function reset(): void
    {
        $this->currentZone = null;
        $this->pendingZone = null;
    }

    private function restage(Actor $player, int $now, string $because): StagingResult
    {
        $this->lastStagedAt = $now;

        // Anyone mid-conversation stays, wherever they are standing. A companion vanishing
        // between your question and its answer reads as the world breaking, not as housekeeping.
        $protected = [];

        foreach ($this->claims as $claim) {
            $protected = array_merge($protected, $claim->protectedFor($player));
        }

        $sentAway = $this->stager->dismissDistant($player, $this->dismissBeyond, $protected);
        $staged = $this->stager->stage($player);

        foreach ($staged->broughtIn as $bot) {
            $this->toRouse[] = ['name' => $bot['name'], 'readyAt' => $now + $this->rouseDelaySeconds];
        }

        $this->logger->info('Restaged around {player} ({because})', [
            'player' => $player->name,
            'because' => $because,
        ]);

        return new StagingResult(
            $staged->alreadyPresent,
            $staged->target,
            $staged->broughtIn,
            $sentAway,
            $staged->limitedBy,
        );
    }
}
