<?php

declare(strict_types=1);

namespace App\World;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Party and raid membership, read from the server's own group tables.
 *
 * Membership is the whole reason party chat is a better proving ground than proximity: there is
 * no guessing about who could have heard, and the candidate set is at most four other characters.
 */
#[AsAlias(GroupRepositoryInterface::class)]
final readonly class GroupRepository implements GroupRepositoryInterface
{
    public function __construct(
        #[Autowire(service: 'anm.characters_connection')]
        private Connection $characters,
    ) {
    }

    /**
     * Guids of everyone grouped with this character, excluding the character itself.
     *
     * @return int[]
     */
    public function memberGuidsWith(int $guid): array
    {
        $rows = $this->characters->fetchFirstColumn(
            <<<'SQL'
                SELECT other.memberGuid
                FROM group_member mine
                JOIN group_member other ON other.guid = mine.guid
                WHERE mine.memberGuid = :guid AND other.memberGuid <> :guid
                SQL,
            ['guid' => $guid],
        );

        return array_map(intval(...), $rows);
    }

    public function isGrouped(int $guid): bool
    {
        return [] !== $this->memberGuidsWith($guid);
    }

    public function leaderGuid(int $guid): ?int
    {
        $leader = $this->characters->fetchOne(
            'SELECT g.leaderGuid FROM group_member gm
             JOIN `groups` g ON g.guid = gm.guid
             WHERE gm.memberGuid = :guid',
            ['guid' => $guid],
        );

        return false === $leader ? null : (int) $leader;
    }
}
