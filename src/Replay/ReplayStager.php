<?php

declare(strict_types=1);

namespace App\Replay;

use App\Herding\BotStagerInterface;
use App\Herding\StagingResult;
use App\World\Model\Actor;

/**
 * Herding, in a world that cannot be herded.
 *
 * Reports the crowd the snapshot already holds and brings nobody in. The alternative — returning
 * a plausible-looking "brought in 19" — would make the one feature this project is named for
 * look like it works when there is nothing behind it.
 */
final class ReplayStager implements BotStagerInterface
{
    public function __construct(private readonly ReplayGateway $world)
    {
    }

    public function stage(Actor $player, ?int $target = null): StagingResult
    {
        return new StagingResult(
            alreadyPresent: \count($this->world->actorsInZone($player->zoneId)),
            target: $target ?? 0,
            broughtIn: [],
            sentAway: [],
        );
    }

    /**
     * @param string[] $names
     */
    public function rouse(array $names): void
    {
        // Nobody to rouse.
    }

    /**
     * @return string[]
     */
    public function namesNear(Actor $player): array
    {
        return array_map(
            static fn (Actor $a): string => $a->name,
            array_filter($this->world->actorsInZone($player->zoneId), static fn (Actor $a): bool => $a->isBot),
        );
    }

    /**
     * @param int[] $alsoProtect
     *
     * @return string[]
     */
    public function dismissDistant(Actor $player, float $beyondYards, array $alsoProtect = []): array
    {
        return [];
    }
}
