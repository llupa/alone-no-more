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
}
