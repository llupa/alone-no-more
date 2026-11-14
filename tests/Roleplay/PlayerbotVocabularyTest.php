<?php

declare(strict_types=1);

namespace App\Tests\Roleplay;

use App\Roleplay\PlayerbotVocabulary;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The line between driving a companion and talking to one.
 *
 * It used to be a judgement call — first word in the mod's 135-command vocabulary, short enough,
 * no ordinary English in the rest — and every version of that heuristic shipped a bug. `who` is
 * a real command, so "Who is Anub'arak?" went to the mod and the player got silence. Mentioning
 * "cloth" opened a trade window.
 *
 * Setting `Playerbots.CommandPrefix` moved the line into the server's own config: the mod now
 * ignores anything unprefixed, so there is nothing left for us to guess at.
 */
final class PlayerbotVocabularyTest extends TestCase
{
    #[DataProvider('orders')]
    public function testPrefixedLinesAreLeftToTheMod(string $message): void
    {
        self::assertTrue(PlayerbotVocabulary::isCommand($message), "\"$message\" is an order");
    }

    public static function orders(): iterable
    {
        yield 'bare command' => ['!follow'];
        yield 'with an argument' => ['!cast fireball'];
        yield 'strategy toggle' => ['!nc +grind'];
        yield 'leading whitespace' => ['  !stay  '];
    }

    /**
     * Every one of these was mis-read as an order at some point, and each cost a player a reply.
     */
    #[DataProvider('conversation')]
    public function testEverythingElseIsConversation(string $message): void
    {
        self::assertFalse(PlayerbotVocabulary::isCommand($message), "\"$message\" is conversation");
    }

    public static function conversation(): iterable
    {
        yield 'a question beginning with a command word' => ["Who is Anub'arak?"];
        yield 'a question with no mark' => ['who is deputy willem'];
        // "do " was executed as an action name, and answered with a red "unknown action".
        yield 'a question beginning with do' => ['Do you have quests?'];
        yield 'an unprefixed verb' => ['follow'];
        yield 'a verb wrapped in english' => ['stay with me'];
        yield 'plain talk' => ['I need three more'];
        yield 'a mention that once opened a trade window' => ['that cloth looks useful'];
        yield 'empty' => [''];
        yield 'the bare prefix' => ['!'];
    }

    /**
     * Without a prefix configured the mod parses everything, and so would we — there would be no
     * line to draw. Guarding it means a misconfigured server degrades to "answer everything"
     * rather than "answer nothing".
     */
    public function testWithNoPrefixNothingIsTreatedAsAnOrder(): void
    {
        self::assertFalse(PlayerbotVocabulary::isCommand('follow', prefix: ''));
    }

    public function testTheVocabularyIsStillAvailableForValidation(): void
    {
        self::assertContains('follow', PlayerbotVocabulary::commands());
    }
}
