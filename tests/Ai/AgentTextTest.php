<?php

declare(strict_types=1);

namespace App\Tests\Ai;

use App\Ai\AgentText;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ThinkingResult;

#[CoversClass(AgentText::class)]
final class AgentTextTest extends TestCase
{
    public function testPlainStringIsTrimmed(): void
    {
        self::assertSame('Aye, I know the place.', AgentText::of("  Aye, I know the place.\n"));
    }

    public function testTakesTheTextOutOfAReasonedReply(): void
    {
        // The shape that actually comes back from Sonnet with thinking on: an empty thinking
        // block carrying only a signature, then the words.
        $content = [
            new ThinkingResult(null, 'ErUBCkYIBxgCKkBr...'),
            new TextResult('Hyacinth Mushrooms are for Iverron\'s Antidote.'),
        ];

        self::assertSame("Hyacinth Mushrooms are for Iverron's Antidote.", AgentText::of($content));
    }

    public function testJoinsSeveralTextBlocks(): void
    {
        $content = [new TextResult('First.'), new TextResult('Second.')];

        self::assertSame('First. Second.', AgentText::of($content));
    }

    public function testThinkingOnlyReplyYieldsNothingSayable(): void
    {
        self::assertSame('', AgentText::of([new ThinkingResult('pondering', 'sig')]));
    }

    public function testUnknownShapeIsEmptyRatherThanFatal(): void
    {
        self::assertSame('', AgentText::of(null));
    }
}
