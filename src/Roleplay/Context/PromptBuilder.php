<?php

declare(strict_types=1);

namespace App\Roleplay\Context;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Assembles a bot's system prompt from whatever context providers are registered.
 *
 * Providers are discovered by tag, so adding a new kind of context — the weather, a faction
 * grudge, what the bot is carrying — is a new class and nothing else.
 */
final readonly class PromptBuilder
{
    /**
     * @param iterable<ContextProviderInterface> $providers
     */
    public function __construct(
        #[AutowireIterator('anm.roleplay_context')]
        private iterable $providers,
    ) {
    }

    public function build(RoleplayContext $context): string
    {
        $providers = iterator_to_array($this->providers, false);

        usort(
            $providers,
            static fn (ContextProviderInterface $a, ContextProviderInterface $b): int => $a->priority() <=> $b->priority(),
        );

        $sections = [];

        foreach ($providers as $provider) {
            $section = $provider->contribute($context);

            if (null !== $section && '' !== trim($section)) {
                $sections[] = trim($section);
            }
        }

        return implode("\n\n", $sections);
    }
}
