<?php

declare(strict_types=1);

namespace App\Herding;

/**
 * What a staging pass actually did, so the cockpit can show it and a human can judge it.
 */
final readonly class StagingResult
{
    /**
     * @param array<int, array{name: string, level: int}> $broughtIn
     * @param array<int, string>                          $sentAway
     */
    public function __construct(
        public int $alreadyPresent,
        public int $target,
        public array $broughtIn,
        public array $sentAway,
        public ?string $limitedBy = null,
    ) {
    }

    public function changed(): bool
    {
        return [] !== $this->broughtIn || [] !== $this->sentAway;
    }

    public function summary(): string
    {
        if (!$this->changed()) {
            return \sprintf('%d of %d present, nothing to do.', $this->alreadyPresent, $this->target);
        }

        return \sprintf(
            '%d present, brought in %d, sent away %d%s.',
            $this->alreadyPresent,
            \count($this->broughtIn),
            \count($this->sentAway),
            null !== $this->limitedBy ? ' (limited by '.$this->limitedBy.')' : '',
        );
    }
}
