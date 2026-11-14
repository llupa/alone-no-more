<?php

declare(strict_types=1);

namespace App\Tests\Roleplay;

use App\Roleplay\ReplySanitiser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The last thing between a model and the chat window.
 *
 * WoW drops any line over 255 characters outright, so something has to give — the only question
 * is whether it reads as a person finishing a thought or as a bot being cut off.
 */
#[CoversClass(ReplySanitiser::class)]
final class ReplySanitiserTest extends TestCase
{
    private ReplySanitiser $sanitiser;

    protected function setUp(): void
    {
        $this->sanitiser = new ReplySanitiser();
    }

    public function testAShortReplyIsLeftAlone(): void
    {
        self::assertSame('Aye, I am on my way.', $this->sanitiser->sanitise('Aye, I am on my way.'));
    }

    /**
     * Captured from a session: the word-boundary cut left "work fit for a priest,…" in party
     * chat, which reads as an interruption rather than as someone who stopped talking.
     */
    public function testALongReplyEndsOnAFinishedSentence(): void
    {
        $long = 'I can see none that I am able to take up myself, Klemprime, and my log is empty. '
            .'The records will not answer me just now. Marshal McBride and Brother Neals are both nearby, so ask them when we arrive. '
            .'Brother Neals may well have work fit for a priest, though I would not swear to it in this weather.';

        $out = $this->sanitiser->sanitise($long);

        self::assertNotNull($out);
        self::assertLessThanOrEqual(255, mb_strlen($out));
        self::assertStringEndsWith('ask them when we arrive.', $out);
        self::assertStringNotContainsString('…', $out);
    }

    /**
     * One enormous clause with no sentence end in range still has to fit, and a word boundary
     * is the least bad cut left.
     */
    public function testOneUnendingClauseFallsBackToAWordBoundary(): void
    {
        $out = $this->sanitiser->sanitise(str_repeat('onward ', 60));

        self::assertNotNull($out);
        self::assertLessThanOrEqual(255, mb_strlen($out));
        self::assertStringEndsWith('…', $out);
    }

    /**
     * A full stop inside "Mr." or a number is not the end of a sentence, and cutting there
     * would lose most of the reply.
     */
    public function testAStopInsideAWordDoesNotEndTheSentence(): void
    {
        $text = 'I counted 3.5 score of them by the ridge and I would not care to meet that many again, '
            .'not with Mr. Willem expecting us back before dusk and the light already going from the sky above us. '
            .'We should turn for the abbey now.';

        $out = $this->sanitiser->sanitise($text);

        self::assertNotNull($out);
        self::assertStringNotContainsString('3.5', substr($out, -10));
        self::assertGreaterThan(100, mb_strlen($out), 'cutting at "3.5" would throw the reply away');
    }

    /**
     * Multi-byte safety: cutting by byte would split an accent in half and the client renders
     * the remains as mojibake.
     */
    public function testCuttingNeverSplitsACharacter(): void
    {
        $out = $this->sanitiser->sanitise(str_repeat('Prüfung über Straße ', 30));

        self::assertNotNull($out);
        self::assertSame($out, mb_convert_encoding($out, 'UTF-8', 'UTF-8'));
    }
}
