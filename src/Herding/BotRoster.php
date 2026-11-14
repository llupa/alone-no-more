<?php

declare(strict_types=1);

namespace App\Herding;

use App\World\Model\Actor;
use App\World\Model\Faction;
use App\World\Model\Position;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Which bot characters exist, where they are saved, and which are in the world.
 *
 * Reads the characters table directly rather than going through the gateway, because herding
 * cares about bots that are *offline* — and a gateway built around who is present cannot see
 * them.
 */
final readonly class BotRoster
{
    public function __construct(
        #[Autowire(service: 'anm.characters_connection')]
        private Connection $characters,
    ) {
    }

    /**
     * Offline bots who could plausibly be standing next to this player: close in level, and
     * already initialised.
     *
     * Level is the filter that matters most. A level 1 in Ashenvale reads as wrong however good
     * the dialogue, and the pool spans 1-80, so without this most candidates are nonsense.
     *
     * Bots never randomised (zone 0) are skipped: they have no gear, no talents and no sensible
     * level, so bringing one into the world produces an obvious prop.
     *
     * @return array<int, array{guid: int, name: string, level: int, race: int}>
     */
    /**
     * A dormant bot of a particular calling, for someone who asked for one.
     *
     * The crowd is assembled by level and faction and nothing else, which is right for filling a
     * zone and useless for "I want to level with a priest". This is the narrow version of the
     * same query: one companion, chosen on purpose.
     *
     * @return array{guid: int, name: string, level: int, race: int, class: int}|null
     */
    public function dormantOfClass(
        Actor $player,
        ?int $classId,
        int $tolerance,
        ?Faction $faction = null,
        ?int $raceId = null,
        ?int $genderId = null,
    ): ?array {
        // A named race overrides the faction net: asking for a night elf has already decided
        // which side they are on, and keeping both filters would only let them disagree.
        $races = null !== $raceId ? [$raceId] : self::racesOf($faction);

        $row = $this->characters->fetchAssociative(
            <<<'SQL'
                SELECT c.guid, c.name, c.level, c.race, c.class
                FROM characters c
                JOIN acore_auth.account a ON a.id = c.account
                WHERE a.username LIKE 'RNDBOT%'
                  AND c.online = 0
                  AND c.zone <> 0
                  AND c.level BETWEEN :min AND :max
                SQL
                .(null === $races ? '' : ' AND c.race IN (:races)')
                .(null === $classId ? '' : ' AND c.class = :class')
                .(null === $genderId ? '' : ' AND c.gender = :gender')
                // Closest in level first, so "a priest of my level" means it rather than
                // anyone within the tolerance.
                .' ORDER BY ABS(c.level - :exact), RAND() LIMIT 1',
            array_filter([
                'min' => max(1, $player->level - $tolerance),
                'max' => $player->level + $tolerance,
                'exact' => $player->level,
                'races' => $races,
                'class' => $classId,
                'gender' => $genderId,
            ], static fn ($v): bool => null !== $v),
            array_filter([
                'races' => null === $races ? null : \Doctrine\DBAL\ArrayParameterType::INTEGER,
                'class' => null === $classId ? null : ParameterType::INTEGER,
                'gender' => null === $genderId ? null : ParameterType::INTEGER,
            ], static fn ($v): bool => null !== $v),
        );

        return false === $row ? null : [
            'guid' => (int) $row['guid'],
            'name' => (string) $row['name'],
            'level' => (int) $row['level'],
            'race' => (int) $row['race'],
            'class' => (int) $row['class'],
        ];
    }

    /**
     * @return int[]|null race ids of a faction, or null for either
     */
    private static function racesOf(?Faction $faction): ?array
    {
        if (null === $faction) {
            return null;
        }

        return array_map(
            static fn (\App\World\Model\CharacterRace $r): int => $r->value,
            array_values(array_filter(
                \App\World\Model\CharacterRace::cases(),
                static fn (\App\World\Model\CharacterRace $r): bool => $r->faction() === $faction,
            )),
        );
    }

    /**
     * @return array<int, array{guid: int, name: string, level: int, race: int}>
     */
    public function dormantPeersOf(Actor $player, int $tolerance, int $limit, ?Faction $faction = null): array
    {
        // Race decides faction, and the dormant rows carry only a race id, so the filter is a
        // race list rather than a join.
        $races = null === $faction
            ? null
            : array_map(
                static fn (\App\World\Model\CharacterRace $r): int => $r->value,
                array_values(array_filter(
                    \App\World\Model\CharacterRace::cases(),
                    static fn (\App\World\Model\CharacterRace $r): bool => $r->faction() === $faction,
                )),
            );

        return array_map(
            static fn (array $r): array => [
                'guid' => (int) $r['guid'],
                'name' => $r['name'],
                'level' => (int) $r['level'],
                'race' => (int) $r['race'],
            ],
            $this->characters->fetchAllAssociative(
                <<<'SQL'
                    SELECT c.guid, c.name, c.level, c.race
                    FROM characters c
                    JOIN acore_auth.account a ON a.id = c.account
                    WHERE a.username LIKE 'RNDBOT%'
                      AND c.online = 0
                      AND c.zone <> 0
                      AND c.level BETWEEN :min AND :max
                    SQL
                    .(null === $races ? '' : ' AND c.race IN (:races)')
                    .' ORDER BY RAND() LIMIT :limit',
                array_filter([
                    'min' => max(1, $player->level - $tolerance),
                    'max' => $player->level + $tolerance,
                    'limit' => $limit,
                    'races' => $races,
                ], static fn ($v): bool => null !== $v),
                array_filter([
                    'limit' => ParameterType::INTEGER,
                    'races' => null === $races ? null : \Doctrine\DBAL\ArrayParameterType::INTEGER,
                ], static fn ($v): bool => null !== $v),
            ),
        );
    }

    /**
     * Move a dormant bot to where we want it to be found.
     *
     * Only ever applied to a bot that is offline, which is the whole trick: nobody can watch an
     * offline character arrive, so there is no teleport to see. It simply exists there when it
     * logs in.
     *
     * @return bool false when the bot came online in the meantime, in which case it must be left
     *              alone — overwriting a live character's position would teleport them in view
     */
    public function placeDormant(int $botGuid, Position $at, int $zoneId): bool
    {
        return 1 === $this->characters->executeStatement(
            'UPDATE characters
             SET position_x = :x, position_y = :y, position_z = :z, map = :map, zone = :zone, orientation = :o
             WHERE guid = :guid AND online = 0',
            [
                'x' => $at->x,
                'y' => $at->y,
                'z' => $at->z,
                'map' => $at->mapId,
                'zone' => $zoneId,
                'o' => mt_rand(0, 628) / 100,
                'guid' => $botGuid,
            ],
        );
    }

    /**
     * Bots currently in the world and far from the player, oldest-placed first — candidates to
     * send away so the population can follow the player instead of accumulating.
     *
     * @param int[] $protectedGuids never returned, whatever the distance
     *
     * @return array<int, array{guid: int, name: string}>
     */
    public function onlineBotsBeyond(Position $centre, float $yards, array $protectedGuids, int $limit): array
    {
        $sql = <<<'SQL'
            SELECT c.guid, c.name
            FROM characters c
            JOIN acore_auth.account a ON a.id = c.account
            WHERE a.username LIKE 'RNDBOT%'
              AND c.online = 1
              AND (c.map <> :map OR POW(c.position_x - :x, 2) + POW(c.position_y - :y, 2) > :radius2)
            SQL;

        $params = [
            'map' => $centre->mapId,
            'x' => $centre->x,
            'y' => $centre->y,
            'radius2' => $yards * $yards,
            'limit' => $limit,
        ];
        $types = ['limit' => ParameterType::INTEGER];

        if ([] !== $protectedGuids) {
            $sql .= ' AND c.guid NOT IN (:protected)';
            $params['protected'] = $protectedGuids;
            $types['protected'] = \Doctrine\DBAL\ArrayParameterType::INTEGER;
        }

        $sql .= ' ORDER BY RAND() LIMIT :limit';

        return array_map(
            static fn (array $r): array => ['guid' => (int) $r['guid'], 'name' => $r['name']],
            $this->characters->fetchAllAssociative($sql, $params, $types),
        );
    }

    /**
     * Names of bots in the world near the player.
     *
     * Used to re-issue orders periodically. A bot that was brought in by an earlier pass — or
     * by a version of this code that got the timing wrong — stands still forever otherwise,
     * and there is nothing in its state we can cheaply distinguish from a bot that is simply
     * pausing. Re-ordering one that is already busy costs nothing.
     *
     * @return string[]
     */
    /**
     * @param int[] $excludeGuids
     *
     * @return string[]
     */
    public function onlineBotNamesWithin(Position $centre, float $yards, array $excludeGuids = [], int $limit = 80): array
    {
        $sql = <<<'SQL'
            SELECT c.name
            FROM characters c
            JOIN acore_auth.account a ON a.id = c.account
            WHERE a.username LIKE 'RNDBOT%'
              AND c.online = 1
              AND c.map = :map
              AND POW(c.position_x - :x, 2) + POW(c.position_y - :y, 2) <= :radius2
            SQL;

        $params = ['map' => $centre->mapId, 'x' => $centre->x, 'y' => $centre->y, 'radius2' => $yards * $yards, 'limit' => $limit];
        $types = ['limit' => ParameterType::INTEGER];

        if ([] !== $excludeGuids) {
            $sql .= ' AND c.guid NOT IN (:excluded)';
            $params['excluded'] = $excludeGuids;
            $types['excluded'] = \Doctrine\DBAL\ArrayParameterType::INTEGER;
        }

        return $this->characters->fetchFirstColumn($sql.' LIMIT :limit', $params, $types);
    }

    /**
     * How many bots are already in the world within reach of the player.
     */
    public function onlineBotsWithin(Position $centre, float $yards): int
    {
        return (int) $this->characters->fetchOne(
            <<<'SQL'
                SELECT COUNT(*)
                FROM characters c
                JOIN acore_auth.account a ON a.id = c.account
                WHERE a.username LIKE 'RNDBOT%'
                  AND c.online = 1
                  AND c.map = :map
                  AND POW(c.position_x - :x, 2) + POW(c.position_y - :y, 2) <= :radius2
                SQL,
            ['map' => $centre->mapId, 'x' => $centre->x, 'y' => $centre->y, 'radius2' => $yards * $yards],
        );
    }

    /**
     * Faction of a race id, without needing a full Actor — the dormant rows carry only ids.
     */
    public static function factionOfRace(int $race): ?Faction
    {
        return \App\World\Model\CharacterRace::tryFrom($race)?->faction();
    }
}
