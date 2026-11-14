<?php

declare(strict_types=1);

namespace App\Roleplay\Banter;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Things a character might dream of killing one day.
 *
 * Curated rather than queried. The obvious approach — everything in `creature_template` with the
 * raid rank — returns 887 rows on this server, most of them the same boss four times over
 * ("Acidmaw", "Acidmaw (1)", "Acidmaw (2)") plus battleground NPCs that nobody has ever vowed
 * revenge on. A short list of names people actually know is better material.
 *
 * It is still checked against the server once, so a core without a given boss simply drops it
 * rather than having its bots swear vengeance on something that does not exist.
 */
final class RaidBosses
{
    private const CANDIDATES = [
        'The Lich King', 'Illidan Stormrage', "Kel'Thuzad", 'Ragnaros', 'Onyxia', 'Nefarian',
        "C'Thun", 'Yogg-Saron', "Kael'thas Sunstrider", 'Lady Vashj', 'Archimonde', 'Malygos',
        'Sartharion', "Anub'arak", 'Professor Putricide', 'Sindragosa', 'Deathbringer Saurfang',
        'Hogger',
    ];

    /** @var string[]|null */
    private ?array $real = null;

    public function __construct(
        #[Autowire(service: 'anm.world_connection')]
        private readonly Connection $world,
    ) {
    }

    public function random(): ?string
    {
        $bosses = $this->available();

        return [] === $bosses ? null : $bosses[array_rand($bosses)];
    }

    /**
     * @return string[]
     */
    private function available(): array
    {
        if (null !== $this->real) {
            return $this->real;
        }

        $found = $this->world->fetchFirstColumn(
            'SELECT DISTINCT name FROM creature_template WHERE name IN (?)',
            [self::CANDIDATES],
            [\Doctrine\DBAL\ArrayParameterType::STRING],
        );

        return $this->real = array_map(strval(...), $found);
    }
}
