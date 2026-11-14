<?php

declare(strict_types=1);

namespace App\World;

use App\World\Model\Actor;
use App\World\Model\CharacterClass;
use App\World\Model\CharacterRace;
use App\World\Model\Gender;
use App\World\Model\Position;
use App\World\Soap\SoapCommandExecutor;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The live gateway: reads world state from the characters database, writes through SOAP.
 *
 * Position comes from SQL rather than the playerbots command server because the command server
 * only knows bots — the player's own character answers "invalid guid" — and because one query
 * returns the whole world where the TCP protocol needs a round trip per bot. The cost is a
 * `saveall` first, which is cheap and is what `refresh()` is for.
 */
#[AsAlias(WorldGatewayInterface::class)]
final readonly class AzerothCoreGateway implements WorldGatewayInterface
{
    private const SELECT_ACTOR = <<<'SQL'
        SELECT c.guid, c.name, c.race, c.class, c.gender, c.level,
               c.position_x, c.position_y, c.position_z, c.map, c.zone, c.online,
               (a.username LIKE 'RNDBOT%') AS is_bot,
               g.name AS guild_name
        FROM characters c
        JOIN acore_auth.account a ON a.id = c.account
        LEFT JOIN guild_member gm ON gm.guid = c.guid
        LEFT JOIN guild g ON g.guildid = gm.guildid
        SQL;

    public function __construct(
        #[Autowire(service: 'anm.characters_connection')]
        private Connection $characters,
        private SoapCommandExecutor $soap,
    ) {
    }

    public function refresh(): void
    {
        $this->soap->execute('saveall');
    }

    public function findActor(string $name): ?Actor
    {
        $row = $this->characters->fetchAssociative(
            self::SELECT_ACTOR.' WHERE c.name = :name',
            ['name' => $name],
        );

        return $row ? $this->hydrate($row) : null;
    }

    public function findActorByGuid(int $guid): ?Actor
    {
        $row = $this->characters->fetchAssociative(
            self::SELECT_ACTOR.' WHERE c.guid = :guid',
            ['guid' => $guid],
        );

        return $row ? $this->hydrate($row) : null;
    }

    public function onlinePlayers(): array
    {
        $rows = $this->characters->fetchAllAssociative(
            self::SELECT_ACTOR." WHERE c.online = 1 AND a.username NOT LIKE 'RNDBOT%'",
        );

        return array_values(array_filter(array_map($this->hydrate(...), $rows)));
    }

    public function onlineActors(): array
    {
        $actors = [];

        foreach ($this->characters->iterateAssociative(self::SELECT_ACTOR.' WHERE c.online = 1') as $row) {
            if (null !== $actor = $this->hydrate($row)) {
                $actors[$actor->guid] = $actor;
            }
        }

        return $actors;
    }

    public function actorsNear(Position $centre, float $yards): array
    {
        // Squared distance keeps the comparison in the index-friendly arithmetic MySQL is good at
        // and avoids a per-row sqrt; we only need ordering and a threshold, not the value.
        $rows = $this->characters->fetchAllAssociative(
            self::SELECT_ACTOR.<<<'SQL'
                 WHERE c.online = 1 AND c.map = :map
                   AND POW(c.position_x - :x, 2) + POW(c.position_y - :y, 2) <= :radius2
                 ORDER BY POW(c.position_x - :x, 2) + POW(c.position_y - :y, 2)
                SQL,
            [
                'map' => $centre->mapId,
                'x' => $centre->x,
                'y' => $centre->y,
                'radius2' => $yards * $yards,
            ],
        );

        return array_values(array_filter(array_map($this->hydrate(...), $rows)));
    }

    public function actorsInZone(int $zoneId): array
    {
        $rows = $this->characters->fetchAllAssociative(
            self::SELECT_ACTOR.' WHERE c.online = 1 AND c.zone = :zone',
            ['zone' => $zoneId],
        );

        return array_values(array_filter(array_map($this->hydrate(...), $rows)));
    }

    public function execute(string $command): string
    {
        return $this->soap->execute($command);
    }

    /**
     * Returns null for rows we cannot model rather than throwing: a character with a race or
     * class outside the playable set is a server-side oddity, and one bad row must not break a
     * sweep over the whole world.
     */
    private function hydrate(array $row): ?Actor
    {
        $race = CharacterRace::tryFrom((int) $row['race']);
        $class = CharacterClass::tryFrom((int) $row['class']);
        $gender = Gender::tryFrom((int) $row['gender']);

        if (null === $race || null === $class || null === $gender) {
            return null;
        }

        return new Actor(
            guid: (int) $row['guid'],
            name: $row['name'],
            race: $race,
            class: $class,
            gender: $gender,
            level: (int) $row['level'],
            position: new Position(
                (float) $row['position_x'],
                (float) $row['position_y'],
                (float) $row['position_z'],
                (int) $row['map'],
            ),
            zoneId: (int) $row['zone'],
            isBot: (bool) $row['is_bot'],
            isOnline: (bool) $row['online'],
            guildName: $row['guild_name'] ?: null,
        );
    }
}
