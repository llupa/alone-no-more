<?php

declare(strict_types=1);

namespace App\Tests\Roleplay\Tool;

use App\Roleplay\Tool\PlayerbotCommandRunner;
use App\World\Exception\WorldGatewayException;
use App\World\WorldGatewayInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The one place a model's choice of words reaches a GM console.
 *
 * The console can create accounts and ban players, and the text an assistant works from includes
 * whatever players have typed in chat. So malformed input is refused here rather than relied on
 * being harmless once it arrives.
 */
#[CoversClass(PlayerbotCommandRunner::class)]
final class PlayerbotCommandRunnerTest extends TestCase
{
    public function testAWellFormedOrderIsSent(): void
    {
        $world = $this->createMock(WorldGatewayInterface::class);
        $world->expects(self::once())
            ->method('execute')
            ->with('playerbots exec Nytalleath !follow')
            ->willReturn("Nytalleath: 'follow' sent");

        self::assertSame("Nytalleath: 'follow' sent", (new PlayerbotCommandRunner($world))->run('Nytalleath', 'follow'));
    }

    #[DataProvider('impossibleNames')]
    public function testANameThatCannotBeACharacterIsRefused(string $name): void
    {
        $world = $this->createMock(WorldGatewayInterface::class);
        $world->expects(self::never())->method('execute');

        $this->expectException(WorldGatewayException::class);

        (new PlayerbotCommandRunner($world))->run($name, 'follow');
    }

    /**
     * @return iterable<string, array<int, mixed>>
     */
    public static function impossibleNames(): iterable
    {
        // A newline is the obvious way to try for a second console line: the SOAP envelope
        // escapes XML, not whitespace.
        yield 'a second command' => ["Menelan\naccount create hax hax"];
        yield 'an argument break' => ['Menelan follow; account set gmlevel'];
        yield 'empty' => [''];
        yield 'too long for a character name' => ['Averyverylongname'];
        yield 'digits' => ['Menelan2'];
        yield 'a null byte' => ["Menelan\0"];
    }

    #[DataProvider('impossibleCommands')]
    public function testAMalformedCommandIsRefused(string $command): void
    {
        $world = $this->createMock(WorldGatewayInterface::class);
        $world->expects(self::never())->method('execute');

        $this->expectException(WorldGatewayException::class);

        (new PlayerbotCommandRunner($world))->run('Menelan', $command);
    }

    /**
     * @return iterable<string, array<int, mixed>>
     */
    public static function impossibleCommands(): iterable
    {
        yield 'a newline' => ["follow\naccount create hax hax"];
        yield 'a carriage return' => ["follow\rserver shutdown 1"];
        yield 'empty' => [''];
    }

    /**
     * Commands that legitimately take an argument must still work — the guard is about shape,
     * not about narrowing the vocabulary.
     */
    public function testACommandWithArgumentsStillPasses(): void
    {
        $world = $this->createMock(WorldGatewayInterface::class);
        $world->expects(self::once())
            ->method('execute')
            ->with('playerbots exec Menelan !co +dps')
            ->willReturn('ok');

        (new PlayerbotCommandRunner($world))->run('Menelan', 'co +dps');
    }

    /**
     * An item link is full of punctuation {@see PlayerbotCommandRunner::run()} refuses, which is
     * why equipping has its own method: the link is formatted from an integer, so none of that
     * punctuation can have come from a model. The mod accepts nothing else — it parses the
     * argument by looking for `Hitem:` and ignores plain names.
     */
    public function testEquippingSendsARealItemLink(): void
    {
        $world = $this->createMock(WorldGatewayInterface::class);
        $world->expects(self::once())
            ->method('execute')
            ->with('playerbots exec Nytalleath !e |Hitem:7281:0:0:0:0:0:0:0:0|h[item]|h|r')
            ->willReturn('ok');

        (new PlayerbotCommandRunner($world))->equipItem('Nytalleath', 7281);
    }

    public function testEquippingNothingIsRefused(): void
    {
        $world = $this->createMock(WorldGatewayInterface::class);
        $world->expects(self::never())->method('execute');

        $this->expectException(WorldGatewayException::class);

        (new PlayerbotCommandRunner($world))->equipItem('Nytalleath', 0);
    }
}
