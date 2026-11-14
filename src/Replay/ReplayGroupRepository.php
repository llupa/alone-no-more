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
        foreach ($this->snapshot->section('groups') as $members) {
            $members = array_map(intval(...), (array) $members);

            if (\in_array($guid, $members, true)) {
                return $members;
            }
        }

        return [];
    }

    public function isGrouped(int $guid): bool
    {
        return [] !== $this->memberGuidsWith($guid);
    }

    public function leaderGuid(int $guid): ?int
    {
        // The capture writes each group leader first, so position carries the answer.
        return $this->memberGuidsWith($guid)[0] ?? null;
    }
}
