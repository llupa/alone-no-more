<?php

declare(strict_types=1);

namespace App\Knowledge;

use App\World\Model\Position;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Who will buy things, and how far off they are standing.
 *
 * The mod's `sell` only works with a merchant already in reach: it looks through the bot's
 * nearest NPCs for one it can interact with and otherwise does nothing at all. From outside,
 * "nothing at all" is indistinguishable from success, so a companion asked to sell used to
 * answer confidently and leave the bags full.
 *
 * Asking first is what lets it say something true instead — and name the merchant, which is
 * more use to a player than a refusal.
 */
#[AsAlias(MerchantsInterface::class)]
final readonly class Merchants implements MerchantsInterface
{
    /** UNIT_NPC_FLAG_VENDOR. Not the specialised food/poison/reagent flags: this is the generic one. */
    private const VENDOR = 0x80;

    /**
     * How close counts as "at hand". The mod uses the bot's own nearest-NPC list, which is
     * built from its interaction range; a few paces of slack here avoids telling a player there
     * is no merchant while their companion is standing at the counter.
     */
    private const AT_HAND_YARDS = 12.0;

    public function __construct(
        #[Autowire(service: 'anm.world_connection')]
        private Connection $world,
    ) {
    }

    /**
     * @return array{name: string, yards: float}|null the closest merchant on this map, if any
     *                                                is near enough to be worth mentioning
     */
    public function nearest(Position $position, float $withinYards = 60.0): ?array
    {
        $row = $this->world->fetchAssociative(
            <<<'SQL'
                SELECT ct.name,
                       SQRT(POW(c.position_x - :x, 2) + POW(c.position_y - :y, 2)) AS yards
                FROM creature c
                JOIN creature_template ct ON ct.entry = c.id
                WHERE c.map = :map
                  AND (ct.npcflag & :vendor) = :vendor
                  -- Test and debug spawns are vendors too. "[DND] TAR Pedestal - General
                  -- Goods" is a real row with a real vendor flag, and a companion naming one
                  -- as the nearest merchant would be worse than saying nothing.
                  AND ct.name NOT LIKE '[%'
                  AND (ct.flags_extra & 0x80) = 0
                  AND POW(c.position_x - :x, 2) + POW(c.position_y - :y, 2) <= :within2
                ORDER BY yards
                LIMIT 1
                SQL,
            [
                'map' => $position->mapId,
                'x' => $position->x,
                'y' => $position->y,
                'vendor' => self::VENDOR,
                'within2' => $withinYards * $withinYards,
            ],
            ['map' => ParameterType::INTEGER, 'vendor' => ParameterType::INTEGER],
        );

        if (false === $row) {
            return null;
        }

        return ['name' => (string) $row['name'], 'yards' => (float) $row['yards']];
    }

    public function isOneAtHand(Position $position): bool
    {
        return null !== $this->nearest($position, self::AT_HAND_YARDS);
    }
}
