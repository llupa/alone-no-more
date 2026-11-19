<?php

declare(strict_types=1);

namespace App\Command;

use App\Director\PlayerCharacter;
use App\Knowledge\Dbc\AreaNames;
use App\World\Model\Actor;
use App\World\WorldGatewayInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Who is around a character right now — the raw material the director and the roleplay agent
 * both work from, exposed as a command so it can be eyeballed without an LLM in the loop.
 */
#[AsCommand(name: 'anm:world', description: 'Show who is near a character, and how the zone is populated.')]
final class WorldCommand
{
    public function __construct(
        private readonly PlayerCharacter $player,
        private readonly WorldGatewayInterface $world,
        private readonly AreaNames $areas,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Character to look around from.')]
        ?string $character = null,
        #[Option(description: 'Radius in yards.')]
        float $radius = 150.0,
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

        $actor = $this->world->findActor($character);

        if (null === $actor) {
            $io->error(\sprintf('No character named "%s".', $character));

            return Command::FAILURE;
        }

        $io->title($actor->name.' — '.$actor->describe());

        // The name, not the number: this is the first thing a stranger following the README
        // sees, and "zone 141" tells them nothing.
        $zoneName = $this->areas->name($actor->zoneId) ?? 'zone '.$actor->zoneId;
        $recordedAt = $this->world->recordedAt();

        if (null !== $recordedAt) {
            // Said first and plainly. A recording that looks live reads as a server where
            // everyone has logged out, and the point of this one is that no server is running.
            $io->note(\sprintf(
                'A recording of a real session in %s, captured %s. No game server is running.',
                $zoneName,
                $recordedAt->format('j F Y'),
            ));
            $io->writeln((string) $actor->position);
        } else {
            $io->writeln((string) $actor->position);
            $io->writeln(\sprintf('%s, %s', $zoneName, $actor->isOnline ? '<info>online</info>' : '<comment>offline</comment>'));
        }

        $near = array_filter(
            $this->world->actorsNear($actor->position, $radius),
            static fn (Actor $a): bool => $a->guid !== $actor->guid,
        );
        $zone = array_filter(
            $this->world->actorsInZone($actor->zoneId),
            static fn (Actor $a): bool => $a->guid !== $actor->guid,
        );

        $io->section(\sprintf('Within %dyd: %d', $radius, \count($near)));

        if ([] !== $near) {
            $io->table(
                ['name', 'who', 'faction', 'lvl', 'peer?', 'distance'],
                array_map(fn (Actor $a): array => [
                    $a->name,
                    \sprintf('%s %s', $a->race->label(), $a->class->label()),
                    $a->faction()->value,
                    $a->level,
                    $a->isPeerOf($actor) ? 'yes' : '-',
                    \sprintf('%.0f yd', $a->position->distanceTo($actor->position) ?? 0),
                ], \array_slice($near, 0, 15)),
            );
        }

        $peers = array_filter($zone, static fn (Actor $a): bool => $a->isPeerOf($actor));
        $io->section('Zone');
        $io->definitionList(
            ['in zone' => \count($zone)],
            ['level-appropriate (±5)' => \count($peers)],
            ['same faction' => \count(array_filter($zone, static fn (Actor $a): bool => $a->isSameFactionAs($actor)))],
            ['online world-wide' => \count($this->world->onlineActors())],
        );

        return Command::SUCCESS;
    }
}
