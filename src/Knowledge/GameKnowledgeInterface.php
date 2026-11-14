<?php

declare(strict_types=1);

namespace App\Knowledge;

use App\World\Model\Position;

/**
 * What a character would plausibly know about the world around them.
 *
 * Every method answers from data rather than from a model's recollection, which is the point:
 * asked for work near Astranaar, an ungrounded bot offered a job at "Raptor's Spine", a place
 * that does not exist. A confidently wrong landmark is worse than a vague answer.
 *
 * Behind this interface sits the server's own world database. It is an interface so the project
 * can also run against a captured snapshot, with no MySQL and no game install — which is what
 * lets someone clone the repository and see it work.
 */
interface GameKnowledgeInterface
{
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
    public function creaturesNear(Position $position, float $yards = 200.0, int $limit = 8): array;

    /**
     * Quests a character of this level would plausibly have heard of in this zone.
     *
     * @return array<int, array{id: int, title: string, level: int}>
     */
    public function questsForZone(int $zoneId, int $level, int $tolerance = 6, int $limit = 6): array;

    /**
     * A quest by id — what a player links when asking "do you have this one?".
     *
     * @return array{id: int, title: string, level: int, min_level: int, objective: string, description: string}|null
     */
    public function quest(int $id): ?array;

    /**
     * An item by id — what a player links in chat.
     *
     * @return array{id: int, name: string, quality: int, item_level: int, required_level: int, description: string}|null
     */
    public function item(int $entry): ?array;

    /**
     * Find a quest by the name someone typed.
     *
     * @return array{id: int, title: string, level: int, objective: string}|null
     */
    public function findQuestByName(string $name): ?array;

    /**
     * Find one item by the name someone typed, preferring an exact match.
     *
     * @return array{id: int, name: string, quality: int, item_level: int, required_level: int, description: string}|null
     */
    public function findItemByName(string $name): ?array;

    /**
     * Who drops an item, commonest first.
     *
     * The question behind "where do I get this" nearly always. Chance is the raw drop rate from
     * the loot table, which is the honest number rather than a guess.
     *
     * @return array<int, array{creature: string, level_min: int, level_max: int, chance: float}>
     */
    public function whatDrops(int $itemId, int $limit = 6): array;

    /**
     * Creatures whose name matches, with roughly where they are found.
     *
     * Position is averaged over their spawns, which is a fair answer to "where do I find these"
     * for a pack that shares a hillside and a poor one for something spawned across a continent —
     * the spawn count tells the caller which it is dealing with.
     *
     * The height matters as much as the other two and used to be missing. Walking a companion to
     * an NPC needs a real z: the server resolves ground height by searching fifty yards around
     * the one it is given, so a destination handed the *bot's* height instead of the NPC's found
     * no ground at all whenever the two stood sixty yards apart, and the walk never started.
     *
     * @return array<int, array{name: string, level_min: int, level_max: int, spawns: int, map: int, x: float, y: float, z: float}>
     */
    public function findCreatures(string $name, int $limit = 5): array;

    /**
     * What a quest actually asks you to fetch, resolved to item names.
     *
     * @return array<int, array{item: string, id: int, count: int}>
     */
    public function questRequiredItems(int $questId): array;

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
    public function findQuestsByName(string $name, int $limit = 8): array;

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
    public function objectsHolding(int $itemId, int $limit = 60): array;

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
    public function questsNeedingItem(int $itemId): array;

    /**
     * Items whose name matches what the player typed, for when they ask in words rather than
     * linking. Exact matches first, then prefixes, so "thunderfury" beats "Thunderfury Fragment".
     *
     * @return array<int, array{id: int, name: string, quality: int, item_level: int}>
     */
    public function findItemsByName(string $name, int $limit = 5): array;

    /**
     * Who hands a quest out and who takes it back — rarely the same person.
     *
     * @return array{starts: string[], ends: string[]}
     */
    public function questGivers(int $questId): array;
}
