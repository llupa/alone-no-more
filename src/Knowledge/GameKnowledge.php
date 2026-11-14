<?php

declare(strict_types=1);

namespace App\Knowledge;

use App\World\Model\Position;
use App\World\RespawnState;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * What a character would plausibly know about where they are standing, read from the server's
 * own world database.
 *
 * This exists because an ungrounded model invents landmarks. Asked for work near Astranaar, a
 * dwarf bot offered a job at "Raptor's Spine" — a place that does not exist. A confidently wrong
 * landmark is worse than a vague answer, because any player who knows the zone sees the seam
 * immediately.
 *
 * The data is local, authoritative and free: 150k creature spawns, 96k object spawns, 9.4k quests
 * and 46k items, and it matches *this* server exactly, which a public wiki may not.
 */
#[AsAlias(GameKnowledgeInterface::class)]
final readonly class GameKnowledge implements GameKnowledgeInterface
{
    public function __construct(
        #[Autowire(service: 'anm.world_connection')]
        private Connection $world,
        private RespawnState $respawns,
    ) {
    }

    /**
     * Creatures actually spawned around a point, commonest and closest first.
     *
     * Grouped by creature rather than listed per spawn: a bot should say "the webwood spiders"
     * once, not name thirty-three of them.
     *
     * Creatures the party has killed are left out. The spawn table alone would have a companion
     * warning about a rare long after you put it down, which reads as the world not noticing
     * what you just did.
     *
     * @return array<int, array{name: string, level_min: int, level_max: int, spawns: int, nearest_yards: int}>
     */
    public function creaturesNear(Position $position, float $yards = 200.0, int $limit = 8): array
    {
        $rows = $this->world->fetchAllAssociative(
            <<<'SQL'
                SELECT ct.name,
                       ct.minlevel AS level_min,
                       ct.maxlevel AS level_max,
                       COUNT(*)    AS spawns,
                       ROUND(MIN(SQRT(POW(c.position_x - :x, 2) + POW(c.position_y - :y, 2)))) AS nearest_yards
                FROM creature c
                JOIN creature_template ct ON ct.entry = c.id
                WHERE c.map = :map
                  AND POW(c.position_x - :x, 2) + POW(c.position_y - :y, 2) <= :radius2
                  -- Exclude the server's own scaffolding. CREATURE_FLAG_EXTRA_TRIGGER (0x80)
                  -- marks invisible trigger creatures, and designers prefix placeholders and
                  -- do-not-disturb objects with [PH] / [DND]. A bot warning you about a
                  -- "TAR Pedestal" breaks the illusion faster than saying nothing at all.
                  AND (ct.flags_extra & 0x80) = 0
                  AND ct.name NOT LIKE '[%'
                  AND c.guid NOT IN (:dead)
                GROUP BY ct.entry
                ORDER BY nearest_yards
                LIMIT :limit
                SQL,
            [
                'x' => $position->x,
                'y' => $position->y,
                'map' => $position->mapId,
                'radius2' => $yards * $yards,
                'limit' => $limit,
                // NOT IN () is a syntax error, so a world with nothing dead needs a guid that
                // cannot match. Spawn guids start at 1.
                'dead' => $this->respawns->deadOn($position->mapId) ?: [0],
            ],
            ['limit' => ParameterType::INTEGER, 'dead' => ArrayParameterType::INTEGER],
        );

        return array_map(static fn (array $r): array => [
            'name' => $r['name'],
            'level_min' => (int) $r['level_min'],
            'level_max' => (int) $r['level_max'],
            'spawns' => (int) $r['spawns'],
            'nearest_yards' => (int) $r['nearest_yards'],
        ], $rows);
    }

    /**
     * Quests a character of this level would plausibly have heard of in this zone.
     *
     * @return array<int, array{id: int, title: string, level: int}>
     */
    public function questsForZone(int $zoneId, int $level, int $tolerance = 6, int $limit = 6): array
    {
        $rows = $this->world->fetchAllAssociative(
            <<<'SQL'
                SELECT ID AS id, LogTitle AS title, QuestLevel AS level
                FROM quest_template
                WHERE QuestSortID = :zone
                  AND QuestLevel BETWEEN :min AND :max
                ORDER BY ABS(QuestLevel - :level)
                LIMIT :limit
                SQL,
            [
                'zone' => $zoneId,
                'min' => $level - $tolerance,
                'max' => $level + $tolerance,
                'level' => $level,
                'limit' => $limit,
            ],
            ['limit' => ParameterType::INTEGER],
        );

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'title' => $r['title'],
            'level' => (int) $r['level'],
        ], $rows);
    }

    /**
     * A quest by id — what a player links when asking "do you have this one?".
     *
     * @return array{id: int, title: string, level: int, min_level: int, objective: string, description: string}|null
     */
    public function quest(int $id): ?array
    {
        $row = $this->world->fetchAssociative(
            // LogDescription is what the quest log shows as the objective; QuestDescription is
            // the giver's flavour text. ObjectiveText1..4 are per-objective and usually empty.
            'SELECT ID, LogTitle, QuestLevel, MinLevel, LogDescription, QuestDescription
             FROM quest_template WHERE ID = :id',
            ['id' => $id],
        );

        if (false === $row) {
            return null;
        }

        return [
            'id' => (int) $row['ID'],
            'title' => (string) $row['LogTitle'],
            'level' => (int) $row['QuestLevel'],
            'min_level' => (int) $row['MinLevel'],
            'objective' => (string) ($row['LogDescription'] ?? ''),
            'description' => (string) ($row['QuestDescription'] ?? ''),
        ];
    }

    /**
     * An item by id — what a player links in chat.
     *
     * @return array{id: int, name: string, quality: int, item_level: int, required_level: int, description: string}|null
     */
    public function item(int $entry): ?array
    {
        $row = $this->world->fetchAssociative(
            'SELECT entry, name, Quality, ItemLevel, RequiredLevel, description
             FROM item_template WHERE entry = :entry',
            ['entry' => $entry],
        );

        if (false === $row) {
            return null;
        }

        return [
            'id' => (int) $row['entry'],
            'name' => $row['name'],
            'quality' => (int) $row['Quality'],
            'item_level' => (int) $row['ItemLevel'],
            'required_level' => (int) $row['RequiredLevel'],
            'description' => $row['description'] ?? '',
        ];
    }

    /**
     * Find a quest by the name someone typed.
     *
     * @return array{id: int, title: string, level: int, objective: string}|null
     */
    public function findQuestByName(string $name): ?array
    {
        $row = $this->world->fetchAssociative(
            <<<'SQL'
                SELECT ID, LogTitle, QuestLevel, LogDescription
                FROM quest_template
                WHERE LogTitle = :exact OR LogTitle LIKE :like
                ORDER BY (LogTitle = :exact) DESC, CHAR_LENGTH(LogTitle)
                LIMIT 1
                SQL,
            ['exact' => $name, 'like' => '%'.$name.'%'],
        );

        if (false === $row) {
            return null;
        }

        return [
            'id' => (int) $row['ID'],
            'title' => (string) $row['LogTitle'],
            'level' => (int) $row['QuestLevel'],
            'objective' => (string) ($row['LogDescription'] ?? ''),
        ];
    }

    /**
     * Find one item by the name someone typed, preferring an exact match.
     *
     * @return array{id: int, name: string, quality: int, item_level: int, required_level: int, description: string}|null
     */
    public function findItemByName(string $name): ?array
    {
        $row = $this->world->fetchAssociative(
            <<<'SQL'
                SELECT entry, name, Quality, ItemLevel, RequiredLevel, description
                FROM item_template
                WHERE name = :exact OR name LIKE :like
                ORDER BY (name = :exact) DESC, CHAR_LENGTH(name)
                LIMIT 1
                SQL,
            ['exact' => $name, 'like' => '%'.$name.'%'],
        );

        if (false === $row) {
            return null;
        }

        return [
            'id' => (int) $row['entry'],
            'name' => (string) $row['name'],
            'quality' => (int) $row['Quality'],
            'item_level' => (int) $row['ItemLevel'],
            'required_level' => (int) $row['RequiredLevel'],
            'description' => (string) ($row['description'] ?? ''),
        ];
    }

    /**
     * Who drops an item, commonest first.
     *
     * The question behind "where do I get this" nearly always. Chance is the raw drop rate from
     * the loot table, which is the honest number rather than a guess.
     *
     * @return array<int, array{creature: string, level_min: int, level_max: int, chance: float}>
     */
    public function whatDrops(int $itemId, int $limit = 6): array
    {
        $rows = $this->world->fetchAllAssociative(
            <<<'SQL'
                SELECT ct.name, ct.minlevel, ct.maxlevel, lt.Chance
                FROM creature_loot_template lt
                JOIN creature_template ct ON ct.entry = lt.Entry
                WHERE lt.Item = :item
                  AND (ct.flags_extra & 0x80) = 0
                  AND ct.name NOT LIKE '[%'
                ORDER BY lt.Chance DESC
                LIMIT :limit
                SQL,
            ['item' => $itemId, 'limit' => $limit],
            ['limit' => ParameterType::INTEGER],
        );

        return array_map(static fn (array $r): array => [
            'creature' => (string) $r['name'],
            'level_min' => (int) $r['minlevel'],
            'level_max' => (int) $r['maxlevel'],
            'chance' => round((float) $r['Chance'], 1),
        ], $rows);
    }

    /**
     * Creatures whose name matches, with roughly where they are found.
     *
     * Position is averaged over their spawns, which is a fair answer to "where do I find these"
     * for a pack that shares a hillside and a poor one for something spawned across a continent —
     * the spawn count tells the caller which it is dealing with.
     *
     * Dead creatures are deliberately *not* filtered out here, unlike {@see creaturesNear()}.
     * The two questions differ: "what can I fight right now" must exclude a corpse, while "where
     * does Milly Osworth stand" is about where she lives, and she will be back.
     *
     * @return array<int, array{name: string, level_min: int, level_max: int, spawns: int, map: int, x: float, y: float, z: float}>
     */
    public function findCreatures(string $name, int $limit = 5): array
    {
        $rows = $this->world->fetchAllAssociative(
            <<<'SQL'
                SELECT ct.name, ct.minlevel, ct.maxlevel, COUNT(*) AS spawns,
                       c.map, AVG(c.position_x) AS x, AVG(c.position_y) AS y, AVG(c.position_z) AS z
                FROM creature c
                JOIN creature_template ct ON ct.entry = c.id
                WHERE ct.name LIKE :like
                  AND (ct.flags_extra & 0x80) = 0
                  AND ct.name NOT LIKE '[%'
                GROUP BY ct.entry, c.map
                -- Exact name first, then the shortest: asking for Thrall should not hand back
                -- three hundred Thrallmar Grunts just because there are more of them.
                ORDER BY (ct.name = :exact) DESC, CHAR_LENGTH(ct.name), spawns DESC
                LIMIT :limit
                SQL,
            ['like' => '%'.$name.'%', 'exact' => $name, 'limit' => $limit],
            ['limit' => ParameterType::INTEGER],
        );

        return array_map(static fn (array $r): array => [
            'name' => (string) $r['name'],
            'level_min' => (int) $r['minlevel'],
            'level_max' => (int) $r['maxlevel'],
            'spawns' => (int) $r['spawns'],
            'map' => (int) $r['map'],
            'x' => (float) $r['x'],
            'y' => (float) $r['y'],
            'z' => (float) $r['z'],
        ], $rows);
    }

    /**
     * What a quest actually asks you to fetch, resolved to item names.
     *
     * @return array<int, array{item: string, id: int, count: int}>
     */
    public function questRequiredItems(int $questId): array
    {
        $row = $this->world->fetchAssociative(
            'SELECT RequiredItemId1, RequiredItemCount1, RequiredItemId2, RequiredItemCount2,
                    RequiredItemId3, RequiredItemCount3, RequiredItemId4, RequiredItemCount4
             FROM quest_template WHERE ID = :id',
            ['id' => $questId],
        );

        if (false === $row) {
            return [];
        }

        $needed = [];

        for ($i = 1; $i <= 4; ++$i) {
            $itemId = (int) ($row['RequiredItemId'.$i] ?? 0);

            if (0 === $itemId) {
                continue;
            }

            $item = $this->item($itemId);

            if (null !== $item) {
                $needed[] = [
                    'item' => $item['name'],
                    'id' => $itemId,
                    'count' => (int) ($row['RequiredItemCount'.$i] ?? 1),
                ];
            }
        }

        return $needed;
    }

    /**
     * Every quest matching a name, not just one of them.
     *
     * Chains reuse a title for each step: seven separate quests are called "Crown of the Earth",
     * at levels 5 through 11, each wanting a different item. Picking one with LIMIT 1 returns an
     * arbitrary step, and the answer is then confidently about the wrong quest — the failure is
     * invisible because the title read back is exactly the one the player asked about.
     *
     * Ordered so exact title matches come first and the chain reads in the order it is played.
     *
     * @return array<int, array{id: int, title: string, level: int, objective: string}>
     */
    public function findQuestsByName(string $name, int $limit = 8): array
    {
        $rows = $this->world->fetchAllAssociative(
            <<<'SQL'
                SELECT ID, LogTitle, QuestLevel, LogDescription
                FROM quest_template
                WHERE LogTitle = :exact OR LogTitle LIKE :like
                ORDER BY (LogTitle = :exact) DESC, CHAR_LENGTH(LogTitle), QuestLevel, ID
                LIMIT :limit
                SQL,
            ['exact' => $name, 'like' => '%'.$name.'%', 'limit' => $limit],
            ['limit' => ParameterType::INTEGER],
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['ID'],
            'title' => (string) $row['LogTitle'],
            'level' => (int) $row['QuestLevel'],
            'objective' => (string) ($row['LogDescription'] ?? ''),
        ], $rows);
    }

    /**
     * Containers holding an item — chests, crates and the like.
     *
     * Plenty of quest items are never carried by anything alive: the four Relics of Wakening sit
     * in four chests in a cave. Asking only `creature_loot_template` for those returns nothing,
     * and "nothing drops this" is a confident lie about an item the player can see in their bag.
     *
     * The join is not the obvious one. `gameobject_loot_template.Entry` is a *loot id*, not an
     * object id; the object points at it through `gameobject_template.Data1`, which for a chest
     * (type 3) is its loot table. Joining Entry to entry silently matches nothing.
     *
     * Spawns are returned individually rather than grouped so the caller can place each one in a
     * zone; averaging coordinates across scattered spawns would name a zone containing none.
     *
     * @return array<int, array{object: string, chance: float, map: int, x: float, y: float}>
     */
    public function objectsHolding(int $itemId, int $limit = 60): array
    {
        $rows = $this->world->fetchAllAssociative(
            <<<'SQL'
                SELECT gt.name AS name, lt.Chance AS chance, g.map AS map,
                       g.position_x AS x, g.position_y AS y
                FROM gameobject_loot_template lt
                JOIN gameobject_template gt ON gt.Data1 = lt.Entry AND gt.type = 3
                JOIN gameobject g ON g.id = gt.entry
                WHERE lt.Item = :item
                ORDER BY lt.Chance DESC
                LIMIT :limit
                SQL,
            ['item' => $itemId, 'limit' => $limit],
            ['item' => ParameterType::INTEGER, 'limit' => ParameterType::INTEGER],
        );

        return array_map(static fn (array $r): array => [
            'object' => (string) $r['name'],
            'chance' => round((float) $r['chance'], 1),
            'map' => (int) $r['map'],
            'x' => (float) $r['x'],
            'y' => (float) $r['y'],
        ], $rows);
    }

    /**
     * Who hands a quest out and who takes it back.
     *
     * Rarely the same person, and the difference is exactly what a player needs: "A Threat
     * Within" comes from Deputy Willem but is turned in to Marshal McBride. Without this a
     * companion either says it does not know, or guesses the giver — which is confidently wrong
     * for half the quests in the game.
     *
     * @return array{starts: string[], ends: string[]}
     */
    public function questGivers(int $questId): array
    {
        $names = static fn (array $rows): array => array_values(array_unique(array_column($rows, 'name')));

        return [
            'starts' => $names($this->world->fetchAllAssociative(
                'SELECT ct.name FROM creature_queststarter q JOIN creature_template ct ON ct.entry = q.id WHERE q.quest = :quest',
                ['quest' => $questId],
                ['quest' => ParameterType::INTEGER],
            )),
            'ends' => $names($this->world->fetchAllAssociative(
                'SELECT ct.name FROM creature_questender q JOIN creature_template ct ON ct.entry = q.id WHERE q.quest = :quest',
                ['quest' => $questId],
                ['quest' => ParameterType::INTEGER],
            )),
        ];
    }

    /**
     * The quests that want this item — the inverse of {@see questRequiredItems()}.
     *
     * Without this the archives can only answer forwards, from a quest to its items, so a player
     * holding a drop and asking "what is this for" gets told there is no record of it. That is
     * worse than an unhelpful answer: the record exists, it just wasn't reachable from the
     * direction the player asked.
     *
     * An item can also *start* a quest rather than be collected for one, which reads identically
     * to the player ("this is for something"), so both are reported.
     *
     * @return array<int, array{id: int, title: string, level: int, starts: bool}>
     */
    public function questsNeedingItem(int $itemId): array
    {
        $rows = $this->world->fetchAllAssociative(
            <<<'SQL'
                SELECT q.ID AS id, q.LogTitle AS title, q.QuestLevel AS level, 0 AS starts
                FROM quest_template q
                WHERE :id IN (q.RequiredItemId1, q.RequiredItemId2, q.RequiredItemId3, q.RequiredItemId4)
                UNION
                SELECT q.ID AS id, q.LogTitle AS title, q.QuestLevel AS level, 1 AS starts
                FROM item_template i
                JOIN quest_template q ON q.ID = i.startquest
                WHERE i.entry = :id
                SQL,
            ['id' => $itemId],
            ['id' => ParameterType::INTEGER],
        );

        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'title' => (string) $row['title'],
                'level' => (int) $row['level'],
                'starts' => (bool) $row['starts'],
            ],
            $rows,
        );
    }

    /**
     * Items whose name matches what the player typed, for when they ask in words rather than
     * linking. Exact matches first, then prefixes, so "thunderfury" beats "Thunderfury Fragment".
     *
     * @return array<int, array{id: int, name: string, quality: int, item_level: int}>
     */
    public function findItemsByName(string $name, int $limit = 5): array
    {
        $rows = $this->world->fetchAllAssociative(
            <<<'SQL'
                SELECT entry AS id, name, Quality AS quality, ItemLevel AS item_level
                FROM item_template
                WHERE name LIKE :like
                ORDER BY (name = :exact) DESC, (name LIKE :prefix) DESC, ItemLevel DESC
                LIMIT :limit
                SQL,
            [
                'like' => '%'.$name.'%',
                'exact' => $name,
                'prefix' => $name.'%',
                'limit' => $limit,
            ],
            ['limit' => ParameterType::INTEGER],
        );

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'name' => $r['name'],
            'quality' => (int) $r['quality'],
            'item_level' => (int) $r['item_level'],
        ], $rows);
    }
}
