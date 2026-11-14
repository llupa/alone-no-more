<?php

declare(strict_types=1);

namespace App\Herding;

use App\World\Model\Actor;

/**
 * Brings bots into the world around a player, and sends away the ones left behind.
 *
 * An interface because the watcher's judgement — when to act, when to wait out a border
 * crossing — is the part worth testing, and it should be testable without a database or a
 * running game.
 */
interface BotStagerInterface
{
    public function stage(Actor $player, ?int $target = null): StagingResult;

    /**
     * Give newly woken bots something to do. A bot logs in idle and stays that way until told
     * otherwise — the mod's own tick normally handles this, and that tick is switched off.
     *
     * @param string[] $names
     */
    public function rouse(array $names): void;

    /**
     * Names of bots currently in the world near this player.
     *
     * @return string[]
     */
    public function namesNear(Actor $player): array;

    /**
     * @param int[] $alsoProtect bots that must be left alone wherever they stand
     *
     * @return string[] names of the bots sent away
     */
    public function dismissDistant(Actor $player, float $beyondYards, array $alsoProtect = []): array;
}
