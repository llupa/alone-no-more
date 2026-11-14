<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Knowledge\Dbc\AreaNames;
use App\Knowledge\GameKnowledgeInterface;
use App\Knowledge\QuestLogInterface;
use App\World\GroupRepositoryInterface;
use App\World\Model\Actor;
use App\World\WorldGatewayInterface;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;

/**
 * Looking at the living world from outside the game.
 *
 * Everything here reads the running server: where a character is standing this second, who is
 * near them, what they are carrying progress on. That is the part an assistant cannot get from a
 * wiki or from its training data, and the reason this is worth exposing over MCP at all.
 *
 * Every call begins with `refresh()`, which is a `saveall` on the server. AzerothCore otherwise
 * persists a player's position every fifteen minutes, so without it an MCP client would be
 * confidently answering questions about where somebody was a quarter of an hour ago.
 */
final readonly class WorldInspector
{
    public function __construct(
        private WorldGatewayInterface $world,
        private GameKnowledgeInterface $knowledge,
        private QuestLogInterface $questLog,
        private GroupRepositoryInterface $groups,
        private AreaNames $areas,
    ) {
    }

    #[McpTool(
        name: 'world_player_state',
        description: 'Where a character is right now, who they are, who is in their party, and what they are working on. Reads the live server.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    public function playerState(string $characterName): string
    {
        $this->world->refresh();
        $actor = $this->world->findActor($characterName);

        if (null === $actor) {
            return \sprintf('No character called "%s" exists on this server.', $characterName);
        }

        $lines = [
            \sprintf(
                '%s is a level %d %s %s, %s.',
                $actor->name,
                $actor->level,
                $actor->race->label(),
                $actor->class->label(),
                $actor->isOnline ? 'currently online' : 'offline',
            ),
            \sprintf('They are in %s, at %s.', $this->zoneOf($actor), $this->coordinates($actor)),
        ];

        $party = $this->partyOf($actor);

        if ([] !== $party) {
            $lines[] = \sprintf('Their party: %s.', implode(', ', $party));
        }

        $quests = $this->questLog->activeFor($actor->guid);

        if ([] !== $quests) {
            $lines[] = 'Quest log: '.implode('; ', array_map(
                static fn (array $q): string => \sprintf(
                    '"%s" (level %d)%s',
                    $q['title'],
                    $q['level'],
                    $q['complete'] ? ', complete' : self::outstanding($q['needs']),
                ),
                $quests,
            )).'.';
        }

        return implode("\n", $lines);
    }

    #[McpTool(
        name: 'world_nearby_actors',
        description: 'Who and what is around a character: other players, bots, and the creatures actually alive nearby. Reads the live server.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    public function nearbyActors(string $characterName, int $yards = 100): string
    {
        $this->world->refresh();
        $actor = $this->world->findActor($characterName);

        if (null === $actor) {
            return \sprintf('No character called "%s" exists on this server.', $characterName);
        }

        $others = array_values(array_filter(
            $this->world->actorsNear($actor->position, (float) $yards),
            static fn (Actor $near): bool => $near->guid !== $actor->guid,
        ));

        $lines = [];

        $lines[] = [] === $others
            ? \sprintf('Nobody else is within %d yards of %s.', $yards, $actor->name)
            : \sprintf('Within %d yards: %s.', $yards, implode(', ', array_map(
                static fn (Actor $n): string => \sprintf(
                    '%s (level %d %s %s%s)',
                    $n->name,
                    $n->level,
                    $n->race->label(),
                    $n->class->label(),
                    $n->isBot ? ', bot' : '',
                ),
                $others,
            )));

        // Creatures the party has already killed are excluded, so this is what is standing
        // there now rather than what spawns there.
        $creatures = $this->knowledge->creaturesNear($actor->position, (float) $yards);

        if ([] !== $creatures) {
            $lines[] = 'Creatures alive nearby: '.implode(', ', array_map(
                static fn (array $c): string => \sprintf(
                    '%s (level %d%s, %d of them, nearest %d yards)',
                    $c['name'],
                    $c['level_min'],
                    $c['level_max'] > $c['level_min'] ? '-'.$c['level_max'] : '',
                    $c['spawns'],
                    $c['nearest_yards'],
                ),
                $creatures,
            )).'.';
        }

        return implode("\n", $lines);
    }

    #[McpTool(
        name: 'world_zone_population',
        description: 'How many characters are online in each zone, and how many of them are bots. Shows where the world is populated and where it is empty.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    public function zonePopulation(): string
    {
        $this->world->refresh();

        $byZone = [];

        foreach ($this->world->onlineActors() as $actor) {
            $zone = $this->areas->name($actor->zoneId) ?? 'zone '.$actor->zoneId;
            $byZone[$zone] ??= ['players' => 0, 'bots' => 0];
            ++$byZone[$zone][$actor->isBot ? 'bots' : 'players'];
        }

        if ([] === $byZone) {
            return 'Nobody is online.';
        }

        uasort($byZone, static fn (array $a, array $b): int => ($b['bots'] + $b['players']) <=> ($a['bots'] + $a['players']));

        $lines = [];

        foreach ($byZone as $zone => $counts) {
            $lines[] = \sprintf(
                '%s: %d online (%d %s, %d %s)',
                $zone,
                $counts['players'] + $counts['bots'],
                $counts['players'],
                1 === $counts['players'] ? 'player' : 'players',
                $counts['bots'],
                1 === $counts['bots'] ? 'bot' : 'bots',
            );
        }

        return implode("\n", $lines);
    }

    #[McpTool(
        name: 'what_to_do_next',
        description: <<<'TXT'
            What a character could reasonably get on with: quests they have finished and can hand
            in, quests still needing something, and what else their zone offers at their level.
            Use for "what should I do next" or "how much have I got left".
            TXT,
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    public function whatToDoNext(string $characterName): string
    {
        $this->world->refresh();
        $actor = $this->world->findActor($characterName);

        if (null === $actor) {
            return \sprintf('No character called "%s" exists on this server.', $characterName);
        }

        $log = $this->questLog->activeFor($actor->guid);
        $done = array_values(array_filter($log, static fn (array $q): bool => $q['complete']));
        $open = array_values(array_filter($log, static fn (array $q): bool => !$q['complete']));

        $lines = [\sprintf(
            '%s is level %d in %s, carrying %d %s.',
            $actor->name,
            $actor->level,
            $this->zoneOf($actor),
            \count($log),
            1 === \count($log) ? 'quest' : 'quests',
        )];

        if ([] !== $done) {
            $lines[] = \sprintf('Ready to hand in: %s.', implode(', ', array_map(
                static fn (array $q): string => \sprintf('"%s"', $q['title']),
                $done,
            )));
        }

        foreach ($open as $quest) {
            $outstanding = self::outstanding($quest['needs']);

            $lines[] = '' === $outstanding
                ? \sprintf('Still on "%s" (level %d).', $quest['title'], $quest['level'])
                : \sprintf('"%s" (level %d)%s.', $quest['title'], $quest['level'], $outstanding);
        }

        // What the zone still has to offer, so "nothing in my log" is not a dead end.
        $available = $this->knowledge->questsForZone($actor->zoneId, $actor->level);
        $takenIds = array_column($log, 'id');
        // Also by title: a chain reuses one name for every step, so offering "Crown of the
        // Earth" to someone already holding a Crown of the Earth reads as a mistake even when
        // it is a different quest.
        $takenTitles = array_map(mb_strtolower(...), array_column($log, 'title'));
        $untaken = array_values(array_filter(
            $available,
            static fn (array $q): bool => !\in_array($q['id'], $takenIds, true)
                && !\in_array(mb_strtolower($q['title']), $takenTitles, true),
        ));

        if ([] !== $untaken) {
            $lines[] = \sprintf('Also around here at their level: %s.', implode(', ', array_map(
                static fn (array $q): string => \sprintf('"%s" (level %d)', $q['title'], $q['level']),
                \array_slice($untaken, 0, 5),
            )));
        }

        if ([] === $log && [] === $untaken) {
            $lines[] = 'Nothing in their log and nothing recorded nearby at their level — time to move on to another zone.';
        }

        return implode("\n", $lines);
    }

    /**
     * @return string[]
     */
    private function partyOf(Actor $actor): array
    {
        $names = [];

        foreach ($this->groups->memberGuidsWith($actor->guid) as $guid) {
            if ($guid === $actor->guid) {
                continue;
            }

            $member = $this->world->findActorByGuid($guid);

            if (null !== $member) {
                $names[] = \sprintf('%s (level %d %s)', $member->name, $member->level, $member->class->label());
            }
        }

        return $names;
    }

    private function zoneOf(Actor $actor): string
    {
        return $this->areas->name($actor->zoneId) ?? 'an unnamed place';
    }

    private function coordinates(Actor $actor): string
    {
        return \sprintf('(%.0f, %.0f) on map %d', $actor->position->x, $actor->position->y, $actor->position->mapId);
    }

    /**
     * @param array<int, array{what: string, have: int, need: int}> $needs
     */
    private static function outstanding(array $needs): string
    {
        $left = [];

        foreach ($needs as $need) {
            if ($need['have'] < $need['need']) {
                $left[] = \sprintf('%s %d/%d', $need['what'], $need['have'], $need['need']);
            }
        }

        return [] === $left ? '' : ', needs '.implode(' and ', $left);
    }
}
