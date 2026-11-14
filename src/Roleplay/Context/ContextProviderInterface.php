<?php

declare(strict_types=1);

namespace App\Roleplay\Context;

/**
 * One section of a bot's system prompt.
 *
 * The prompt is assembled from independent providers — identity, place, memory, style — so each
 * can be tested, reordered or dropped on its own. Returning null means "nothing to add here",
 * which keeps the prompt short when there is no history or no landmark worth mentioning.
 */
interface ContextProviderInterface
{
    public function contribute(RoleplayContext $context): ?string;

    /**
     * Lower runs first. Identity must precede everything; style must come last so its rules are
     * the final thing the model reads.
     */
    public function priority(): int;
}
