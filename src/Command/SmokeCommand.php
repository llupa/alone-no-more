<?php

declare(strict_types=1);

namespace App\Command;

use App\World\Chat\BotSpeechDispatcher;
use App\World\Chat\ChatEventRepositoryInterface;
use App\World\CommandServer\CommandServerClient;
use App\World\Exception\WorldGatewayException;
use App\World\Model\ChatChannel;
use App\World\Soap\SoapCommandExecutor;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Proves every channel this project depends on, against the real server, in one run.
 *
 * Exists because "the class encodes what I verified by hand" and "the class works" are different
 * claims. Run it after any change to the server, the module or the gateway.
 */
#[AsCommand(
    name: 'anm:smoke',
    description: 'Check every link to the game server end to end: SOAP, command server, chat capture, bot speech.',
)]
final class SmokeCommand
{
    public function __construct(
        private readonly SoapCommandExecutor $soap,
        private readonly CommandServerClient $commandServer,
        private readonly ChatEventRepositoryInterface $chatEvents,
        private readonly BotSpeechDispatcher $speech,
        #[Autowire(service: 'anm.characters_connection')]
        private readonly Connection $characters,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Bot that should speak. Defaults to the first online bot.')]
        ?string $bot = null,
        #[Option(description: 'Line the bot should say.')]
        string $line = 'The road to Astranaar is quiet tonight.',
    ): int {
        $failures = 0;

        $io->title('Alone No More — connectivity smoke test');

        // 1. SOAP ------------------------------------------------------------------------------
        $io->section('1. SOAP (GM console)');
        try {
            $info = $this->soap->execute('server info');
            $io->writeln(explode("\n", $info)[1] ?? $info);
            $io->success('SOAP reachable');
        } catch (WorldGatewayException $e) {
            $io->error($e->getMessage());
            ++$failures;
        }

        // 2. Bot roster ------------------------------------------------------------------------
        $io->section('2. Bot roster (characters database)');
        $botRow = null;
        try {
            $botRow = $this->characters->fetchAssociative(
                <<<'SQL'
                    SELECT c.guid, c.name, c.level, c.race, c.class, c.zone
                    FROM characters c
                    JOIN acore_auth.account a ON a.id = c.account
                    WHERE c.online = 1 AND a.username LIKE 'RNDBOT%'
                      AND (:name IS NULL OR c.name = :name)
                    ORDER BY c.guid
                    LIMIT 1
                    SQL,
                ['name' => $bot],
            ) ?: null;

            if (null === $botRow) {
                $io->error($bot ? \sprintf('Bot "%s" is not online.', $bot) : 'No bots online.');
                ++$failures;
            } else {
                $io->writeln(\sprintf(
                    'picked <info>%s</info> (guid %d, level %d, zone %d)',
                    $botRow['name'], $botRow['guid'], $botRow['level'], $botRow['zone'],
                ));
                $io->success('characters database reachable');
            }
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            ++$failures;
        }

        // 3. Command server --------------------------------------------------------------------
        $io->section('3. Playerbots command server (TCP)');
        if (null !== $botRow) {
            try {
                $position = $this->commandServer->position((int) $botRow['guid']);
                if (null === $position) {
                    $io->warning('command server answered, but reported no position for this bot');
                } else {
                    $io->writeln((string) $position);
                    $io->success('command server reachable');
                }
            } catch (WorldGatewayException $e) {
                $io->error($e->getMessage());
                ++$failures;
            }
        } else {
            $io->writeln('<comment>skipped — no bot</comment>');
        }

        // 4. Bot speech, via the outbox the C++ module drains -------------------------------------
        $io->section('4. Bot speech (anm_outbox → mod-anm-bridge)');
        if (null !== $botRow) {
            try {
                $id = $this->speech->say((int) $botRow['guid'], $line, ChatChannel::Say);
                $io->writeln(\sprintf('queued outbox row %d', $id));

                $status = $this->waitForDelivery($id);
                if ('done' === $status) {
                    $io->success(\sprintf('%s said: "%s"', $botRow['name'], $line));
                } else {
                    $io->error(\sprintf('outbox row %d ended as "%s"', $id, $status ?? 'missing'));
                    ++$failures;
                }
            } catch (\Throwable $e) {
                $io->error($e->getMessage());
                ++$failures;
            }
        } else {
            $io->writeln('<comment>skipped — no bot</comment>');
        }

        // 5. Chat capture ------------------------------------------------------------------------
        $io->section('5. Chat capture (anm_chat_event)');
        try {
            $latest = $this->chatEvents->latestId();
            $recent = $this->chatEvents->since(max(0, $latest - 5), 5);

            if ([] === $recent) {
                $io->warning('no chat captured yet — say something in game, then run this again');
            } else {
                foreach ($recent as $event) {
                    $io->writeln(\sprintf(
                        '  [%s] %s%s: %s',
                        $event->channel->name,
                        $event->senderName,
                        $event->senderIsBot ? ' (bot)' : '',
                        $event->message,
                    ));
                }
                $io->success(\sprintf('%d chat events readable', \count($recent)));
            }
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            ++$failures;
        }

        if ($failures > 0) {
            $io->error(\sprintf('%d check(s) failed', $failures));

            return Command::FAILURE;
        }

        $io->success('all checks passed');

        return Command::SUCCESS;
    }

    /**
     * The module drains the outbox every 500 ms, so a couple of seconds is generous.
     */
    private function waitForDelivery(int $outboxId, float $timeout = 5.0): ?string
    {
        $deadline = microtime(true) + $timeout;

        do {
            $status = $this->speech->statusOf($outboxId);

            if (null === $status || 'pending' !== $status) {
                return $status;
            }

            usleep(250_000);
        } while (microtime(true) < $deadline);

        return 'pending';
    }
}
