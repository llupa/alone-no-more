<?php

declare(strict_types=1);

namespace App\Replay;

use App\Knowledge\QuestLogInterface;

/**
 * Quest logs as they stood when the snapshot was taken, progress counters and all.
 */
final class ReplayQuestLog implements QuestLogInterface
{
    public function __construct(private readonly Snapshot $snapshot)
    {
    }

    /**
     * @return array<int, array{id: int, title: string, level: int, complete: bool, needs: array<int, array{what: string, have: int, need: int}>}>
     */
    public function activeFor(int $guid, int $limit = 25): array
    {
        /** @var array<int, array{id: int, title: string, level: int, complete: bool, needs: array<int, array{what: string, have: int, need: int}>}> $log */
        $log = $this->snapshot->section('questLogs')[(string) $guid] ?? [];

        return \array_slice($log, 0, $limit);
    }

    public function hasCompleted(int $guid, int $questId): bool
    {
        $rewarded = array_map(intval(...), (array) ($this->snapshot->section('questsRewarded')[(string) $guid] ?? []));

        return \in_array($questId, $rewarded, true);
    }
}
