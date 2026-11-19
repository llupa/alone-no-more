<?php

declare(strict_types=1);

namespace App\Command;

use App\Director\PlayerCharacter;
use App\Herding\BotPresenceManager;
use App\World\Model\Actor;
use App\World\WorldGatewayInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Show a player's party and, optionally, bring the missing members into the world.
 *
 * The first slice of herding: an invited bot that is not logged in shows as "Unknown" in the
 * party frame and answers nothing. Here you can see that, and fix it.
 */
#[AsCommand(name: 'anm:party', description: "Show a player's party and wake any members who are offline.")]
final class PartyCommand
{
    public function __construct(
        private readonly PlayerCharacter $player,
        private readonly WorldGatewayInterface $world,
        private readonly BotPresenceManager $presence,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Whose party to look at.')]
        ?string $character = null,
        #[Option(description: 'Log in any party members who are offline.')]
        bool $wake = false,
    ): int {
        $this->world->refresh();

        // No name given: whoever is actually playing. Every bot sits on an RNDBOT account, so
        // the player is simply the online character that is not one of those — no configuration,
        // and nothing to go stale.
        $character ??= $this->player->current()?->name;

        if (null === $character) {
            $io->error('Nobody is online. Log a character in, or name one.');

            return Command::FAILURE;
        }

        $player = $this->world->findActor($character);

        if (null === $player) {
            $io->error(\sprintf('No character named "%s".', $character));

            return Command::FAILURE;
        }

        $sleeping = $this->presence->sleepingPartyMembers($player);

        $io->title($player->name.'’s party');
        $this->renderParty($io, $player);

        if ([] === $sleeping) {
            $io->success('Everyone in the party is in the world.');

            return Command::SUCCESS;
        }

        $io->warning(\sprintf(
            '%d party member(s) offline — they show as "Unknown" in game and cannot answer you.',
            \count($sleeping),
        ));

        if (!$wake) {
            $io->writeln('Run again with <info>--wake</info> to bring them in.');

            return Command::SUCCESS;
        }

        $woken = $this->presence->wakeParty($player);

        $io->writeln(\sprintf('Asked %d bot(s) to log in. Logging in is asynchronous; re-run to see the result.', \count($woken)));

        foreach ($woken as $bot) {
            $io->writeln(\sprintf('  <info>%s</info> — %s', $bot->name, $bot->describe()));
        }

        return Command::SUCCESS;
    }

    private function renderParty(SymfonyStyle $io, Actor $player): void
    {
        $rows = [[
            $player->name,
            'you',
            $player->level,
            $player->isOnline ? '<info>in world</info>' : '<comment>offline</comment>',
        ]];

        foreach ($this->presence->partyMembers($player) as $member) {
            $rows[] = [
                $member->name,
                \sprintf('%s %s', $member->race->label(), $member->class->label()),
                $member->level,
                $member->isOnline
                    ? '<info>in world</info>'
                    : '<comment>offline — shows as "Unknown"</comment>',
            ];
        }

        $io->table(['name', 'who', 'lvl', 'state'], $rows);
    }
}
