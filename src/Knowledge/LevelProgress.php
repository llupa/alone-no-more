<?php

declare(strict_types=1);

namespace App\Knowledge;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Experience toward the next level, from the server's own two halves.
 *
 * `characters.xp` is how much is banked; `player_xp_for_level` says how much this level costs.
 * Neither is interesting alone, which is probably why nothing read them before.
 */
#[AsAlias(LevelProgressInterface::class)]
final readonly class LevelProgress implements LevelProgressInterface
{
    public function __construct(
        #[Autowire(service: 'anm.characters_connection')]
        private Connection $characters,
        #[Autowire(service: 'anm.world_connection')]
        private Connection $world,
    ) {
    }

    /**
     * @return array{level: int, xp: int, needed: int, remaining: int, percent: int}|null
     */
    public function forCharacter(int $guid): ?array
    {
        $row = $this->characters->fetchAssociative(
            'SELECT level, xp FROM characters WHERE guid = :guid',
            ['guid' => $guid],
            ['guid' => ParameterType::INTEGER],
        );

        if (false === $row) {
            return null;
        }

        $level = (int) $row['level'];
        $needed = (int) $this->world->fetchOne(
            'SELECT Experience FROM player_xp_for_level WHERE Level = :level',
            ['level' => $level],
            ['level' => ParameterType::INTEGER],
        );

        // The table stops at the cap, where there is genuinely nothing left to earn — saying
        // "0 of 0" would be worse than saying nothing.
        if ($needed <= 0) {
            return null;
        }

        $xp = (int) $row['xp'];

        return [
            'level' => $level,
            'xp' => $xp,
            'needed' => $needed,
            'remaining' => max(0, $needed - $xp),
            'percent' => (int) round(100 * $xp / $needed),
        ];
    }
}
