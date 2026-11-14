<?php

declare(strict_types=1);

namespace App\Knowledge\Dbc;

use App\World\Model\Faction;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Zone and subzone names, from AreaTable.dbc.
 *
 * This is the difference between a bot saying "I'm in Teldrassil" and "I'm at Shadowglen". The
 * server knows the numbers; only the client data knows the words.
 *
 * AreaTable's 3.3.5a layout (36 fields, verified against the file's own header):
 *   0  ID
 *   1  ContinentID (map)
 *   2  ParentAreaID  — 0 when this row *is* a zone, otherwise the zone it sits in
 *   10 ExplorationLevel
 *   11 AreaName_lang[0] (enUS) — the first of sixteen locale slots
 */
final class AreaNames
{
    private const FIELD_ID = 0;
    private const FIELD_CONTINENT = 1;
    private const FIELD_PARENT = 2;
    private const FIELD_EXPLORATION_LEVEL = 10;
    private const FIELD_NAME_ENUS = 11;
    private const FIELD_FACTION_MASK = 28;

    /** @var array<int, array{name: string, parent: int, map: int, level: int, faction: int}>|null */
    private ?array $areas = null;

    public function __construct(
        #[Autowire('%env(ANM_DBC_DIR)%')]
        private readonly string $dbcDir,
        private readonly ?CacheInterface $cache = null,
        #[Autowire('%kernel.project_dir%/fixtures/areas.json')]
        private readonly ?string $fixture = null,
    ) {
    }

    /**
     * Who holds this zone — or its parent, since a subzone belongs to whoever holds the zone
     * around it.
     */
    public function controlOf(int $areaId): ZoneControl
    {
        $areas = $this->areas();
        $area = $areas[$areaId] ?? null;

        if (null === $area) {
            return ZoneControl::Contested;
        }

        // A subzone carries its own mask, which is often 0 even inside a faction's heartland;
        // the zone above it is the honest answer.
        if (0 === $area['faction'] && 0 !== $area['parent'] && isset($areas[$area['parent']])) {
            $area = $areas[$area['parent']];
        }

        return ZoneControl::tryFrom($area['faction']) ?? ZoneControl::Contested;
    }

    /**
     * The name of a zone or subzone, e.g. 141 => "Teldrassil", 188 => "Shadowglen".
     */
    public function name(int $areaId): ?string
    {
        return $this->areas()[$areaId]['name'] ?? null;
    }

    /**
     * The id of a zone named in words, for turning "Northshire Valley" back into a number.
     *
     * Matched case-insensitively and on a prefix, because a player says "Elwynn" and the records
     * say "Elwynn Forest". The shortest match wins, so "Elwynn" finds the forest rather than
     * some longer name containing it.
     */
    public function idOf(string $name): ?int
    {
        $wanted = mb_strtolower(trim($name));

        if ('' === $wanted) {
            return null;
        }

        $best = null;
        $bestLength = \PHP_INT_MAX;

        foreach ($this->areas() as $id => $area) {
            $candidate = mb_strtolower($area['name']);

            if ($candidate !== $wanted && !str_starts_with($candidate, $wanted)) {
                continue;
            }

            if (mb_strlen($candidate) < $bestLength) {
                $best = $id;
                $bestLength = mb_strlen($candidate);
            }
        }

        return $best;
    }

    /**
     * The zone an area belongs to, as a name. For a row that is itself a zone this returns its
     * own name, so callers can ask without first knowing which kind of row they have.
     */
    public function zoneNameOf(int $areaId): ?string
    {
        $areas = $this->areas();

        if (!isset($areas[$areaId])) {
            return null;
        }

        $parent = $areas[$areaId]['parent'];

        return 0 !== $parent && isset($areas[$parent])
            ? $areas[$parent]['name']
            : $areas[$areaId]['name'];
    }

    /**
     * Where a zone sits and roughly who it is for — useful when staging a crowd.
     *
     * @return array{name: string, parent: int, map: int, level: int, faction: int}|null
     */
    public function describe(int $areaId): ?array
    {
        return $this->areas()[$areaId] ?? null;
    }

    /**
     * @return array<int, array{name: string, parent: int, map: int, level: int, faction: int}>
     */
    /**
     * Zones captured alongside a world snapshot, for running without client data.
     *
     * @return array<int, array{name: string, parent: int, map: int, level: int, faction: int}>
     */
    private function fromFixture(): array
    {
        if (null === $this->fixture || !is_readable($this->fixture)) {
            // Neither client data nor a snapshot. Returning no zones would leave every bot
            // standing in "an unnamed place" with nothing to explain why, so this keeps the
            // original error: it is the one that tells someone what to go and get.
            throw new DbcException(\sprintf('No zone names available: "%s/AreaTable.dbc" is missing (it comes from the client data; see the README) and there is no captured snapshot to fall back on.', rtrim($this->dbcDir, '/')));
        }

        /** @var array<string, array{name: string, parent?: int, map?: int, level?: int, faction?: int}> $rows */
        $rows = json_decode((string) file_get_contents($this->fixture), true, flags: \JSON_THROW_ON_ERROR);

        $areas = [];

        foreach ($rows as $id => $row) {
            $areas[(int) $id] = [
                'name' => (string) $row['name'],
                'parent' => (int) ($row['parent'] ?? 0),
                'map' => (int) ($row['map'] ?? 0),
                'level' => (int) ($row['level'] ?? 0),
                'faction' => (int) ($row['faction'] ?? 0),
            ];
        }

        return $areas;
    }

    private function areas(): array
    {
        if (null !== $this->areas) {
            return $this->areas;
        }

        $load = function (): array {
            // Client data cannot be committed, so a clone without a WoW install falls back to
            // the zones captured in the snapshot. Fewer zones, same shape, and callers already
            // handle an unknown id — they say "an unnamed place" rather than inventing one.
            $path = rtrim($this->dbcDir, '/').'/AreaTable.dbc';

            if (!is_readable($path)) {
                return $this->fromFixture();
            }

            $dbc = new DbcFile($path);
            $areas = [];

            foreach ($dbc->records() as $fields) {
                $id = $fields[self::FIELD_ID];

                $areas[$id] = [
                    'name' => $dbc->string($fields[self::FIELD_NAME_ENUS]),
                    'parent' => $fields[self::FIELD_PARENT],
                    'map' => $fields[self::FIELD_CONTINENT],
                    'level' => $fields[self::FIELD_EXPLORATION_LEVEL],
                    'faction' => $fields[self::FIELD_FACTION_MASK],
                ];
            }

            return $areas;
        };

        // Client data never changes under a running server, so this is cached for the life of
        // the process and, where a cache is wired, between runs.
        //
        // The key carries a version because the cache outlives a deploy: adding the faction
        // field to these rows left the old shape cached, and every lookup then failed on a
        // missing key. Bump it whenever the row shape changes.
        return $this->areas = null !== $this->cache
            ? $this->cache->get('anm.dbc.areas.v2', static fn (): array => $load())
            : $load();
    }
}
