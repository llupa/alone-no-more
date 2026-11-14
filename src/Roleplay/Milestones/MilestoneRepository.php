<?php

declare(strict_types=1);

namespace App\Roleplay\Milestones;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Dings and achievements, as our server module recorded them.
 *
 * Read the same way as chat: a watermark and everything after it. That is deliberate — both are
 * streams of things that already happened, and a listener that restarts should pick up where it
 * left off rather than congratulate somebody for a level they gained an hour ago.
 */
final readonly class MilestoneRepository
{
    public function __construct(
        #[Autowire(service: 'anm.characters_connection')]
        private Connection $characters,
    ) {
    }

    /**
     * @return Milestone[]
     */
    public function since(int $watermark, int $limit = 10): array
    {
        $rows = $this->characters->fetchAllAssociative(
            'SELECT id, player_guid, player_name, kind, detail, number
             FROM anm_milestone WHERE id > :since ORDER BY id LIMIT :limit',
            ['since' => $watermark, 'limit' => $limit],
            ['since' => ParameterType::INTEGER, 'limit' => ParameterType::INTEGER],
        );

        return array_map(static fn (array $r): Milestone => new Milestone(
            id: (int) $r['id'],
            playerGuid: (int) $r['player_guid'],
            playerName: (string) $r['player_name'],
            kind: (string) $r['kind'],
            detail: (string) $r['detail'],
            number: (int) $r['number'],
        ), $rows);
    }

    /**
     * The newest id, for starting without replaying history.
     */
    public function latestId(): int
    {
        $id = $this->characters->fetchOne('SELECT MAX(id) FROM anm_milestone');

        return false === $id || null === $id ? 0 : (int) $id;
    }
}
