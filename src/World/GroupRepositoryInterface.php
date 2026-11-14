<?php

declare(strict_types=1);

namespace App\World;

/**
 * Party and raid membership.
 *
 * An interface rather than a concrete dependency because the selector's rules — who may answer,
 * and when the party stays quiet — are the part worth testing, and they should be testable
 * without a database. Our classes are `final`, which PHPUnit cannot double, so depending on the
 * abstraction is what makes that possible. That is the dependency-inversion rule earning its keep
 * rather than being quoted.
 */
interface GroupRepositoryInterface
{
    /**
     * Guids of everyone grouped with this character, excluding the character itself.
     *
     * @return int[]
     */
    public function memberGuidsWith(int $guid): array;

    public function isGrouped(int $guid): bool;

    public function leaderGuid(int $guid): ?int;
}
