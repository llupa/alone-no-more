<?php

declare(strict_types=1);

namespace App\Command;

use App\Director\PlayerCharacter;
use App\Knowledge\Dbc\AreaNames;
use App\Knowledge\GameKnowledgeInterface;
use App\Knowledge\QuestLogInterface;
use App\World\Chat\ChatEventRepositoryInterface;
use App\World\GroupRepositoryInterface;
use App\World\Model\Actor;
use App\World\Model\ChatEvent;
use App\World\WorldGatewayInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Capture a slice of the live world so it can be replayed without a server.
 *
 * Run against a running AzerothCore; the result is committed. Everything the replay services
 * answer with comes from here, which is the point — inventing fixture content would be exactly
 * the confident fiction this project exists to avoid, and a juror comparing the demo against a
 * wiki would catch it immediately.
 *
 * The slice is centred on one character, because that is what the demo is about: a zone's worth
 * of world, not the server's 46,000 items.
 */
#[AsCommand(name: 'anm:snapshot', description: 'Capture a slice of the live world into fixtures/ for replay.')]
final class SnapshotCommand
{
    public function __construct(
        private readonly PlayerCharacter $player,
        private readonly WorldGatewayInterface $world,
        private readonly GameKnowledgeInterface $knowledge,
        private readonly QuestLogInterface $questLog,
        private readonly GroupRepositoryInterface $groups,
        private readonly ChatEventRepositoryInterface $chat,
        private readonly AreaNames $areas,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Character the snapshot is centred on.')]
        ?string $character = null,
        #[Option(description: 'How far around them to capture, in yards.')]
        float $radius = 300.0,
        #[Option(description: 'How many recent chat lines to include.')]
        int $chatLines = 60,
    ): int {
        $this->world->refresh();

        // No name given: whoever is actually playing. Every bot sits on an RNDBOT account, so
        // the player is simply the online character that is not one of those — no configuration,
        // and nothing to go stale.
        $character ??= $this->player->current()?->name;

        if (null === $character) {
            $io->error('Nobody is online. Log a character in, or name one.');

            return Command::FAILURE;
        }

        $centre = $this->world->findActor($character);

        if (null === $centre) {
            $io->error(\sprintf('No character named "%s". Run this against a live server.', $character));

            return Command::FAILURE;
        }

        $actors = $this->actorsToCapture($centre, $radius);
        $creatures = $this->knowledge->creaturesNear($centre->position, $radius, limit: 40);
        $quests = $this->questsToCapture($centre, $actors);
        $items = [];
        $drops = [];
        $containers = [];

        foreach ($quests as $quest) {
            foreach ($quest['needs'] as $need) {
                $item = $this->knowledge->item($need['id']);

                if (null === $item) {
                    continue;
                }

                $items[$item['id']] = $item;

                foreach ($this->knowledge->whatDrops($item['id']) as $drop) {
                    $drops[] = ['itemId' => $item['id']] + $drop;
                }

                foreach ($this->knowledge->objectsHolding($item['id']) as $held) {
                    $containers[] = ['itemId' => $item['id']] + $held;
                }
            }
        }

        $world = [
            'capturedAt' => (new \DateTimeImmutable())->format(\DATE_ATOM),
            'centredOn' => $centre->name,
            // array_values: the working set is keyed by guid, and array_map would keep those
            // keys, serialising a list as a JSON object.
            'actors' => array_values(array_map(self::actorRow(...), $actors)),
            'groups' => $this->groupsOf($actors),
            'questLogs' => $this->questLogsOf($actors),
            'chat' => $this->chatRows($chatLines),
            'creatures' => $this->creatureRows($creatures, $centre),
            'quests' => array_values($quests),
            'items' => array_values($items),
            'drops' => $drops,
            'containers' => $containers,
        ];

        $this->write('world.json', $world);
        $this->write('areas.json', $this->areasOf($actors));

        $io->success(\sprintf(
            'Captured %d characters, %d creature spawns, %d quests, %d items and %d chat lines around %s.',
            \count($world['actors']),
            \count($world['creatures']),
            \count($world['quests']),
            \count($world['items']),
            \count($world['chat']),
            $centre->name,
        ));
        $io->writeln('Replay it with <info>APP_ENV=replay</info> — no server, no database, no client needed.');

        return Command::SUCCESS;
    }

    /**
     * @return Actor[]
     */
    private function actorsToCapture(Actor $centre, float $radius): array
    {
        $actors = [$centre->guid => $centre];

        foreach ($this->world->actorsNear($centre->position, $radius) as $actor) {
            $actors[$actor->guid] = $actor;
        }

        // Party members may have run well past the radius, and a demo without them would show
        // an empty party on the character the snapshot is centred on.
        foreach ($this->groups->memberGuidsWith($centre->guid) as $guid) {
            $member = $this->world->findActorByGuid($guid);

            if (null !== $member) {
                $actors[$guid] = $member;
            }
        }

        return $actors;
    }

