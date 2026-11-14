<?php

declare(strict_types=1);

namespace App\Roleplay;

use App\World\Model\Actor;

/**
 * A bot's answer, with the prompt that produced it — the prompt is kept so the TUI can show
 * *why* a bot said what it said, which is the difference between a demo and a magic trick.
 */
final readonly class Reply
{
    public function __construct(
        public Actor $bot,
        public string $text,
        public int $outboxId,
        public string $prompt,
    ) {
    }
}
