<?php

declare(strict_types=1);

namespace App\Knowledge;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * What a character is actually working on, and how far along they are.
 *
 * Knowing what a quest *is* turned out not to be the same as knowing what someone is *doing*.
 * Companions could recite that "The Enchanted Glade" wants six Bloodfeather Belts and still had
 * nothing to say to "I have two already" — the archives describe the world, not the player in
 * front of them.
 *
 * Both halves live here because a bot is a character too: the same tables answer "what am I
 * doing" and "what are they doing", which is far more reliable than parsing the playerbots
 * console output for a quest list.
 *
 * The data is as fresh as the last save, and the listener runs `saveall` every loop, so in
 * practice it is seconds old.
 */
#[AsAlias(QuestLogInterface::class)]
final readonly class QuestLog implements QuestLogInterface
{
    /**
     * AzerothCore's QuestStatus enum. Only these two ever appear in an active log: everything
     * else is either absent or moved to `character_queststatus_rewarded`.
     */
    private const STATUS_COMPLETE = 1;

    public function __construct(
        #[Autowire(service: 'anm.characters_connection')]
        private Connection $characters,
        #[Autowire(service: 'anm.world_connection')]
        private Connection $world,
    ) {
    }

    /**
     * Quests in a character's log, with progress against each requirement.
     *
     * @return array<int, array{id: int, title: string, level: int, complete: bool, needs: array<int, array{what: string, have: int, need: int}>}>
     */
    public function activeFor(int $guid, int $limit = 25): array
    {
        $rows = $this->characters->fetchAllAssociative(
            'SELECT quest, status, mobcount1, mobcount2, mobcount3, mobcount4,
                    itemcount1, itemcount2, itemcount3, itemcount4
             FROM character_queststatus WHERE guid = :guid LIMIT :limit',
            ['guid' => $guid, 'limit' => $limit],
            ['guid' => ParameterType::INTEGER, 'limit' => ParameterType::INTEGER],
        );

        $quests = [];

        foreach ($rows as $row) {
            $template = $this->template((int) $row['quest']);

            if (null === $template) {
                continue;
            }

            $quests[] = [
                'id' => (int) $row['quest'],
                'title' => (string) $template['LogTitle'],
                'level' => (int) $template['QuestLevel'],
                'complete' => self::STATUS_COMPLETE === (int) $row['status'],
                'needs' => $this->progress($template, $row),
            ];
        }

        return $quests;
    }

    /**
     * Whether a character has already handed a quest in. Distinct from it being absent from the
     * log, which only means they are not on it — they may never have taken it.
     */
    public function hasCompleted(int $guid, int $questId): bool
    {
        return (bool) $this->characters->fetchOne(
            'SELECT COUNT(*) FROM character_queststatus_rewarded WHERE guid = :guid AND quest = :quest',
            ['guid' => $guid, 'quest' => $questId],
            ['guid' => ParameterType::INTEGER, 'quest' => ParameterType::INTEGER],
        );
    }

    /**
     * Pair each requirement with the counter tracking it.
     *
     * The two sets of slots are positional: `RequiredItemId2` is counted by `itemcount2`, and
     * `RequiredNpcOrGo3` by `mobcount3`. A negative RequiredNpcOrGo is an object to use rather
     * than a creature to kill, which reads the same way to a player.
     *
     * @param array<string, mixed> $template
     * @param array<string, mixed> $row
     *
     * @return array<int, array{what: string, have: int, need: int}>
     */
    private function progress(array $template, array $row): array
    {
        $needs = [];

        for ($i = 1; $i <= 4; ++$i) {
            $itemId = (int) ($template['RequiredItemId'.$i] ?? 0);

            if (0 !== $itemId) {
                $needs[] = [
                    'what' => $this->itemName($itemId) ?? 'something',
                    'have' => (int) ($row['itemcount'.$i] ?? 0),
                    'need' => (int) ($template['RequiredItemCount'.$i] ?? 1),
                ];
            }

            $npcOrGo = (int) ($template['RequiredNpcOrGo'.$i] ?? 0);

            if (0 !== $npcOrGo) {
                $needs[] = [
                    'what' => $this->npcOrObjectName($npcOrGo) ?? 'something',
                    'have' => (int) ($row['mobcount'.$i] ?? 0),
                    'need' => (int) ($template['RequiredNpcOrGoCount'.$i] ?? 1),
                ];
            }
        }

        return $needs;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function template(int $questId): ?array
    {
        $row = $this->world->fetchAssociative(
            'SELECT LogTitle, QuestLevel,
                    RequiredItemId1, RequiredItemCount1, RequiredItemId2, RequiredItemCount2,
                    RequiredItemId3, RequiredItemCount3, RequiredItemId4, RequiredItemCount4,
                    RequiredNpcOrGo1, RequiredNpcOrGoCount1, RequiredNpcOrGo2, RequiredNpcOrGoCount2,
                    RequiredNpcOrGo3, RequiredNpcOrGoCount3, RequiredNpcOrGo4, RequiredNpcOrGoCount4
             FROM quest_template WHERE ID = :id',
            ['id' => $questId],
            ['id' => ParameterType::INTEGER],
        );

        return false === $row ? null : $row;
    }

    private function itemName(int $entry): ?string
    {
        $name = $this->world->fetchOne(
            'SELECT name FROM item_template WHERE entry = :entry',
            ['entry' => $entry],
            ['entry' => ParameterType::INTEGER],
        );

        return false === $name || null === $name ? null : (string) $name;
    }

    private function npcOrObjectName(int $required): ?string
    {
        // Positive is a creature to kill, negative an object to use.
        [$table, $entry] = $required > 0
            ? ['creature_template', $required]
            : ['gameobject_template', -$required];

        $name = $this->world->fetchOne(
            \sprintf('SELECT name FROM %s WHERE entry = :entry', $table),
            ['entry' => $entry],
            ['entry' => ParameterType::INTEGER],
        );

        return false === $name || null === $name ? null : (string) $name;
    }
}