    /**
     * @param Actor[] $actors
     *
     * @return array<int, array{id: int, title: string, level: int, min_level: int, objective: string, description: string, zoneId: int, needs: array<int, array{item: string, id: int, count: int}>}>
     */
    private function questsToCapture(Actor $centre, array $actors): array
    {
        $ids = [];

        foreach ($actors as $actor) {
            foreach ($this->questLog->activeFor($actor->guid) as $entry) {
                $ids[$entry['id']] = true;
            }
        }

        foreach ($this->knowledge->questsForZone($centre->zoneId, $centre->level, limit: 12) as $entry) {
            $ids[$entry['id']] = true;
        }

        $quests = [];

        foreach (array_keys($ids) as $id) {
            $quest = $this->knowledge->quest($id);

            if (null === $quest) {
                continue;
            }

            $quests[$id] = $quest + [
                'zoneId' => $centre->zoneId,
                'needs' => $this->knowledge->questRequiredItems($id),
            ];
        }

        return $quests;
    }

    /**
     * @param Actor[] $actors
     *
     * @return array<string, int[]>
     */
    private function groupsOf(array $actors): array
    {
        $groups = [];

        foreach ($actors as $actor) {
            $members = $this->groups->memberGuidsWith($actor->guid);

            if ([] === $members) {
                continue;
            }

            // Keyed by the leader so the same party is not written once per member.
            $leader = $this->groups->leaderGuid($actor->guid) ?? $members[0];
            $groups[(string) $leader] = $members;
        }

        return $groups;
    }

    /**
     * @param Actor[] $actors
     *
     * @return array<string, array<int, array{id: int, title: string, level: int, complete: bool, needs: array<int, array{what: string, have: int, need: int}>}>>
     */
    private function questLogsOf(array $actors): array
    {
        $logs = [];

        foreach ($actors as $actor) {
            $log = $this->questLog->activeFor($actor->guid);

            if ([] !== $log) {
                $logs[(string) $actor->guid] = $log;
            }
        }

        return $logs;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function chatRows(int $limit): array
    {
        $latest = $this->chat->latestId();

        return array_map(static fn (ChatEvent $e): array => [
            'id' => $e->id,
            'at' => $e->createdAt->format(\DATE_ATOM),
            'senderGuid' => $e->senderGuid,
            'senderName' => $e->senderName,
            'channel' => $e->channel->value,
            'message' => $e->message,
            'receiverGuid' => $e->receiverGuid,
            'channelName' => $e->channelName,
            'x' => $e->position->x,
            'y' => $e->position->y,
            'z' => $e->position->z,
            'map' => $e->position->mapId,
            'senderIsBot' => $e->senderIsBot,
        ], $this->chat->since(max(0, $latest - $limit), $limit));
    }

    /**
     * Creature spawns need coordinates, which `creaturesNear` reports only as a distance. The
     * replay places each group back at that distance from the centre, which is close enough for
     * a demo and keeps the fixture honest about where things were.
     *
     * @param array<int, array{name: string, level_min: int, level_max: int, spawns: int, nearest_yards: int}> $creatures
     *
     * @return array<int, array<string, mixed>>
     */
    private function creatureRows(array $creatures, Actor $centre): array
    {
        $rows = [];

        foreach ($creatures as $i => $creature) {
            $angle = 2 * \M_PI * $i / max(1, \count($creatures));

            for ($spawn = 0; $spawn < $creature['spawns']; ++$spawn) {
                $rows[] = [
                    'name' => $creature['name'],
                    'level_min' => $creature['level_min'],
                    'level_max' => $creature['level_max'],
                    'map' => $centre->position->mapId,
                    'x' => $centre->position->x + cos($angle) * $creature['nearest_yards'],
                    'y' => $centre->position->y + sin($angle) * $creature['nearest_yards'],
                ];
            }
        }

        return $rows;
    }

    /**
     * @param Actor[] $actors
     *
     * @return array<string, array{name: string, parent: int, map: int, level: int, faction: int}>
     */
    private function areasOf(array $actors): array
    {
        $areas = [];

        foreach ($actors as $actor) {
            $described = $this->areas->describe($actor->zoneId);

            if (null !== $described) {
                $areas[(string) $actor->zoneId] = $described;
            }
        }

        return $areas;
    }

    /**
     * @return array<string, mixed>
     */
    private static function actorRow(Actor $actor): array
    {
        return [
            'guid' => $actor->guid,
            'name' => $actor->name,
            'race' => $actor->race->value,
            'class' => $actor->class->value,
            'gender' => $actor->gender->value,
            'level' => $actor->level,
            'x' => $actor->position->x,
            'y' => $actor->position->y,
            'z' => $actor->position->z,
            'map' => $actor->position->mapId,
            'zoneId' => $actor->zoneId,
            'isBot' => $actor->isBot,
            'isOnline' => $actor->isOnline,
            'guildName' => $actor->guildName,
        ];
    }

    private function write(string $name, mixed $data): void
    {
        $dir = $this->projectDir.'/fixtures';

        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new \RuntimeException(\sprintf('Could not create "%s".', $dir));
        }

        file_put_contents(
            $dir.'/'.$name,
            json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)."\n",
        );
    }
}
