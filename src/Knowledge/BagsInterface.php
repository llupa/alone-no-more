<?php

declare(strict_types=1);

namespace App\Knowledge;

/**
 * What a character is carrying.
 */
interface BagsInterface
{
    /**
     * The equippable thing in this character's bags whose name best matches what was asked for.
     *
     * Returns null when they are not carrying it, which is a different answer from "no such
     * item exists" and is the one a companion should say out loud.
     *
     * @return array{entry: int, name: string, quality: int, item_level: int}|null
     */
    public function wearableNamed(int $guid, string $name): ?array;

    /**
     * Anything in their bags whose name matches — a sword a priest cannot use, a grey trinket,
     * a stack of linen. {@see wearableNamed()} deliberately ignores all of that, because you
     * cannot put on a Linen Cloth; handing one over is a different question.
     *
     * @return array{entry: int, name: string, quality: int, item_level: int, count: int}|null
     */
    public function anythingNamed(int $guid, string $name): ?array;

    /**
     * What they are carrying that is worth no more than the given quality — 0 is poor, the
     * grey junk that fills bags after an afternoon.
     *
     * @return array<int, array{entry: int, name: string, quality: int, count: int}>
     */
    public function noBetterThan(int $guid, int $quality = 0, int $limit = 30): array;
}
