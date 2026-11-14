<?php

declare(strict_types=1);

namespace App\Tests\Roleplay\Selector;

use App\Roleplay\Memory\ConversationStoreInterface;
use App\Roleplay\Party\PartyVoice;
use App\Roleplay\Selector\PartyVoiceSelector;
use App\Tests\Roleplay\ActorMother;
use App\World\GroupRepositoryInterface;
use App\World\Model\ChatChannel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * One companion speaks for the party, and the player chooses which by saying a name.
 *
 * Every test here is a way the old rotation policy broke a conversation.
 */
#[CoversClass(PartyVoiceSelector::class)]
final class PartyVoiceSelectorTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir().'/anm-vsel-'.bin2hex(random_bytes(6)).'.sqlite';
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    /**
     * @param array<int, int> $secondsSinceReply
     */
    private function selector(array $secondsSinceReply = []): PartyVoiceSelector
    {
        $groups = $this->createStub(GroupRepositoryInterface::class);
        $groups->method('memberGuidsWith')->willReturn([100, 1, 2, 3]);

        $memory = $this->createStub(ConversationStoreInterface::class);
        $memory->method('secondsSinceLastReply')->willReturnCallback(
            static fn (int $guid): ?int => $secondsSinceReply[$guid] ?? null,
        );

        return new PartyVoiceSelector($groups, new PartyVoice($this->path), $memory, cooldownSeconds: 3);
    }

    /**
     * The failure this replaces: two members answered the same question two minutes apart, each
     * unaware the other had.
     */
    public function testTheSameCompanionKeepsAnsweringRatherThanRotating(): void
    {
        $speaker = ActorMother::player(100);
        $members = [ActorMother::bot(1, 'Menelan'), ActorMother::bot(2, 'Lyladaen')];
        $selector = $this->selector();

        $first = $selector->select(ActorMother::said('are you coming?'), $speaker, $members);
        $second = $selector->select(ActorMother::said('still there?'), $speaker, $members);

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertSame($first->name, $second->name);
    }

    public function testSayingANameHandsThatCompanionTheFloor(): void
    {
        $speaker = ActorMother::player(100);
        $members = [ActorMother::bot(1, 'Menelan'), ActorMother::bot(2, 'Lyladaen')];
        $selector = $this->selector();

        $selector->select(ActorMother::said('are you coming?'), $speaker, $members);
        $named = $selector->select(ActorMother::said('Lyladaen, what do you think?'), $speaker, $members);

        self::assertNotNull($named);
        self::assertSame('Lyladaen', $named->name);

        // And it stays with her: answering once and reverting is the rotation problem again.
        $after = $selector->select(ActorMother::said('and then?'), $speaker, $members);
        self::assertNotNull($after);
        self::assertSame('Lyladaen', $after->name);
    }

    /**
     * Being ignored straight after saying someone's name is the most obviously wrong thing this
     * can do, so the anti-spam floor does not apply to it.
     */
    public function testANamedCompanionAnswersEvenInsideTheCooldown(): void
    {
        $speaker = ActorMother::player(100);
        $members = [ActorMother::bot(1, 'Menelan'), ActorMother::bot(2, 'Lyladaen')];

        $named = $this->selector([2 => 0])
            ->select(ActorMother::said('Lyladaen?'), $speaker, $members);

        self::assertNotNull($named);
        self::assertSame('Lyladaen', $named->name);
    }

    public function testTheVoiceStaysQuietWhileItHasJustSpoken(): void
    {
        $speaker = ActorMother::player(100);
        $members = [ActorMother::bot(1, 'Menelan')];

        self::assertNull(
            $this->selector([1 => 0])->select(ActorMother::said('hello?'), $speaker, $members),
        );
    }

    public function testNobodyAnswersAChannelThePartyCannotHear(): void
    {
        $speaker = ActorMother::player(100);
        $event = ActorMother::said('hello', ChatChannel::Guild);

        self::assertNull($this->selector()->select($event, $speaker, [ActorMother::bot(1, 'Menelan')]));
    }
}
