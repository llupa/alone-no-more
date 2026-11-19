<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\TalkCommand;
use App\Config\Settings;
use App\Director\PlayerCharacter;
use App\Knowledge\Dbc\AreaNames;
use App\Replay\ReplayGateway;
use App\Replay\ReplayGroupRepository;
use App\Replay\ReplayOutbox;
use App\Replay\Snapshot;
use App\Roleplay\AgentProvider;
use App\Roleplay\Context\PromptBuilder;
use App\Roleplay\Memory\ConversationBags;
use App\Roleplay\Memory\ConversationStore;
use App\Roleplay\Party\PartyVoice;
use App\Roleplay\ReplySanitiser;
use App\Roleplay\RoleplayResponder;
use App\Roleplay\Selector\PartyVoiceSelector;
use App\Roleplay\Tool\BotCommandContext;
use App\World\Chat\BotSpeechDispatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\MockAgent;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Talking to a companion with no game at all — the way a judge meets them.
 *
 * Everything here is the real thing except the model: the recorded world, the party, the voice
 * that answers for it, the conversation memory. The model is Symfony AI's own MockAgent, so the
 * test says what the command does with an answer, not what a model happens to say.
 */
#[CoversClass(TalkCommand::class)]
final class TalkCommandTest extends TestCase
{
    private string $state;

    protected function setUp(): void
    {
        $this->state = (string) tempnam(sys_get_temp_dir(), 'anm-talk-');
    }

    protected function tearDown(): void
    {
        @unlink($this->state);
    }

    public function testAPartyLineIsAnsweredByTheCompanionFromTheRecording(): void
    {
        $model = new MockAgent(['Anduril, how do you fare?' => 'Well enough, Klemprime, though the weather is grim.']);

        $tester = $this->talk($model);
        $tester->execute(['character' => 'Klemprime', '--say' => 'Anduril, how do you fare?']);

        $out = $tester->getDisplay();
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Anduril (Night Elf Priest, lvl 13): Well enough, Klemprime', $out);
        self::assertStringContainsString('A recording of a real session', preg_replace('/\s+/', ' ', $out) ?? '');
    }

    /**
     * The second line reaches the model with the first exchange already in the conversation —
     * the companion remembers what was just said, from the same memory the game uses.
     */
    public function testTheCompanionRemembersTheConversation(): void
    {
        $model = new MockAgent([
            'Where are we headed?' => 'To Lakeshire, Klemprime.',
            'Why there?' => 'Because Barkeep Daniels wants his drinks.',
        ]);

        $tester = $this->talk($model);
        $tester->setInputs(['Where are we headed?', 'Why there?', '']);
        $tester->execute(['character' => 'Klemprime']);

        self::assertStringContainsString('Because Barkeep Daniels wants his drinks.', $tester->getDisplay());

        $second = $model->getCall(1)['messages']->getMessages();
        $remembered = array_filter($second, static fn ($m): bool => $m instanceof AssistantMessage && 'To Lakeshire, Klemprime.' === $m->asText());
        self::assertCount(1, $remembered, 'the first answer is part of the second conversation');
    }

    /**
     * "follow" is an order, and orders belong to the game's own command handler. Answering it
     * in conversation as well would talk over the thing the player asked for.
     */
    public function testAnOrderIsLeftToTheGameAndNeverReachesTheModel(): void
    {
        $model = new MockAgent();

        $tester = $this->talk($model);
        $tester->execute(['character' => 'Klemprime', '--say' => 'follow']);

        self::assertStringContainsString('orders go to the game itself', $tester->getDisplay());
        $model->assertNotCalled();
    }

    /**
     * A model that fails — no key, Ollama not running — says what to check instead of a stack
     * trace, because the person reading it is trying the project for the first time.
     */
    public function testAModelFailureSaysWhatToCheck(): void
    {
        $tester = $this->talk(new MockAgent());
        $tester->execute(['character' => 'Klemprime', '--say' => 'Hello there']);

        self::assertStringContainsString('ANTHROPIC_API_KEY', $tester->getDisplay());
    }

    public function testSomebodyWhoIsNotInTheWorldCannotTalk(): void
    {
        $tester = $this->talk(new MockAgent());
        $tester->execute(['character' => 'Arthas', '--say' => 'Hello']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('No character named "Arthas"', $tester->getDisplay());
    }

    private function talk(MockAgent $model): CommandTester
    {
        $snapshot = new Snapshot(\dirname(__DIR__, 2).'/fixtures/world.json');
        $world = new ReplayGateway($snapshot);
        $groups = new ReplayGroupRepository($snapshot);
        $store = new ConversationStore($this->state);
        $voice = new PartyVoice($this->state);
        $agents = new AgentProvider(
            static fn () => $model,
            static fn () => $model,
            static fn () => $model,
            static fn () => $model,
            AgentProvider::ANTHROPIC,
        );

        $responder = new RoleplayResponder(
            $world,
            $groups,
            new PartyVoiceSelector($groups, $voice, $store, cooldownSeconds: 0),
            new PromptBuilder([]),
            $agents,
            new BotSpeechDispatcher(new ReplayOutbox()),
            $store,
            new ConversationBags($store),
            $voice,
            new AreaNames('/nonexistent-client-data', null, \dirname(__DIR__, 2).'/fixtures/areas.json'),
            new BotCommandContext(),
            new ReplySanitiser(),
        );

        $application = new Application();
        $application->addCommand(new TalkCommand($responder, $world, new PlayerCharacter($world, new Settings($this->state)), $agents));

        return new CommandTester($application->find('anm:talk'));
    }
}
