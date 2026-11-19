<?php

declare(strict_types=1);

namespace App\Replay;

use App\Knowledge\GameKnowledgeInterface;
use App\World\Model\Position;

/**
 * The slice of the world database that was captured, answered from memory.
 *
 * A snapshot holds a zone's worth of content rather than the server's 46,000 items and 9,400
 * quests, so lookups here are array scans. At this size that is faster than the queries it
 * replaces and needs no database at all.
 *
 * Where the real implementation would find nothing, this finds nothing too — a snapshot of
 * Teldrassil genuinely does not know about Northrend, and saying so is the honest answer.
 */
final class ReplayKnowledge implements GameKnowledgeInterface
{
    public function __construct(private readonly Snapshot $snapshot)
    {
    }

    public function creaturesNear(Position $position, float $yards = 200.0, int $limit = 8): array
    {
        $near = [];

        foreach ($this->rows('creatures') as $row) {
            if ((int) $row['map'] !== $position->mapId) {
                continue;
            }

            $distance = hypot((float) $row['x'] - $position->x, (float) $row['y'] - $position->y);

            if ($distance > $yards) {
                continue;
            }

            $name = (string) $row['name'];
            // Grouped by creature rather than per spawn, as the live query does: a bot should
            // say "the webwood spiders" once, not name thirty-three of them.
            $near[$name] ??= [
                'name' => $name,
                'level_min' => (int) $row['level_min'],
                'level_max' => (int) $row['level_max'],
                'spawns' => 0,
                'nearest_yards' => \PHP_INT_MAX,
            ];
            ++$near[$name]['spawns'];
            $near[$name]['nearest_yards'] = min($near[$name]['nearest_yards'], (int) round($distance));
        }

        usort($near, static fn (array $a, array $b): int => $a['nearest_yards'] <=> $b['nearest_yards']);

        return \array_slice($near, 0, $limit);
    }

    public function questsForZone(int $zoneId, int $level, int $tolerance = 6, int $limit = 6): array
    {
        $found = [];

        foreach ($this->rows('quests') as $row) {
            if ((int) ($row['zoneId'] ?? 0) !== $zoneId || abs((int) $row['level'] - $level) > $tolerance) {
                continue;
            }

            $found[] = ['id' => (int) $row['id'], 'title' => (string) $row['title'], 'level' => (int) $row['level']];
        }

        return \array_slice($found, 0, $limit);
    }

    public function quest(int $id): ?array
    {
        foreach ($this->rows('quests') as $row) {
            if ((int) $row['id'] === $id) {
                return [
                    'id' => $id,
                    'title' => (string) $row['title'],
                    'level' => (int) $row['level'],
                    'min_level' => (int) ($row['min_level'] ?? 0),
                    'objective' => (string) ($row['objective'] ?? ''),
                    'description' => (string) ($row['description'] ?? ''),
                ];
            }
        }

        return null;
    }

    public function item(int $entry): ?array
    {
        foreach ($this->rows('items') as $row) {
            if ((int) $row['id'] === $entry) {
                return self::asItem($row);
            }
        }

        return null;
    }

    public function findQuestByName(string $name): ?array
    {
        return $this->findQuestsByName($name, 1)[0] ?? null;
    }

    public function findItemByName(string $name): ?array
    {
        $found = $this->matchByName('items', $name, 1);

        return [] === $found ? null : self::asItem($found[0]);
    }

    public function whatDrops(int $itemId, int $limit = 6): array
    {
        $drops = [];

        foreach ($this->rows('drops') as $row) {
            if ((int) $row['itemId'] !== $itemId) {
                continue;
            }

            $drops[] = [
                'creature' => (string) $row['creature'],
                'level_min' => (int) $row['level_min'],
                'level_max' => (int) $row['level_max'],
                'chance' => round((float) $row['chance'], 1),
            ];
        }

        usort($drops, static fn (array $a, array $b): int => $b['chance'] <=> $a['chance']);

        return \array_slice($drops, 0, $limit);
    }

    public function findCreatures(string $name, int $limit = 5): array
    {
        $byName = [];

        foreach ($this->matchByName('creatures', $name, \PHP_INT_MAX) as $row) {
            $key = (string) $row['name'];
            $byName[$key] ??= [
                'name' => $key,
                'level_min' => (int) $row['level_min'],
                'level_max' => (int) $row['level_max'],
                'spawns' => 0,
                'map' => (int) $row['map'],
                'x' => (float) $row['x'],
                'y' => (float) $row['y'],
                // A snapshot records where people stood, height included when it has it.
                'z' => (float) ($row['z'] ?? 0.0),
            ];
            ++$byName[$key]['spawns'];
        }

        return \array_slice(array_values($byName), 0, $limit);
    }

