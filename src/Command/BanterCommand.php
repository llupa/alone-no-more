<?php

declare(strict_types=1);

namespace App\Command;

use App\Roleplay\Banter\BanterDirector;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Make the world mutter, now, without waiting for the interval.
 *
 * The listener does this on its own every few minutes, which is right for play and useless for
 * judging whether the lines are any good — or for filming, where the world has to say something
 * on cue.
 */
#[AsCommand(name: 'anm:banter', description: 'Have a bot near the player mutter something, immediately.')]
final class BanterCommand
{
    public function __construct(private readonly BanterDirector $banter)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'How many lines to draw out.')]
        int $lines = 1,
    ): int {
        $said = 0;

        for ($i = 0; $i < $lines; ++$i) {
            $line = $this->banter->tickNow();

            if (null === $line) {
                continue;
            }

            $io->writeln('<fg=magenta>'.$line.'</>');
            ++$said;
        }

        if (0 === $said) {
            $io->warning('Nobody muttered. Needs a player online with bots within earshot.');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
