<?php

declare(strict_types=1);

namespace App\Knowledge;

use App\World\Model\Position;

/**
 * Who will buy things nearby.
 */
interface MerchantsInterface
{
    /**
     * @return array{name: string, yards: float}|null
     */
    public function nearest(Position $position, float $withinYards = 60.0): ?array;

    /** Whether a bot standing here could actually sell to someone. */
    public function isOneAtHand(Position $position): bool;
}
