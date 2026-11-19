<?php

declare(strict_types=1);

namespace App\Command;

use App\Director\PlayerCharacter;
use App\Roleplay\AgentProvider;
use App\Roleplay\PlayerbotVocabulary;
use App\Roleplay\RoleplayResponder;
use App\World\Model\Actor;
use App\World\Model\ChatChannel;
use App\World\Model\ChatEvent;
use App\World\WorldGatewayInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Talk to a companion from the terminal, as a player would in party chat.
 *
 * The line goes through exactly what a line typed in the game goes through — the same responder,
 * prompt, agent, tools and memory — so this is the companions themselves, not a demo of them.
 * Against a live server the reply is spoken in the game as well. Against the recording it is the
 * one way to meet a companion without owning a 2008 game client, which is the point of having it.
 */
#[AsCommand(name: 'anm:talk', description: 'Talk to your companions from the terminal, as a player in party chat.')]
final class TalkCommand
{
    public function __construct(
        private readonly RoleplayResponder $responder,
        private readonly WorldGatewayInterface $world,
        private readonly PlayerCharacter $player,
        private readonly AgentProvider $agents,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Who you are. Defaults to whoever is playing; against the recording, Klemprime.')]
        ?string $character = null,
        #[Option(description: 'Say this one line and stop, instead of holding a conversation.')]
        ?string $say = null,
        #[Option(description: 'Whisper to this companion instead of speaking in party chat.')]
        ?string $to = null,
    ): int {
        $character ??= $this->player->current()?->name;

        if (null === $character) {
            $io->error('Name the character you are speaking as: bin/console anm:talk Klemprime');

            return Command::FAILURE;
        }

        $speaker = $this->world->findActor($character);

        if (null === $speaker) {
            $io->error(\sprintf('No character named "%s".', $character));

            return Command::FAILURE;
        }

        $listener = null;

        if (null !== $to) {
            $listener = $this->world->findActor($to);

            if (null === $listener) {
                $io->error(\sprintf('No character named "%s" to whisper to.', $to));

                return Command::FAILURE;
            }
        }

        $this->introduce($io, $speaker, $listener);

        if (null !== $say) {
            $this->exchange($io, $speaker, $listener, $say);

            return Command::SUCCESS;
        }

        while (true) {
            $line = $io->ask($speaker->name);

            if (null === $line || \in_array(strtolower(trim($line)), ['', 'quit', 'exit'], true)) {
                break;
            }

            $this->exchange($io, $speaker, $listener, $line);
        }

        return Command::SUCCESS;
    }

    private function introduce(SymfonyStyle $io, Actor $speaker, ?Actor $listener): void
    {
        $io->title(\sprintf('Talking as %s', $speaker->name));

        $recordedAt = $this->world->recordedAt();

        if (null !== $recordedAt) {
            $io->note(\sprintf(
                'A recording of a real session, captured %s. The companions answer for real; nothing you '
                .'ask them to do can happen, because no game server is running.',
                $recordedAt->format('j F Y'),
            ));
        }

        $io->writeln(\sprintf(
            '%s. Answers come from <info>%s</info>. An empty line stops.',
            null === $listener ? 'Speaking in party chat' : 'Whispering to '.$listener->name,
            AgentProvider::ANTHROPIC === $this->agents->name() ? 'Claude' : 'Ollama, on this machine',
        ));

        if (AgentProvider::OLLAMA === $this->agents->name()) {
            $io->writeln('<comment>On Ollama, companions talk but use no tools. For the archivist and their actions: '
                .'ANM_AI_PROVIDER=anthropic ANTHROPIC_API_KEY=…</comment>');
        }

        $io->newLine();
    }

    private function exchange(SymfonyStyle $io, Actor $speaker, ?Actor $listener, string $line): void
    {
        $event = new ChatEvent(
            // Unique per line, and far from the ids of logged chat, which this never touches.
            id: (int) (microtime(true) * 1000),
            createdAt: new \DateTimeImmutable(),
            senderGuid: $speaker->guid,
            senderName: $speaker->name,
            channel: null === $listener ? ChatChannel::Party : ChatChannel::Whisper,
            message: $line,
            receiverGuid: null === $listener ? 0 : $listener->guid,
            channelName: '',
            position: $speaker->position,
            senderIsBot: false,
        );

        try {
            $reply = $this->responder->respondTo($event);
        } catch (\Throwable $e) {
            $io->error([$e->getMessage(), $this->whatToCheck()]);

            return;
        }

        if (null === $reply) {
            $io->writeln(PlayerbotVocabulary::isCommand($line)
                ? '  <comment>That is an order, and orders go to the game itself rather than to the conversation.</comment>'
                : '  <comment>Nobody answered — nobody in earshot, or they answered a moment ago.</comment>');

            return;
        }

        $io->writeln(\sprintf(
            '  <fg=green>%s</> (%s %s, lvl %d): %s',
            $reply->bot->name,
            $reply->bot->race->label(),
            $reply->bot->class->label(),
            $reply->bot->level,
            $reply->text,
        ));
    }

    /**
     * The usual reason a reply failed, for whichever provider is configured.
     */
    private function whatToCheck(): string
    {
        return AgentProvider::ANTHROPIC === $this->agents->name()
            ? 'Check ANTHROPIC_API_KEY.'
            : 'Is Ollama running? Start it with `ollama serve`, then `ollama pull llama3.1:8b`. '
                .'Or use Claude: ANM_AI_PROVIDER=anthropic ANTHROPIC_API_KEY=…';
    }
}
