<?php

declare(strict_types=1);

namespace App\Tests\Roleplay\Memory;

use App\Roleplay\Memory\ConversationStoreInterface;
use App\Roleplay\Memory\SceneMemoryProvider;
use App\Roleplay\Tool\BotCommandContext;
use App\Tests\Roleplay\ActorMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Input;
use Symfony\AI\Platform\Message\MessageBag;

/**
 * The provider exists because two party members each answered "are you coming?" as though the
 * other had not just answered it. Every test here is that failure, or a way of reintroducing it.
 */
#[CoversClass(SceneMemoryProvider::class)]
final class SceneMemoryProviderTest extends TestCase
{
    private const BOT = 1;
    private const PLAYER = 100;

    public function testACompanionsLineIsCarriedAsSomethingOverheard(): void
    {
        $memories = $this->load([
            ['speaker' => 'Menelan', 'message' => "I'm on my way.", 'said_at' => '2026-11-13 11:12:55'],
        ]);

        self::assertCount(1, $memories);
        self::assertStringContainsString('Menelan said: "I\'m on my way."', $memories[0]->getContent());
        self::assertStringContainsString('not your words', $memories[0]->getContent());
    }

    public function testNothingOverheardProducesNoMemoryAtAll(): void
    {
        self::assertSame([], $this->load([]));
    }

    /**
     * The bot's own words reach it as assistant turns. If they came back as memory too it would
     * answer itself, so the exclusion is pushed down to the query — assert it is asked for.
     */
    public function testItAsksForLinesExcludingBothTheBotAndThePlayer(): void
    {
        $store = $this->createMock(ConversationStoreInterface::class);
        $store->expects(self::once())
            ->method('sceneLines')
            ->with('party:'.self::PLAYER, [self::BOT, self::PLAYER], 6)
            ->willReturn([]);
        $store->method('acquaintance')->willReturn(null);

        $this->providerWith($store)->load($this->input());
    }

    public function testAWhisperHasNoSceneSoNothingIsOverheard(): void
    {
        $store = $this->createMock(ConversationStoreInterface::class);
        $store->expects(self::never())->method('sceneLines');
        $store->method('acquaintance')->willReturn(null);

        $context = new BotCommandContext();
        // No scene key: a whisper is between two people and the message bag already holds it.
        $context->enter(ActorMother::bot(self::BOT, 'Lyladaen'), ActorMother::player(self::PLAYER), null);

        self::assertSame([], (new SceneMemoryProvider($store, $context))->load($this->input()));
    }

    public function testPastMeetingsAreRecalledAsKnowledgeOfThePerson(): void
    {
        $memories = $this->load([], ['exchanges' => 14, 'last_seen' => '2026-11-12 20:04:11']);

        self::assertCount(1, $memories);
        self::assertStringContainsString('met Doppel before', $memories[0]->getContent());
        self::assertStringContainsString('14 times', $memories[0]->getContent());
    }

    /**
     * `anm:ask` talks to an agent with no bot acting. Memory there would be nonsense, and
     * reading the context would throw.
     */
    public function testOutsideAConversationThereIsNoMemory(): void
    {
        $store = $this->createStub(ConversationStoreInterface::class);

        self::assertSame([], (new SceneMemoryProvider($store, new BotCommandContext()))->load($this->input()));
    }

    /**
     * @param array<int, array{speaker: string, message: string, said_at: string}> $lines
     * @param array{exchanges: int, last_seen: string}|null                        $acquaintance
     *
     * @return list<\Symfony\AI\Agent\Memory\Memory>
     */
    private function load(array $lines, ?array $acquaintance = null): array
    {
        $store = $this->createStub(ConversationStoreInterface::class);
        $store->method('sceneLines')->willReturn($lines);
        $store->method('acquaintance')->willReturn($acquaintance);

        return $this->providerWith($store)->load($this->input());
    }

    private function providerWith(ConversationStoreInterface $store): SceneMemoryProvider
    {
        $context = new BotCommandContext();
        $context->enter(
            ActorMother::bot(self::BOT, 'Lyladaen'),
            ActorMother::player(self::PLAYER),
            'party:'.self::PLAYER,
        );

        return new SceneMemoryProvider($store, $context);
    }

    private function input(): Input
    {
        return new Input('claude-sonnet-5-5', new MessageBag());
    }
}
