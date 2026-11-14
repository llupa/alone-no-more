<?php

declare(strict_types=1);

namespace App\Command;

use App\Ai\AgentText;
use Symfony\AI\Agent\AgentInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Ask the archivist something directly.
 *
 * The archivist is normally reached through a bot in conversation, which makes it awkward to
 * tell a bad answer from a bad question. Here it answers on its own, so its tool use and its
 * reasoning can be judged without a performance wrapped around them.
 */
#[AsCommand(name: 'anm:ask', description: 'Ask the archivist about a quest, an item, a creature, or where to find something.')]
final class AskCommand
{
    public function __construct(
        #[Autowire(service: 'ai.agent.lore')]
        private readonly AgentInterface $archivist,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'What you want to know.')]
        string $question,
    ): int {
        $io->writeln(\sprintf('<comment>%s</comment>', $question));
        $io->newLine();

        $started = microtime(true);

        try {
            $answer = AgentText::of($this->archivist->call($question)->getContent());
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->writeln($answer);
        $io->newLine();
        $io->writeln(\sprintf('<fg=gray>%.1fs</>', microtime(true) - $started));

        return Command::SUCCESS;
    }
}
