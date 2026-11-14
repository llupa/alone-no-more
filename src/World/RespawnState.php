<?php

declare(strict_types=1);

namespace App\World;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Which creatures are dead right now.
 *
 * The world database's `creature` table is a list of spawn *points* — where something stands when
 * it is alive. It says nothing about whether it is alive at this moment, so a bot reading it
 * alone will keep mentioning a rare you killed twenty minutes ago. It did: companions went on
 * warning about Ursal long after the party had put him down, which reads as the world not
 * noticing what you just did.
 *
 * The server records the other half in `creature_respawn`, in the characters database: a row per
 * corpse, with the time it comes back. A guid in there with a future respawn time is a creature
 * that is not currently in the world.
 */
final readonly class RespawnState
{
    public function __construct(
        #[Autowire(service: 'anm.characters_connection')]
        private Connection $characters,
    ) {
    }

    /**
     * Spawn guids on a map whose creature is dead and has not come back yet.
     *
     * Fetched whole rather than joined: the two tables live in different databases, and the list
     * is a few hundred rows server-wide — far cheaper to carry than to make the world query
     * reach across schemas.
     *
     * @return int[]
     */
    public function deadOn(int $mapId): array
    {
        $guids = $this->characters->fetchFirstColumn(
            'SELECT guid FROM creature_respawn WHERE mapId = :map AND respawnTime > UNIX_TIMESTAMP()',
            ['map' => $mapId],
            ['map' => ParameterType::INTEGER],
        );

        return array_map(intval(...), $guids);
    }
}
