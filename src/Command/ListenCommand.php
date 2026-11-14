<?php

declare(strict_types=1);

namespace App\Command;

use App\Director\CompanionsOnCall;
use App\Director\PlayerCharacter;
use App\Director\Settings;
use App\Herding\HerdingWatcher;
use App\Roleplay\AgentProvider;
use App\Roleplay\Banter\BanterDirector;
use App\Roleplay\Errand\ErrandRunner;
use App\Roleplay\Milestones\MilestoneHerald;
use App\Roleplay\Milestones\MilestoneRepository;
use App\Roleplay\RoleplayResponder;
use App\Roleplay\Travel\Journeys;
use App\World\Chat\ChatEventRepositoryInterface;
use App\World\WorldGatewayInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Tails the game's chat and lets bots answer.
 *
 * The headless form of the roleplay feature: everything the TUI will eventually show, printed to
 * a terminal. Useful on its own, and it keeps the responder honest by exercising it without a
 * rendering layer in the way.
 */
#[AsCommand(name: 'anm:listen', description: 'Listen to game chat and let nearby bots reply in character.')]
final class ListenCommand
{
    public function __construct(
        private readonly ChatEventRepositoryInterface $chatEvents,
        private readonly RoleplayResponder $responder,
        private readonly WorldGatewayInterface $world,
        private readonly AgentProvider $agents,
        private readonly HerdingWatcher $watcher,
        private readonly Settings $settings,
        private readonly PlayerCharacter $player,
        private readonly CompanionsOnCall $onCall,
        private readonly ErrandRunner $errands,
        private readonly BanterDirector $banter,
        private readonly Journeys $journeys,
        private readonly MilestoneRepository $milestones,
        private readonly MilestoneHerald $herald,
    ) {
    }

    /** The last thing said about why nothing is happening, so it is said once rather than every tick. */
    private ?string $lastQuietNotice = null;

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Milliseconds between polls of the chat log.')]
        int $interval = 1000,
        #[Option(description: 'Answer chat already in the log rather than starting from now.')]
        bool $backlog = false,
        #[Option(description: 'Show the system prompt each reply was built from.')]
        bool $explain = false,
        #[Option(description: 'Do not populate the world; only answer chat.')]
        bool $noHerding = false,
    ): int {
        $watermark = $backlog ? 0 : $this->chatEvents->latestId();

        $io->title('Alone No More — listening');
        $io->writeln(\sprintf(
            'model: <info>%s</info>   herding: <info>%s</info>   from chat id: %d',
            $this->agents->name(),
            $noHerding ? 'off' : 'on',
            $watermark,
        ));
        // Start from now, like the chat watermark: nobody wants congratulating for a level
        // they gained an hour ago.
        $milestoneMark = $backlog ? 0 : $this->milestones->latestId();

        $io->writeln('<comment>Ctrl-C to stop.</comment>');
        $io->newLine();

        while (true) {
            // Positions must be fresh before we decide who is in earshot: AzerothCore only
            // persists them every 15 minutes otherwise.
            $this->world->refresh();

            if (!$noHerding) {
                $this->keepTheWorldPopulated($io);
            }

            // Companions working through a standing order get one more swing. Same loop as
            // everything else: it needs the same fresh view of the world.
            foreach ($this->errands->tick() as $done) {
                $io->writeln('<fg=cyan>~ '.$done.'</>');
            }

            // Dings and achievements, recorded by the server module as they happened.
            foreach ($this->milestones->since($milestoneMark) as $milestone) {
                $milestoneMark = max($milestoneMark, $milestone->id);

                if (null !== $said = $this->herald->announce($milestone)) {
                    $io->writeln('<fg=green>  '.$said.'</>');
                }
            }

            foreach ($this->journeys->tick() as $arrival) {
                $io->writeln('<fg=cyan>~ '.$arrival.'</>');
            }

            if (null !== $muttered = $this->banter->tick()) {
                $io->writeln('<fg=magenta>  '.$muttered.'</>');
            }

            foreach ($this->chatEvents->since($watermark) as $event) {
                $watermark = max($watermark, $event->id);

                $io->writeln(\sprintf(
                    '<fg=gray>%s</> [%s] <info>%s</info>: %s',
                    $event->createdAt->format('H:i:s'),
                    $event->channel->name,
                    $event->senderName,
                    $event->message,
                ));

                try {
                    $reply = $this->responder->respondTo($event);
                } catch (\Throwable $e) {
                    $io->writeln('    <error>'.$e->getMessage().'</error>');

                    continue;
                }

                if (null === $reply) {
                    continue;
                }

                $io->writeln(\sprintf(
                    '    <fg=green>→ %s</> (%s %s, lvl %d): %s',
                    $reply->bot->name,
                    $reply->bot->race->label(),
                    $reply->bot->class->label(),
                    $reply->bot->level,
                    $reply->text,
                ));

                if ($explain) {
                    $io->writeln('<fg=gray>'.preg_replace('/^/m', '      │ ', $reply->prompt).'</>');
                }
            }

            usleep($interval * 1000);
        }

        return Command::SUCCESS;
    }

    /**
     * Say why nothing is happening, once.
     *
     * Waiting and broken look identical from outside: a process that prints nothing is a process
     * you assume has died. That is exactly what happened — the director was following a
     * character who had not logged in yet, and reported it by sitting in silence.
     */
    private function explainTheQuiet(SymfonyStyle $io): void
    {
        $reason = 'Nobody is online. Waiting for someone to log in.';

        // Once per change of state, not once a second.
        if ($reason === $this->lastQuietNotice) {
            return;
        }

        $this->lastQuietNotice = $reason;
        $io->writeln('<fg=yellow>~ '.$reason.'</>');
    }

    /**
     * Follow whoever is playing and keep a crowd around them.
     *
     * Runs in the same loop as the chat listener rather than as its own process: the two need
     * the same fresh view of the world, and one process is one thing to restart.
     */
    private function keepTheWorldPopulated(SymfonyStyle $io): void
    {
        if (!$this->settings->isHerding()) {
            return;
        }

        $players = $this->player->toFollow();

        if ([] === $players) {
            $this->explainTheQuiet($io);

            // Nobody to follow. Forget where they were — by the time they return it is stale.
            $this->watcher->reset();

            return;
        }

        // Following someone other than the pinned character is worth saying once: it is the
        // difference between the director ignoring you and the pin being out of date.
        $stale = $this->player->pinIsStale();

        if (null !== $stale && $stale !== $this->lastQuietNotice) {
            $this->lastQuietNotice = $stale;
            $io->writeln(\sprintf('<fg=yellow>~ %s, so following whoever is playing instead.</>', $stale));
        } elseif (null === $stale) {
            $this->lastQuietNotice = null;
        }

        foreach ($players as $player) {
            // Anyone asked for in advance arrives first, so they are in the world before the
            // crowd is sized around it.
            foreach ($this->onCall->gather($player) as $name) {
                $io->writeln(\sprintf('<fg=cyan>~ %s was waiting for %s and is here</>', $name, $player->name));
            }

            try {
                $result = $this->watcher->tick($player);
            } catch (\Throwable $e) {
                $io->writeln('    <error>herding: '.$e->getMessage().'</error>');

                continue;
            }

            if (null !== $result && $result->changed()) {
                $io->writeln(\sprintf(
                    '<fg=cyan>~ %s: %s</>',
                    $player->name,
                    $result->summary(),
                ));
            }
        }
    }
}
