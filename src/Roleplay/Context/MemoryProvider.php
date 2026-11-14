<?php

declare(strict_types=1);

namespace App\Roleplay\Context;

use App\Roleplay\Memory\ConversationBags;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Whether this bot and this player have met.
 *
 * Nothing more. The conversation itself is no longer narrated here — it arrives as real
 * user and assistant turns in the MessageBag, which is both cheaper and the only way a model
 * reliably treats its own past replies as its own. While the transcript lived in this prompt the
 * bot would answer "I do not recall speaking with you before" while holding the exchange, and we
 * had to forbid it from saying so. That instruction is gone with the cause.
 */
#[AutoconfigureTag('anm.roleplay_context')]
final readonly class MemoryProvider implements ContextProviderInterface
{
    public function __construct(private ConversationBags $bags)
    {
    }

    public function contribute(RoleplayContext $context): string
    {
        return $this->bags->hasSpokenWith($context->bot->guid, $context->speaker->guid)
            ? \sprintf('You know %s. What passed between you before follows.', $context->speaker->name)
            : \sprintf('You have not met %s before. Greet them as a stranger would.', $context->speaker->name);
    }

    public function priority(): int
    {
        return 20;
    }
}
