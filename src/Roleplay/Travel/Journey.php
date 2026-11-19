<?php

declare(strict_types=1);

namespace App\Roleplay\Travel;

use App\World\Model\Position;

/**
 * A companion walking somewhere, and what to do when it gets there.
 *
 * The walking itself is the mod's: a `stay` anchor is moved along the route, and its "return
 * to stay position" trigger carries the bot to it and holds it there. What this has to do is
 * keep the anchor inside the leash.
 *
 * `ReturnToStayPositionAction::isPossible()` throws away any anchor further than
 * `Playerbots.ReactDistance` — 150 yards by default — and silently re-anchors the bot where it
 * stands. That single limit is why leading the way failed five times running: every rewrite
 * worked at a hundred yards and froze at three hundred, so each new mechanism looked plausible
 * and none of them was the problem. The bot's own words gave it away in the end — "about a
 * hundred paces" always arrived, "about 303 paces east" never did.
 *
 * So the route is walked in legs shorter than the leash — and the anchor is re-sent every
 * pass rather than once per leg, because the server destroys it without saying so:
 * `PlayerbotAI::ChangeEngineOnNonCombat()` resets the stay position every time a bot leaves
 * combat. One wandering boar was enough to strand a companion for good.
 *
 * Hot memory, like an errand: a walk is the next minute, not a fact about the world.
 */
final class Journey
{
    public function __construct(
        public readonly int $botGuid,
        public readonly Position $destination,
        public readonly string $towards,
        public readonly float $startedAt,
        /** Close enough. The bot stops short of the target and pathing rarely lands on a point. */
        public readonly float $arrivalYards = 10.0,
        /**
         * A walk that never finishes must not leave a bot marked as travelling for ever.
         *
         * Only a backstop. The module gives up on a walk that stops getting closer, and says
         * so; this is for when nothing is heard at all. It used to be three minutes, which cut
         * off long walks that were still going fine.
         */
        public readonly float $giveUpAfter = 900.0,
        /**
         * How far ahead to place the anchor, which must stay under the server's
         * `Playerbots.ReactDistance` (150 yards as shipped). The margin is deliberate: the leg
         * is measured when it is set, and a bot that overshoots slightly must not tip over.
         */
        public readonly float $legYards = 120.0,
    ) {
    }

    /** Where the anchor was last put, and when, so it is not re-sent needlessly. */
    public ?Position $anchor = null;
    public float $anchoredAt = 0.0;
    public ?Position $wasAt = null;

    /**
     * Whether to re-send the anchor this pass.
     *
     * Every `position stay` makes the companion whisper "Position stay is set" at the player,
     * so this cannot run every tick — that turned a stuck bot into a stuck bot talking once a
     * second. But it cannot be once per leg either: the server silently resets the stay
     * position whenever the bot leaves combat, and a companion that is waiting to reach an
     * anchor that no longer exists waits for ever.
     *
     * So: when the leg has been walked, or when nothing has moved for a while, which is the
     * only outward sign that the anchor was taken away.
     */
    public function needsAnchor(Position $at, float $now, float $stalledFor = 8.0): bool
    {
        if (null === $this->anchor || null === $this->wasAt) {
            return true;
        }

        if (hypot($at->x - $this->anchor->x, $at->y - $this->anchor->y) <= $this->arrivalYards * 2) {
            return true;
        }

        $moved = hypot($at->x - $this->wasAt->x, $at->y - $this->wasAt->y);

        return $moved < 2.0 && ($now - $this->anchoredAt) >= $stalledFor;
    }

    /**
     * The next anchor along the route: the destination itself when it is within a leg,
     * otherwise a point that far toward it — a carrot that stays a leg ahead until the
     * destination itself comes within reach.
     *
     * Z is taken from where the bot is standing rather than from the destination. Over a leg
     * the ground barely moves, and the alternative — an average of every spawn point of an NPC
     * — is how you anchor someone inside a hillside.
     */
    public function nextLeg(Position $from): Position
    {
        $dx = $this->destination->x - $from->x;
        $dy = $this->destination->y - $from->y;
        $togo = hypot($dx, $dy);

        if ($togo <= $this->legYards || 0.0 === $togo) {
            return new Position($this->destination->x, $this->destination->y, $from->z, $this->destination->mapId);
        }

        $step = $this->legYards / $togo;

        return new Position(
            $from->x + ($dx * $step),
            $from->y + ($dy * $step),
            $from->z,
            $this->destination->mapId,
        );
    }

    public function hasArrived(Position $at): bool
    {
        return $at->mapId === $this->destination->mapId
            && hypot($at->x - $this->destination->x, $at->y - $this->destination->y) <= $this->arrivalYards;
    }

    public function gaveUp(float $now): bool
    {
        return $now - $this->startedAt > $this->giveUpAfter;
    }
}
