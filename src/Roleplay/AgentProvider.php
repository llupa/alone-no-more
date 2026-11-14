<?php

declare(strict_types=1);

namespace App\Roleplay;

use Symfony\AI\Agent\AgentInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;

/**
 * Picks the model that answers, at runtime, from ANM_AI_PROVIDER.
 *
 * The agents arrive as closures rather than instances so the unused platform is never
 * constructed. That matters: the project has to run from a clean clone on Ollama alone, with no
 * Anthropic key present anywhere.
 */
final readonly class AgentProvider
{
    public const OLLAMA = 'ollama';
    public const ANTHROPIC = 'anthropic';

    /**
     * @param \Closure():AgentInterface $ollamaAgent
     * @param \Closure():AgentInterface $anthropicAgent
     * @param \Closure():AgentInterface $ollamaBanter
     * @param \Closure():AgentInterface $anthropicBanter
     */
    public function __construct(
        #[AutowireServiceClosure('ai.agent.roleplay_ollama')]
        private \Closure $ollamaAgent,
        #[AutowireServiceClosure('ai.agent.roleplay_anthropic')]
        private \Closure $anthropicAgent,
        #[AutowireServiceClosure('ai.agent.banter_ollama')]
        private \Closure $ollamaBanter,
        #[AutowireServiceClosure('ai.agent.banter_anthropic')]
        private \Closure $anthropicBanter,
        #[Autowire(env: 'ANM_AI_PROVIDER')]
        private string $provider = self::OLLAMA,
    ) {
    }

    /**
     * The model that mutters. Same switch as everything else, because a clone running on Ollama
     * alone must not hit an Anthropic agent on a background tick it never asked for.
     */
    public function banterAgent(): AgentInterface
    {
        return match ($this->name()) {
            self::OLLAMA => ($this->ollamaBanter)(),
            self::ANTHROPIC => ($this->anthropicBanter)(),
            default => throw new \InvalidArgumentException(\sprintf('Unknown AI provider "%s".', $this->provider)),
        };
    }

    public function agent(): AgentInterface
    {
        return match ($this->name()) {
            self::OLLAMA => ($this->ollamaAgent)(),
            self::ANTHROPIC => ($this->anthropicAgent)(),
            default => throw new \InvalidArgumentException(\sprintf('Unknown AI provider "%s". Set ANM_AI_PROVIDER to "%s" or "%s".', $this->provider, self::OLLAMA, self::ANTHROPIC)),
        };
    }

    public function name(): string
    {
        $name = strtolower(trim($this->provider));

        return '' !== $name ? $name : self::OLLAMA;
    }
}
