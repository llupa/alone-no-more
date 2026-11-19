<?php

declare(strict_types=1);

namespace App\Replay;

use App\World\Model\Actor;
use App\World\Model\CharacterClass;
use App\World\Model\CharacterRace;
use App\World\Model\Gender;
use App\World\Model\Position;
use App\World\WorldGatewayInterface;

/**
 * The world as it stood when the snapshot was taken.
 *
 * Read-only by nature: there is no server to change. `execute()` says so rather than pretending
 * an order was carried out, because a demo that silently swallows commands teaches the wrong
 * thing about what the project does.
 */
final class ReplayGateway implements WorldGatewayInterface
{
    /** @var array<int, Actor>|null */
    private ?array $actors = null;

    public function __construct(private readonly Snapshot $snapshot)
    {
    }

    public function refresh(): void
    {
        // Nothing to flush: the world is already as fresh as it will ever be.
    }

    public function recordedAt(): ?\DateTimeImmutable
    {
        $at = $this->snapshot->capturedAt();

        return null === $at ? null : new \DateTimeImmutable($at);
    }

    public function findActor(string $name): ?Actor
    {
        foreach ($this->actors() as $actor) {
            if (0 === strcasecmp($actor->name, $name)) {
                return $actor;
            }
        }

        return null;
    }

    public function findActorByGuid(int $guid): ?Actor
    {
        return $this->actors()[$guid] ?? null;
    }

    /**
     * @return Actor[]
     */
    public function onlinePlayers(): array
    {
        return array_values(array_filter(
            $this->actors(),
            static fn (Actor $a): bool => $a->isOnline && !$a->isBot,
        ));
    }

    /**
     * @return Actor[]
     */
    public function onlineActors(): array
    {
        return array_values(array_filter($this->actors(), static fn (Actor $a): bool => $a->isOnline));
    }

    /**
     * @return Actor[]
     */
    public function actorsNear(Position $centre, float $yards): array
    {
        return array_values(array_filter(
            $this->onlineActors(),
            static fn (Actor $a): bool => $a->position->mapId === $centre->mapId
                && hypot($a->position->x - $centre->x, $a->position->y - $centre->y) <= $yards,
        ));
    }

    /**
     * @return Actor[]
     */
    public function actorsInZone(int $zoneId): array
    {
        return array_values(array_filter(
            $this->onlineActors(),
            static fn (Actor $a): bool => $a->zoneId === $zoneId,
        ));
    }

    public function execute(string $command): string
    {
        return \sprintf(
            'This is a recorded world, so "%s" was not run. Point the app at a live AzerothCore to give orders.',
            $command,
        );
    }

    /**
     * @return array<int, Actor>
     */
    private function actors(): array
    {
        if (null !== $this->actors) {
            return $this->actors;
        }

        $actors = [];

        /** @var array<string, mixed> $row */
        foreach ($this->snapshot->section('actors') as $row) {
            $guid = (int) $row['guid'];

            $actors[$guid] = new Actor(
                guid: $guid,
                name: (string) $row['name'],
                race: CharacterRace::from((int) $row['race']),
                class: CharacterClass::from((int) $row['class']),
                gender: Gender::from((int) $row['gender']),
                level: (int) $row['level'],
                position: new Position(
                    (float) $row['x'],
                    (float) $row['y'],
                    (float) $row['z'],
                    (int) $row['map'],
                ),
                zoneId: (int) $row['zoneId'],
                isBot: (bool) $row['isBot'],
                isOnline: (bool) $row['isOnline'],
                guildName: $row['guildName'] ?? null,
            );
        }

        return $this->actors = $actors;
    }
}
