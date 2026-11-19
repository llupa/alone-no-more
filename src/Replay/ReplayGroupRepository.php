<?php

declare(strict_types=1);

namespace App\Replay;

use App\World\GroupRepositoryInterface;

/**
 * The parties that existed when the snapshot was taken.
 */
final class ReplayGroupRepository implements GroupRepositoryInterface
{
    public function __construct(private readonly Snapshot $snapshot)
    {
    }

    /**
     * @return int[]
     */
    public function memberGuidsWith(int $guid): array
    {
        // Everyone else, as the interface promises and the live repository does. Returning the
        // whole group listed the player as a member of their own party.
        return array_values(array_filter($this->groupOf($guid), static fn (int $member): bool => $member !== $guid));
    }

    public function isGrouped(int $guid): bool
    {
        return [] !== $this->memberGuidsWith($guid);
    }

    public function leaderGuid(int $guid): ?int
    {
        // The capture writes each group leader first, so position carries the answer.
        return $this->groupOf($guid)[0] ?? null;
    }

    /**
     * The whole group this character is in, themselves included, leader first.
     *
     * @return int[]
     */
    private function groupOf(int $guid): array
    {
        foreach ($this->snapshot->section('groups') as $members) {
            $members = array_map(intval(...), (array) $members);

            if (\in_array($guid, $members, true)) {
                return $members;
            }
        }

        return [];
    }
}
