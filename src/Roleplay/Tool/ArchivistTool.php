<?php

declare(strict_types=1);

namespace App\Roleplay\Tool;

use App\Ai\AgentText;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Asks the archivist, and survives it having thought about the answer.
 *
 * This replaces Symfony AI's own `Toolbox\Tool\Subagent`, which does:
 *
 *     $result = $this->agent->call($message)->getResult();
 *     \assert($result instanceof TextResult);
 *
 * That assertion does not hold with extended thinking on. The result is then an array — a
 * `ThinkingResult` first, the `TextResult` after it — and the assert throws, so every lookup
 * failed and companions said the archives would not answer them. It is the same shape that
 * {@see AgentText} already exists for, one layer further in.
 *
 * Failing softly is deliberate. A companion that cannot reach the records should say so in
 * character, which it does well; an exception out of a tool call loses the whole reply.
 */
#[AsTool(
    name: 'consult_the_archives',
    description: <<<'TXT'
        Ask someone who knows the world's records about a quest, an item, a creature, a
        person, or where any of them can be found. Use it whenever the player asks something
        you were not told in your instructions — especially "where is", "who is", "where do
        I get", "what do I need for", "where do I hand this in". Always ask before answering
        that you do not know: they can give directions and distances from where you are
        standing. Ask in plain words, as you would ask a person.
        TXT,
    method: 'ask',
)]
final readonly class ArchivistTool
{
    public function __construct(
        #[Autowire(service: 'ai.agent.lore')]
        private AgentInterface $archivist,
    ) {
    }

    public function ask(string $message): string
    {
        try {
            $answer = AgentText::of($this->archivist->call($message)->getResult()->getContent());
        } catch (\Throwable $e) {
            return \sprintf('The archives did not answer (%s). Say so plainly.', $e->getMessage());
        }

        return '' !== $answer ? $answer : 'The archives had nothing on that. Say so plainly.';
    }
}
