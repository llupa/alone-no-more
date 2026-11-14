<?php

declare(strict_types=1);

namespace App\Roleplay\Errand;

/**
 * What each companion is currently in the middle of.
 *
 * In memory only, on purpose: see {@see Errand}. Nothing here is written down, and the whole book
 * is forgotten when the listener restarts — which is the correct behaviour, not a limitation.
 */
final class ErrandBook
{
    /** @var array<int, Errand> */
    private array $errands = [];

    public function start(Errand $errand): void
    {
        $this->errands[$errand->botGuid] = $errand;
    }

    public function of(int $botGuid): ?Errand
    {
        return $this->errands[$botGuid] ?? null;
    }

    public function finish(int $botGuid): void
    {
        unset($this->errands[$botGuid]);
    }

    /**
     * @return Errand[]
     */
    public function all(): array
    {
        return array_values($this->errands);
    }

    public function isEmpty(): bool
    {
        return [] === $this->errands;
    }
}
