<?php

declare(strict_types=1);

namespace App\Replay;

use App\Knowledge\BagsInterface;

/**
 * A recorded world has no bags.
 *
 * The snapshot holds who stood where, not what was in anyone's pockets, so a companion in the
 * replay demo truthfully says it is not carrying what you asked for rather than miming putting
 * something on.
 */
final readonly class ReplayBags implements BagsInterface
{
    public function wearableNamed(int $guid, string $name): ?array
    {
        return null;
    }

    public function anythingNamed(int $guid, string $name): ?array
    {
        return null;
    }

    public function noBetterThan(int $guid, int $quality = 0, int $limit = 30): array
    {
        return [];
    }
}
