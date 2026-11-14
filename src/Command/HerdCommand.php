<?php

declare(strict_types=1);

namespace App\Command;

use App\Director\PlayerCharacter;
use App\Herding\BotStagerInterface;
use App\World\WorldGatewayInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Populate the world around a character, once.
 *
 * The manual form of what the watcher will do automatically. Useful on its own for setting a
 * scene before recording, and it keeps the staging logic honest by exercising it without a
 * position watcher in the way.
 */
#[AsCommand(name: 'anm:herd', description: 'Bring a believable crowd into the world around a character.')]
final class HerdCommand
{
    public function __construct(
        private readonly PlayerCharacter $player,
        private readonly WorldGatewayInterface $world,
        private readonly BotStagerInterface $stager,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Who to gather a crowd around.')]
        ?string $character = null,
        #[Option(description: 'How many bots should be nearby.')]
        ?int $target = null,
        #[Option(description: 'Also send away bots further than this many yards.')]
        ?float $dismissBeyond = null,
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

        $io->title(\sprintf('Staging around %s — %s', $player->name, $player->describe()));

        if (null !== $dismissBeyond) {
            $sent = $this->stager->dismissDistant($player, $dismissBeyond);
            $io->writeln(\sprintf('Sent away %d bot(s) beyond %dyd.', \count($sent), $dismissBeyond));
        }

        $result = $this->stager->stage($player, $target);

        $io->writeln($result->summary());

        if ([] !== $result->broughtIn) {
            $io->table(
                ['name', 'level'],
                array_map(
                    static fn (array $b): array => [$b['name'], $b['level']],
                    \array_slice($result->broughtIn, 0, 12),
                ),
            );

            if (\count($result->broughtIn) > 12) {
                $io->writeln(\sprintf('<comment>… and %d more</comment>', \count($result->broughtIn) - 12));
            }

            $io->writeln('<comment>Waiting for them to finish logging in…</comment>');

            // A bot logs in idle and stays that way until told otherwise; an order sent before
            // it is in the world is lost, so give the logins a moment to land first.
            sleep(8);
            $this->stager->rouse(array_column($result->broughtIn, 'name'));
            $io->writeln('Sent them about their business.');
        }

        return Command::SUCCESS;
    }
}
