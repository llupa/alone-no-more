<?php

declare(strict_types=1);

namespace App\Roleplay\Context;

use App\World\Model\Actor;
use App\World\Model\ChatEvent;

/**
 * Everything known about one moment: who is answering, who spoke, and what was said.
 * Passed to each context provider in turn to build the system prompt.
 */
final readonly class RoleplayContext
{
    public function __construct(
        public Actor $bot,
        public Actor $speaker,
        public ChatEvent $event,
        public ?string $zoneName = null,
        /**
         * Filled by the providers as they build the prompt, then used to turn the plain names in
         * the reply back into links the player can click.
         */
        public MentionedEntities $mentioned = new MentionedEntities(),
    ) {
    }
}
