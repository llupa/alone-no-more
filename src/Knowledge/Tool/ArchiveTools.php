<?php

declare(strict_types=1);

namespace App\Knowledge\Tool;

use App\Knowledge\Bearing;
use App\Knowledge\Dbc\AreaNames;
use App\Knowledge\Dbc\ZoneBounds;
use App\Knowledge\GameKnowledgeInterface;
use App\Knowledge\Viewpoint;
use App\World\Model\Position;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

/**
 * The world's own records, as tools for the archivist.
 *
 * These are only ever used by the lore agent, never by the one holding a conversation. The split
 * matters for cost as much as for tidiness: answering "what do I need for this quest" means
 * reading quest rows, item rows and loot tables, which is a lot of tokens of input to produce two
 * sentences of output. That reading belongs on the cheap model.
 *
 * Everything here reads the server's own tables, so the answers are true for *this* server rather
 * than true of some wiki.
 */
#[AsTool(
    name: 'find_quest',
    description: 'Look up a quest by name. Returns its level, what it asks you to do, and what items it needs.',
    method: 'findQuest',
)]
#[AsTool(
    name: 'find_item',
    description: 'Look up an item by name. Returns its quality, item level and required level.',
    method: 'findItem',
)]
#[AsTool(
    name: 'where_to_get',
    description: 'Find where an item comes from: which creatures drop it, or which chests hold it, and roughly where in the world. Use to answer "where do I get this".',
    method: 'whoDrops',
)]
#[AsTool(
    name: 'what_needs',
    description: 'Find which quests want an item. Use when asked what an item is for, or why they are collecting it.',
    method: 'whatNeeds',
)]
#[AsTool(
    name: 'quests_around_here',
    description: <<<'TXT'
        What quests this zone offers at a given level. Use for "what is there to do here", "any
        quests nearby", "what should we pick up". Give the zone name and the level to suit.
        TXT,
    method: 'questsAroundHere',
)]
#[AsTool(
    name: 'where_is',
    description: <<<'TXT'
        Find a named person or creature and say where they are relative to whoever is asking —
        direction and rough distance, not coordinates. Use for "where is Milly", "where do I hand
        this in", "where can I find the kobolds". Works for quest givers, vendors and trainers as
        well as things to fight.
        TXT,
    method: 'whereIs',
)]
#[AsTool(
    name: 'find_creature',
    description: 'Find creatures by name: their level, how many are in the world and roughly where. Use for "where do I find these".',
    method: 'findCreature',
)]
final readonly class ArchiveTools
{
    public function __construct(
        private GameKnowledgeInterface $knowledge,
        private AreaNames $areas,
        private ZoneBounds $zones,
        private Viewpoint $asker,
    ) {
    }

    /**
     * @param string $name the quest's name, or part of it
     */
    public function findQuest(string $name): string
    {
        $quests = $this->knowledge->findQuestsByName($name);

        if ([] === $quests) {
            return \sprintf('There is no quest called "%s" in the records.', $name);
        }

        // Steps of a chain all carry the same title. Reporting only one of them would be a
        // confident answer about a quest the player may not be on; the levels and the items are
        // what tell them apart, so give all of it and let the asker match it to the player.
        $sameName = array_values(array_filter(
            $quests,
            static fn (array $q): bool => 0 === strcasecmp($q['title'], $quests[0]['title']),
        ));

        if (\count($sameName) > 1) {
            $steps = array_map(
                fn (array $q): string => \sprintf('level %d%s', $q['level'], $this->needsClause($q['id'])),
                $sameName,
            );

            return \sprintf(
                'There are %d quests called "%s", one for each step of the chain: %s. Which one they mean depends on their level and on what they are carrying.',
                \count($sameName),
                $sameName[0]['title'],
                implode('; ', $steps),
            );
        }

        $quest = $quests[0];
        $lines = [\sprintf('"%s" is a level %d quest. %s', $quest['title'], $quest['level'], $quest['objective'])];

        foreach ($this->knowledge->questRequiredItems($quest['id']) as $needed) {
            $lines[] = \sprintf('It needs %d x %s.', $needed['count'], $needed['item']);
        }

        $givers = $this->knowledge->questGivers($quest['id']);

        if ([] !== $givers['starts']) {
            $lines[] = \sprintf('%s gives it out.', $givers['starts'][0]);
        }

        // Worth saying separately: the two are rarely the same person.
        if ([] !== $givers['ends'] && ($givers['ends'][0] ?? null) !== ($givers['starts'][0] ?? null)) {
            $lines[] = \sprintf('It is handed in to %s.', $givers['ends'][0]);
        }

        return implode(' ', $lines);
    }

    /**
     * What a step of a chain wants, as a short clause — the only thing that distinguishes two
     * quests sharing a title.
     */
    private function needsClause(int $questId): string
    {
        $needed = array_map(
            static fn (array $n): string => \sprintf('%d x %s', $n['count'], $n['item']),
            $this->knowledge->questRequiredItems($questId),
        );

        return [] === $needed ? '' : ' (wants '.implode(', ', $needed).')';
    }

    public function findItem(string $name): string
    {
        $item = $this->knowledge->findItemByName($name);

        if (null === $item) {
            return \sprintf('There is no item called "%s" in the records.', $name);
        }

        return \sprintf(
            '"%s" is an item of level %d%s.%s',
            $item['name'],
            $item['item_level'],
            $item['required_level'] > 0 ? \sprintf(', usable from level %d', $item['required_level']) : '',
            '' !== $item['description'] ? ' '.$item['description'] : '',
        );
    }

    /**
     * @param string $itemName the item to trace
     */
    public function whoDrops(string $itemName): string
    {
        $item = $this->knowledge->findItemByName($itemName);

        if (null === $item) {
            return \sprintf('There is no item called "%s" in the records.', $itemName);
        }

        $sources = [];

        foreach ($this->knowledge->whatDrops($item['id']) as $drop) {
            $sources[] = \sprintf(
                '%s (level %d%s, about %d%% of the time)',
                $drop['creature'],
                $drop['level_min'],
                $drop['level_max'] > $drop['level_min'] ? '-'.$drop['level_max'] : '',
                (int) round($drop['chance']),
            );
        }

        foreach ($this->containers($item['id']) as $where => $container) {
            $place = '' !== $where ? ' in '.$where : '';

            // A herb or ore node is an object named after the thing you take off it, so the
            // plain phrasing comes out as "Hyacinth Mushroom comes from: Hyacinth Mushroom".
            $sources[] = 0 === strcasecmp($container, $item['name'])
                ? \sprintf('gathering it%s', $place)
                : \sprintf('%s%s', $container, '' !== $place ? ','.$place : '');
        }

        if ([] === $sources) {
            return \sprintf('Nothing in the records carries %s; it must come from elsewhere.', $item['name']);
        }

        return \sprintf('%s comes from: %s.', $item['name'], implode('; ', $sources));
    }

    /**
     * Containers holding the item, folded to one line per container and zone.
     *
     * Several spawns of the same chest in the same zone are one fact to a player, not five.
     *
     * @return array<string, string> zone name (possibly empty) => container name
     */
    private function containers(int $itemId): array
    {
        $seen = [];

        foreach ($this->knowledge->objectsHolding($itemId) as $object) {
            $zone = $this->zoneAt($object['map'], $object['x'], $object['y']) ?? '';

            $seen[$zone.'|'.$object['object']] = $object['object'];
        }

        $byZone = [];

        foreach ($seen as $key => $name) {
            $byZone[explode('|', $key, 2)[0]] = $name;
        }

        return $byZone;
    }

    /**
     * @param string $itemName the item's name, or part of it
     */
    public function whatNeeds(string $itemName): string
    {
        $item = $this->knowledge->findItemByName($itemName);

        if (null === $item) {
            return \sprintf('There is no item called "%s" in the records.', $itemName);
        }

        $quests = $this->knowledge->questsNeedingItem($item['id']);

        if ([] === $quests) {
            return \sprintf('No quest in the records asks for %s.', $item['name']);
        }

        $parts = array_map(
            static fn (array $q): string => \sprintf(
                '"%s" (level %d%s)',
                $q['title'],
                $q['level'],
                $q['starts'] ? ', which the item itself begins' : '',
            ),
            $quests,
        );

        return \sprintf('%s is wanted for: %s.', $item['name'], implode('; ', $parts));
    }

    /**
     * @param string $zone  the zone to look in
     * @param int    $level the level to suit the work to
     */
    public function questsAroundHere(string $zone, int $level): string
    {
        $areaId = $this->areas->idOf($zone);

        if (null === $areaId) {
            return \sprintf('There is no zone called "%s" in the records.', $zone);
        }

        $quests = $this->knowledge->questsForZone($areaId, $level);

        if ([] === $quests) {
            return \sprintf('Nothing in %s is recorded as work for someone around level %d.', $zone, $level);
        }

        return \sprintf('In %s, around level %d: %s.', $zone, $level, implode('; ', array_map(
            static fn (array $q): string => \sprintf('"%s" (level %d)', $q['title'], $q['level']),
            $quests,
        )));
    }

    /**
     * @param string $name who or what to look for
     */
    public function whereIs(string $name): string
    {
        $found = $this->knowledge->findCreatures($name);

        if ([] === $found) {
            return \sprintf('Nothing and nobody called "%s" is recorded anywhere.', $name);
        }

        $lines = [];
        $said = [];

        foreach ($found as $creature) {
            // The same name can appear once per map — Thrall stands in Orgrimmar and again
            // inside an instance. Saying it three times reads as confusion, not thoroughness.
            if (isset($said[$creature['name']])) {
                continue;
            }

            $said[$creature['name']] = true;
            $where = new Position($creature['x'], $creature['y'], 0.0, $creature['map']);
            $zone = $this->zoneAt($creature['map'], $creature['x'], $creature['y']);

            // Orient from whoever is speaking when there is one. The archivist is called as a
            // tool from inside a conversation, so the acting bot — standing with the player — is
            // the right frame of reference. Over MCP there is no bot, and the zone alone is the
            // honest answer.
            $here = $this->asker->position();
            $bearing = null !== $here ? Bearing::from($here, $where) : null;

            $lines[] = match (true) {
                null !== $bearing && null !== $zone => \sprintf('%s is %s, in %s.', $creature['name'], $bearing->describe(), $zone),
                null !== $bearing => \sprintf('%s is %s.', $creature['name'], $bearing->describe()),
                null !== $zone => \sprintf('%s is in %s, a long way from here.', $creature['name'], $zone),
                default => \sprintf('%s is recorded, but nowhere we can point to.', $creature['name']),
            };
        }

        return implode(' ', \array_slice($lines, 0, 3));
    }

    public function findCreature(string $name): string
    {
        $found = $this->knowledge->findCreatures($name);

        if ([] === $found) {
            return \sprintf('Nothing called "%s" is recorded anywhere.', $name);
        }

        $parts = [];

        foreach ($found as $creature) {
            // Spawn coordinates mean nothing to a person; the zone name does.
            $zone = $this->zoneAt($creature['map'], $creature['x'], $creature['y']);

            $parts[] = \sprintf(
                '%s (level %d%s, %d of them%s)',
                $creature['name'],
                $creature['level_min'],
                $creature['level_max'] > $creature['level_min'] ? '-'.$creature['level_max'] : '',
                $creature['spawns'],
                null !== $zone ? ' around '.$zone : '',
            );
        }

        return implode('; ', $parts).'.';
    }

    /**
     * Averaged spawn coordinates do not carry a zone id, and resolving one properly needs the
     * server's own map lookup. Naming the continent is honest and still useful; claiming a
     * precise zone we have not actually resolved would not be.
     */
    private function zoneAt(int $map, float $x, float $y): ?string
    {
        // A zone name is what a player can act on; the continent is the honest fallback when
        // nothing on the world map covers the point, which happens inside instances.
        $areaId = $this->zones->areaAt($map, $x, $y);

        return (null !== $areaId ? $this->areas->name($areaId) : null) ?? $this->continent($map);
    }

    private function continent(int $map): ?string
    {
        return match ($map) {
            0 => 'the Eastern Kingdoms',
            1 => 'Kalimdor',
            530 => 'Outland',
            571 => 'Northrend',
            default => null,
        };
    }
}
