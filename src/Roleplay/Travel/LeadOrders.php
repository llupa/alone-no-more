<?php

declare(strict_types=1);

namespace App\Roleplay\Travel;

use App\World\Model\Position;
use App\World\Outbox;

/**
 * Tells a companion where to walk, by asking the worldserver rather than the bot.
 *
 * Six attempts went into doing this from out here, through mod-playerbots' chat commands, and
 * every one of them failed the same way past 150 yards. That number is
 * `Playerbots.ReactDistance`, and it is a hard leash on every route in: a `stay` anchor beyond
 * it is discarded by `ReturnToStayPositionAction::isPossible()`, which silently re-anchors the
 * bot where it stands, and `GoAction`'s coordinate form refuses outright with "It is too far
 * away". Nothing out here can get past that, because it is not a mechanism we are using wrongly
 * — it is a limit on the mechanisms themselves.
 *
 * So it moved inside, to mod-anm-bridge, where the destination is held in the module's own
 * memory and walked in hops on the world tick. Three things fall away as a result: the leash,
 * the "Position stay is set" whisper the player got on every re-anchor, and the stranding when
 * a fight interrupted the walk — the module simply resumes afterwards, because combat cannot
 * clear a destination it does not know about.
 */
final readonly class LeadOrders
{
    public function __construct(private Outbox $outbox)
    {
    }

    /**
     * @return string the empty string when the order was accepted, otherwise why it was not
     */
    public function walkTo(int $botGuid, Position $destination): string
    {
        $id = $this->outbox->queue(
            $botGuid,
            'lead',
            \sprintf('%.2f;%.2f;%.2f', $destination->x, $destination->y, $destination->z),
        );

        $outcome = $this->outbox->awaitOutcome($id);

        if ($outcome['done']) {
            return '';
        }

        return '' !== $outcome['error'] ? $outcome['error'] : 'the server did not answer in time';
    }

    /**
     * Forget where a companion was going, when something else takes over its feet.
     *
     * @param bool $wait whether to wait for the module to act on it, for when another order is
     *                   about to follow and must land after this one
     */
    public function stop(int $botGuid, bool $wait = false): void
    {
        $id = $this->outbox->queue($botGuid, 'unlead', '');

        if ($wait) {
            $this->outbox->awaitOutcome($id);
        }
    }

    /**
     * Why the module gave up on a companion's walk, or null while it is still going.
     *
     * The module is the one that can tell a companion making slow progress round a lake from one
     * jogging into a rock, so it decides when a walk has failed, and says so here.
     */
    public function failure(int $botGuid): ?string
    {
        $latest = $this->outbox->latest($botGuid, 'lead');

        if (null === $latest || 'failed' !== $latest['status']) {
            return null;
        }

        return '' !== $latest['error'] ? $latest['error'] : 'lost the way';
    }
}
