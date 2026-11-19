<?php

declare(strict_types=1);

namespace App\Tests\World\Model;

use App\World\Model\ChatChannel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Where a companion's answer goes, given where the line it answers was said.
 *
 * Answering on the wrong channel is never just untidy: a whisper answered in /say reads a
 * private exchange out to the street, and an officer's line answered in guild chat reads it out
 * to every member.
 */
#[CoversClass(ChatChannel::class)]
final class ChatChannelTest extends TestCase
{
    /**
     * @return iterable<string, array{ChatChannel, string}>
     */
    public static function answers(): iterable
    {
        yield 'say' => [ChatChannel::Say, 'say'];
        yield 'yell' => [ChatChannel::Yell, 'say'];
        yield 'party' => [ChatChannel::Party, 'party'];
        yield 'party leader' => [ChatChannel::PartyLeader, 'party'];
        yield 'raid' => [ChatChannel::Raid, 'party'];
        yield 'guild' => [ChatChannel::Guild, 'guild'];
        yield 'officer' => [ChatChannel::Officer, 'officer'];
        yield 'whisper' => [ChatChannel::Whisper, 'whisper'];
    }

    #[DataProvider('answers')]
    public function testAnAnswerGoesBackWhereTheLineCameFrom(ChatChannel $heard, string $action): void
    {
        self::assertSame($action, $heard->replyAction());
    }

    /**
     * Officer chat used to be answered as plain guild chat.
     */
    public function testAnOfficersLineIsNotAnsweredToTheWholeGuild(): void
    {
        self::assertNotSame(ChatChannel::Guild->replyAction(), ChatChannel::Officer->replyAction());
    }
}
