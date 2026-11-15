<?php

declare(strict_types=1);

namespace App\Knowledge;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * What a character is carrying, read from the save rather than asked of the bot.
 *
 * The mod can already list a bot's bags — `!items` whispers them to the master — but a whisper
 * is an answer to the player, not to us. To act on a named item we need its *entry id*, because
 * the mod's `equip` parses only item links and ignores plain names entirely
 * (`ChatHelper::parseItems` scans for the substring `Hitem:`). So the name the player said is
 * matched here, against what the character actually owns, and the caller builds the link.
 *
 * Two connections because the answer spans both databases: `character_inventory` knows which
 * item *instances* a character holds, and only `item_template` knows what any of them are.
 */
#[AsAlias(BagsInterface::class)]
final readonly class Bags implements BagsInterface
{
    public function __construct(
        #[Autowire(service: 'anm.characters_connection')]
        private Connection $characters,
        #[Autowire(service: 'anm.world_connection')]
        private Connection $world,
    ) {
    }

    /**
     * @return array{entry: int, name: string, quality: int, item_level: int}|null
     */
    public function wearableNamed(int $guid, string $name): ?array
    {
        $name = trim($name);

        if ('' === $name) {
            return null;
        }

        $carried = $this->carried($guid);

        if ([] === $carried) {
            return null;
        }

        $entries = array_keys($carried);

        /**
         * `InventoryType = 0` is INVTYPE_NON_EQUIP: reagents, quest items, the grey junk every
         * bot hoards. Excluding it here means a request to wear a Linen Cloth fails as "you are
         * not carrying that", which is at least true, rather than being sent to a server that
         * would quietly ignore it.
         *
         * Ordered the same way as {@see GameKnowledge::findItemsByName()}, so "Pants" prefers an
         * exact match, then a prefix, then the best of whatever is left.
         */
        $row = $this->world->fetchAssociative(
            <<<'SQL'
                SELECT entry, name, Quality AS quality, ItemLevel AS item_level
                FROM item_template
                WHERE entry IN (:entries)
                  AND InventoryType > 0
                  AND name LIKE :like
                ORDER BY (name = :exact) DESC, (name LIKE :prefix) DESC, ItemLevel DESC
                LIMIT 1
                SQL,
            [
                'entries' => array_map(intval(...), $entries),
                'like' => '%'.$name.'%',
                'exact' => $name,
                'prefix' => $name.'%',
            ],
            ['entries' => ArrayParameterType::INTEGER],
        );

        if (false === $row) {
            return null;
        }

        return [
            'entry' => (int) $row['entry'],
            'name' => (string) $row['name'],
            'quality' => (int) $row['quality'],
            'item_level' => (int) $row['item_level'],
        ];
    }

    /**
     * What they hold, as entry id => how many.
     *
     * `character_inventory` covers worn gear as well as bags, which is deliberate: asked to
     * hand over a sword they happen to be holding, "I am not carrying that" would be a lie.
     *
     * @return array<int, int>
     */
    private function carried(int $guid): array
    {
        $rows = $this->characters->fetchAllAssociative(
            'SELECT ii.itemEntry AS entry, SUM(ii.count) AS n
             FROM character_inventory ci
             JOIN item_instance ii ON ii.guid = ci.item
             WHERE ci.guid = :guid
             GROUP BY ii.itemEntry',
            ['guid' => $guid],
            ['guid' => ParameterType::INTEGER],
        );

        $carried = [];

        foreach ($rows as $row) {
            $carried[(int) $row['entry']] = max(1, (int) $row['n']);
        }

        return $carried;
    }

    /**
     * @return array{entry: int, name: string, quality: int, item_level: int, count: int}|null
     */
    public function anythingNamed(int $guid, string $name): ?array
    {
        $name = trim($name);
        $carried = $this->carried($guid);

        if ('' === $name || [] === $carried) {
            return null;
        }

        $row = $this->world->fetchAssociative(
            <<<'SQL'
                SELECT entry, name, Quality AS quality, ItemLevel AS item_level
                FROM item_template
                WHERE entry IN (:entries)
                  AND name LIKE :like
                ORDER BY (name = :exact) DESC, (name LIKE :prefix) DESC, ItemLevel DESC
                LIMIT 1
                SQL,
            [
                'entries' => array_keys($carried),
                'like' => '%'.$name.'%',
                'exact' => $name,
                'prefix' => $name.'%',
            ],
            ['entries' => ArrayParameterType::INTEGER],
        );

        if (false === $row) {
            return null;
        }

        $entry = (int) $row['entry'];

        return [
            'entry' => $entry,
            'name' => (string) $row['name'],
            'quality' => (int) $row['quality'],
            'item_level' => (int) $row['item_level'],
            'count' => $carried[$entry] ?? 1,
        ];
    }

    /**
     * @return array<int, array{entry: int, name: string, quality: int, count: int}>
     */
    public function noBetterThan(int $guid, int $quality = 0, int $limit = 30): array
    {
        $carried = $this->carried($guid);

        if ([] === $carried) {
            return [];
        }

        $rows = $this->world->fetchAllAssociative(
            <<<'SQL'
                SELECT entry, name, Quality AS quality
                FROM item_template
                WHERE entry IN (:entries)
                  AND Quality <= :quality
                  -- Quest items are grey often enough, and selling one costs an evening.
                  AND Class <> 12
                  AND (Flags & 2048) = 0
                ORDER BY Quality, name
                LIMIT :limit
                SQL,
            [
                'entries' => array_keys($carried),
                'quality' => $quality,
                'limit' => $limit,
            ],
            [
                'entries' => ArrayParameterType::INTEGER,
                'quality' => ParameterType::INTEGER,
                'limit' => ParameterType::INTEGER,
            ],
        );

        return array_map(
            static fn (array $r): array => [
                'entry' => (int) $r['entry'],
                'name' => (string) $r['name'],
                'quality' => (int) $r['quality'],
                'count' => $carried[(int) $r['entry']] ?? 1,
            ],
            $rows,
        );
    }
}
