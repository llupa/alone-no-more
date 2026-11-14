<?php

declare(strict_types=1);

namespace App\Tests\Roleplay\Selector;

use App\Roleplay\Memory\ConversationStoreInterface;
use App\Roleplay\Selector\PartyBotSelector;
use App\Tests\Roleplay\ActorMother;
use App\World\GroupRepositoryInterface;
use App\World\Model\ChatChannel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The selector decides whether the world answers you at all, so its silences matter as much as
 * its choices. Each test here corresponds to something that was wrong when first run live.
 */
final class PartyBotSelectorTest extends TestCase
{
    private function selector(
        array $partyGuids,
        array $secondsSinceReply = [],
        int $cooldown = 20,
        int $sticky = 90,
    ): PartyBotSelector {
        $groups = $this->createStub(GroupRepositoryInterface::class);
        $groups->method('memberGuidsWith')->willReturn($partyGuids);

        $memory = $this->createStub(ConversationStoreInterface::class);
        $memory->method('secondsSinceLastReply')->willReturnCallback(
            static fn (int $guid): ?int => $secondsSinceReply[$guid] ?? null,
        );

        return new PartyBotSelector($groups, $memory, $cooldown, 3, $sticky);
    }

    public function testThePartyAnswersPartyChat(): void
    {
        $player = ActorMother::player();
        $bot = ActorMother::bot(1, 'Nytalleath');

        $chosen = $this->selector([1])->select(
            ActorMother::said('hello'),
            $player,
            [$bot],
        );

        self::assertSame($bot, $chosen);
    }

    /**
     * Party chat from the group *leader* arrives on a different opcode (0x33) than from a member
     * (0x02). Getting this wrong would silently kill the feature for anyone leading their own
     * group — which is most people playing with bots.
     */
    #[DataProvider('partyChannels')]
    public function testEveryPartyChannelIsAnswered(ChatChannel $channel): void
    {
        $chosen = $this->selector([1])->select(
            ActorMother::said('hello', $channel),
            ActorMother::player(),
            [ActorMother::bot(1)],
        );

        self::assertNotNull($chosen, $channel->name.' should be answered');
    }

    public static function partyChannels(): iterable
    {
        yield 'party' => [ChatChannel::Party];
        yield 'party leader (0x33)' => [ChatChannel::PartyLeader];
        yield 'raid' => [ChatChannel::Raid];
        yield 'raid leader (0x27)' => [ChatChannel::RaidLeader];
    }

    #[DataProvider('nonPartyChannels')]
    public function testOpenWorldChannelsAreIgnored(ChatChannel $channel): void
    {
        $chosen = $this->selector([1])->select(
            ActorMother::said('hello', $channel),
            ActorMother::player(),
            [ActorMother::bot(1)],
        );

        self::assertNull($chosen, $channel->name.' is not party chat and must be left alone');
    }

    public static function nonPartyChannels(): iterable
    {
        yield 'say' => [ChatChannel::Say];
        yield 'yell' => [ChatChannel::Yell];
        yield 'channel' => [ChatChannel::Channel];
        yield 'guild' => [ChatChannel::Guild];
    }

    public function testNobodyAnswersWhenYouAreNotInAParty(): void
    {
        $chosen = $this->selector([])->select(
            ActorMother::said('anyone there?'),
            ActorMother::player(),
            [ActorMother::bot(1)],
        );

        self::assertNull($chosen);
    }

    public function testOnlyActualPartyMembersAnswer(): void
    {
        $stranger = ActorMother::bot(99, 'Passerby');

        $chosen = $this->selector([1])->select(
            ActorMother::said('hello'),
            ActorMother::player(),
            [$stranger],
        );

        self::assertNull($chosen, 'a bot outside the party must not answer party chat');
    }

    /**
     * Regression: on first run one bot answered every single line, which reads as one voice
     * wearing several names.
     *
     * Both gaps are past the sticky window on purpose — rotation is what happens in a lull.
     * Inside the window the previous speaker keeps the thread instead, which
     * testALiveConversationStaysWithWhoeverIsInIt covers.
     */
    public function testTheQuietestBotSpeaks(): void
    {
        $justSpoke = ActorMother::bot(1, 'Loud');
        $quietAges = ActorMother::bot(2, 'Quiet');

        $chosen = $this->selector([1, 2], [1 => 120, 2 => 900])->select(
            ActorMother::said('hello'),
            ActorMother::player(),
            [$justSpoke, $quietAges],
        );

        self::assertSame($quietAges, $chosen);
    }

