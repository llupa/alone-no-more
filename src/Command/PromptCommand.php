<?php

declare(strict_types=1);

namespace App\Command;

use App\Director\PlayerCharacter;
use App\Knowledge\Dbc\AreaNames;
use App\Roleplay\Context\PromptBuilder;
use App\Roleplay\Context\RoleplayContext;
use App\World\Model\ChatChannel;
use App\World\Model\ChatEvent;
use App\World\WorldGatewayInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Show the system prompt a given bot would be given right now.
 *
 * Nothing about a bot's personality is written down anywhere — it is assembled from who that
 * character is, where it is standing, what is actually spawned around it and what it remembers
 * of you. This command makes that visible without an LLM in the loop, which is the fastest way
 * to tell a prompting problem from a model problem.
 */
#[AsCommand(name: 'anm:prompt', description: 'Show the system prompt a bot would be given, and where each line came from.')]
final class PromptCommand
{
    public function __construct(
        private readonly PlayerCharacter $player,
        private readonly WorldGatewayInterface $world,
        private readonly PromptBuilder $prompts,
        private readonly AreaNames $areaNames,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'The bot whose prompt to render.')]
        string $bot,
        #[Argument(description: 'Who is speaking to them.')]
        ?string $speaker = null,
        #[Option(description: 'What was said, so linked quests and items resolve.')]
        string $said = 'hello there',
    ): int {
        $botActor = $this->world->findActor($bot);

        // No name given: whoever is actually playing. Every bot sits on an RNDBOT account, so
        // the player is simply the online character that is not one of those — no configuration,
        // and nothing to go stale.
        $speaker ??= $this->player->current()?->name;

        if (null === $speaker) {
            $io->error('Nobody is online. Log a character in, or name one.');

            return Command::FAILURE;
        }

        $speakerActor = $this->world->findActor($speaker);

        if (null === $botActor || null === $speakerActor) {
            $io->error(\sprintf('No character named "%s".', null === $botActor ? $bot : $speaker));

            return Command::FAILURE;
        }

        $context = new RoleplayContext(
            bot: $botActor,
            speaker: $speakerActor,
            event: new ChatEvent(
                id: 0,
                createdAt: new \DateTimeImmutable(),
                senderGuid: $speakerActor->guid,
                senderName: $speakerActor->name,
                channel: ChatChannel::PartyLeader,
                message: $said,
                receiverGuid: 0,
                channelName: '',
                position: $speakerActor->position,
                senderIsBot: false,
            ),
            zoneName: $this->areaNames->name($botActor->zoneId),
        );

        $io->title($botActor->name.' — '.$botActor->describe());
        $io->writeln($this->prompts->build($context));
        $io->newLine();

        if (!$context->mentioned->isEmpty()) {
            $io->writeln('<comment>Things this bot could link back: resolved from the world database.</comment>');
        }

        return Command::SUCCESS;
    }
}