    public function nearestSpawn(string $name, Position $from): ?array
    {
        $nearest = null;
        $best = \INF;
        $chosen = null;

        // The best name first, as findCreatures has it; within that name, the closest row.
        foreach ($this->matchByName('creatures', $name, \PHP_INT_MAX) as $row) {
            if ((int) $row['map'] !== $from->mapId) {
                continue;
            }

            $chosen ??= (string) $row['name'];

            if ((string) $row['name'] !== $chosen) {
                continue;
            }

            $distance = hypot((float) $row['x'] - $from->x, (float) $row['y'] - $from->y);

            if ($distance < $best) {
                $best = $distance;
                $nearest = [
                    'name' => (string) $row['name'],
                    'map' => (int) $row['map'],
                    'x' => (float) $row['x'],
                    'y' => (float) $row['y'],
                    'z' => (float) ($row['z'] ?? 0.0),
                ];
            }
        }

        return $nearest;
    }

    public function questRequiredItems(int $questId): array
    {
        foreach ($this->rows('quests') as $row) {
            if ((int) $row['id'] !== $questId) {
                continue;
            }

            return array_map(static fn (array $need): array => [
                'item' => (string) $need['item'],
                'id' => (int) $need['id'],
                'count' => (int) $need['count'],
            ], (array) ($row['needs'] ?? []));
        }

        return [];
    }

    public function findQuestsByName(string $name, int $limit = 8): array
    {
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'level' => (int) $row['level'],
            'objective' => (string) ($row['objective'] ?? ''),
        ], $this->matchByName('quests', $name, $limit, 'title'));
    }

    public function objectsHolding(int $itemId, int $limit = 60): array
    {
        $held = [];

        foreach ($this->rows('containers') as $row) {
            if ((int) $row['itemId'] !== $itemId) {
                continue;
            }

            $held[] = [
                'object' => (string) $row['object'],
                'chance' => round((float) $row['chance'], 1),
                'map' => (int) $row['map'],
                'x' => (float) $row['x'],
                'y' => (float) $row['y'],
            ];
        }

        return \array_slice($held, 0, $limit);
    }

    public function questsNeedingItem(int $itemId): array
    {
        $wanted = [];

        foreach ($this->rows('quests') as $row) {
            foreach ((array) ($row['needs'] ?? []) as $need) {
                if ((int) $need['id'] === $itemId) {
                    $wanted[] = [
                        'id' => (int) $row['id'],
                        'title' => (string) $row['title'],
                        'level' => (int) $row['level'],
                        'starts' => false,
                    ];
                }
            }
        }

        return $wanted;
    }

    public function findItemsByName(string $name, int $limit = 5): array
    {
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'quality' => (int) ($row['quality'] ?? 0),
            'item_level' => (int) ($row['item_level'] ?? 0),
        ], $this->matchByName('items', $name, $limit));
    }

    /**
     * @return array{starts: string[], ends: string[]}
     */
    public function questGivers(int $questId): array
    {
        foreach ($this->rows('quests') as $row) {
            if ((int) $row['id'] === $questId) {
                return [
                    'starts' => array_map(strval(...), (array) ($row['starts'] ?? [])),
                    'ends' => array_map(strval(...), (array) ($row['ends'] ?? [])),
                ];
            }
        }

        return ['starts' => [], 'ends' => []];
    }

    /**
     * Exact matches before partial ones, shortest first — the same ordering the SQL uses, so
     * "thunderfury" beats "Thunderfury Fragment" here too.
     *
     * @return array<int, array<string, mixed>>
     */
    private function matchByName(string $section, string $name, int $limit, string $field = 'name'): array
    {
        $needle = mb_strtolower(trim($name));
        $exact = [];
        $partial = [];

        foreach ($this->rows($section) as $row) {
            $candidate = mb_strtolower((string) ($row[$field] ?? ''));

            if ($candidate === $needle) {
                $exact[] = $row;
            } elseif ('' !== $needle && str_contains($candidate, $needle)) {
                $partial[] = $row;
            }
        }

        $sort = static fn (array $a, array $b): int => mb_strlen((string) $a[$field]) <=> mb_strlen((string) $b[$field]);
        usort($partial, $sort);

        return \array_slice([...$exact, ...$partial], 0, $limit);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rows(string $section): array
    {
        /** @var array<int, array<string, mixed>> $rows */
        $rows = array_values($this->snapshot->section($section));

        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{id: int, name: string, quality: int, item_level: int, required_level: int, description: string}
     */
    private static function asItem(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'quality' => (int) ($row['quality'] ?? 0),
            'item_level' => (int) ($row['item_level'] ?? 0),
            'required_level' => (int) ($row['required_level'] ?? 0),
            'description' => (string) ($row['description'] ?? ''),
        ];
    }
}