    /**
     * Also a lull: 200s is past the sticky window, so nobody is holding a thread and a voice
     * the player has not heard yet wins.
     */
    public function testABotThatNeverSpokeIsPreferred(): void
    {
        $spokeRecently = ActorMother::bot(1, 'Known');
        $neverSpoke = ActorMother::bot(2, 'Fresh');

        $chosen = $this->selector([1, 2], [1 => 200])->select(
            ActorMother::said('hello'),
            ActorMother::player(),
            [$spokeRecently, $neverSpoke],
        );

        self::assertSame($neverSpoke, $chosen);
    }

    /**
     * Even the bot holding the thread is silent inside the anti-spam floor — it is exempt from
     * the long rotation cooldown, not from answering twice in the same breath.
     */
    public function testThePartyStaysQuietWhileEveryoneIsCoolingDown(): void
    {
        $chosen = $this->selector([1, 2], [1 => 1, 2 => 2], cooldown: 20)->select(
            ActorMother::said('hello'),
            ActorMother::player(),
            [ActorMother::bot(1), ActorMother::bot(2)],
        );

        self::assertNull($chosen, 'a real group does not all answer at once');
    }

    /**
     * Being ignored right after saying someone's name is the most obviously wrong thing the
     * selector can do, so a name beats both the cooldown and the rotation.
     */
    public function testNamingSomeoneGetsThatBotEvenMidCooldown(): void
    {
        $lylia = ActorMother::bot(1, 'Lylia');
        $nytalleath = ActorMother::bot(2, 'Nytalleath');

        $chosen = $this->selector([1, 2], [1 => 2, 2 => 900])->select(
            ActorMother::said('Lylia, what do you think?'),
            ActorMother::player(),
            [$lylia, $nytalleath],
        );

        self::assertSame($lylia, $chosen);
    }

    public function testANameIsMatchedWholeSoSimilarNamesDoNotAnswer(): void
    {
        $lylia = ActorMother::bot(1, 'Lylia');
        $lyliandra = ActorMother::bot(2, 'Lyliandra');

        $chosen = $this->selector([1, 2], [1 => 900, 2 => 900])->select(
            ActorMother::said('Lyliandra, over here'),
            ActorMother::player(),
            [$lylia, $lyliandra],
        );

        self::assertSame($lyliandra, $chosen);
    }

    public function testANameIsMatchedHoweverItIsCapitalised(): void
    {
        $lylia = ActorMother::bot(1, 'Lylia');

        $chosen = $this->selector([1, 2], [1 => 1])->select(
            ActorMother::said('oi lylia'),
            ActorMother::player(),
            [$lylia, ActorMother::bot(2, 'Nytalleath')],
        );

        self::assertSame($lylia, $chosen);
    }

    /**
     * Rotation is for lulls. Handing a follow-up question to a bot that was not in the exchange
     * produces an answer with no idea what it refers to.
     */
    public function testALiveConversationStaysWithWhoeverIsInIt(): void
    {
        $talking = ActorMother::bot(1, 'Talking');
        $idle = ActorMother::bot(2, 'Idle');

        $chosen = $this->selector([1, 2], [1 => 10, 2 => 900], sticky: 90)->select(
            ActorMother::said('and then what happened?'),
            ActorMother::player(),
            [$talking, $idle],
        );

        self::assertSame($talking, $chosen, 'the bot mid-conversation should keep it');
    }

    public function testRotationResumesOnceTheThreadGoesCold(): void
    {
        $spokeLongAgo = ActorMother::bot(1, 'Earlier');
        $neverSpoke = ActorMother::bot(2, 'Fresh');

        $chosen = $this->selector([1, 2], [1 => 300], sticky: 90)->select(
            ActorMother::said('anyone about?'),
            ActorMother::player(),
            [$spokeLongAgo, $neverSpoke],
        );

        self::assertSame($neverSpoke, $chosen);
    }

    public function testAWhisperIsAnsweredByItsRecipientAlone(): void
    {
        $addressed = ActorMother::bot(7, 'Addressed');
        $other = ActorMother::bot(8, 'Other');

        $chosen = $this->selector([])->select(
            ActorMother::said('psst', ChatChannel::Whisper, receiverGuid: 7),
            ActorMother::player(),
            [$other, $addressed],
        );

        self::assertSame($addressed, $chosen, 'a whisper names its recipient, party rules aside');
    }

    public function testAWhisperToAPlayerIsNotAnswered(): void
    {
        $chosen = $this->selector([])->select(
            ActorMother::said('psst', ChatChannel::Whisper, receiverGuid: 500),
            ActorMother::player(),
            [ActorMother::bot(7)],
        );

        self::assertNull($chosen);
    }
}
