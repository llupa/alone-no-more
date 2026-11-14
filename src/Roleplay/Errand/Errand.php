<?php

declare(strict_types=1);

namespace App\Roleplay\Errand;

/**
 * A standing order a companion is working through.
 *
 * "Attack" is one swing, which is why "attack until you have seven" got one wolf and silence.
 * The missing idea was not a louder command but a *goal*: what we are killing, what finishing
 * looks like, and the sense to stop when it is done.
 *
 * Deliberately hot memory. An errand is the shape of the next few minutes, not a fact about the
 * world — the player will have moved on by the time the process restarts, and a companion that
 * resumed killing wolves an hour later because a row survived would be a bug, not a feature.
 */
final class Errand
{
    public int $swings = 0;

    public float $lastSwingAt = 0.0;

    public function __construct(
        public readonly int $botGuid,
        /** What the player called it: "wolf", "kobold", or empty for whatever is nearest. */
        public readonly string $what,
        /** The quest this serves, when the player named one, so we know what finished means. */
        public readonly ?int $questId = null,
        public readonly ?string $questTitle = null,
        /**
         * Whose quest it is — not always the companion's.
         *
         * "Help me collect mine, I need six more" is a request to work on the *player's* quest.
         * A companion that had already finished its own copy would check its own log, find
         * nothing outstanding and stop on the very next tick, having just promised to help —
         * which from the player's side is the bot ignoring them.
         */
        public readonly ?int $questOwnerGuid = null,
        /** A stop of last resort: a companion must not grind all evening on a misheard word. */
        public readonly int $maxSwings = 40,
    ) {
    }

    public function exhausted(): bool
    {
        return $this->swings >= $this->maxSwings;
    }

    /**
     * Whether enough time has passed to go again.
     *
     * A fight takes a few seconds and re-issuing every tick would have the bot abandoning a
     * half-killed wolf to run at a fresh one.
     */
    public function readyToSwing(float $now, float $everySeconds): bool
    {
        return $now - $this->lastSwingAt >= $everySeconds;
    }

    public function describe(): string
    {
        return '' === $this->what
            ? 'clearing out whatever is nearby'
            : \sprintf('working through the %s nearby', $this->what);
    }
}
