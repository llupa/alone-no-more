<?php

declare(strict_types=1);

namespace App\Tests\Roleplay\Tool;

use App\Knowledge\BagsInterface;
use App\Knowledge\Dbc\ZoneBounds;
use App\Knowledge\GameKnowledgeInterface;
use App\Knowledge\LevelProgressInterface;
use App\Knowledge\MerchantsInterface;
use App\Knowledge\QuestLogInterface;
use App\Roleplay\Errand\ErrandBook;
use App\Roleplay\Tool\BotCommandContext;
use App\Roleplay\Tool\CompanionTools;
use App\Roleplay\Tool\PlayerbotCommandRunner;
use App\Roleplay\Travel\Journeys;
use App\Roleplay\Travel\LeadOrders;
use App\Tests\Roleplay\ActorMother;
use App\World\Chat\BotSpeechDispatcher;
use App\World\Outbox;
use App\World\WorldGatewayInterface;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a companion may be told to do.
 *
 * The model chooses these verbs from text it does not control: party chat it overheard, and
 * whatever any player has typed. So the gate has to be the short list the tool description
 * promises, not the mod's whole vocabulary — which also contains `destroy`, `sendmail` and
 * `guild remove`.
 */
final class CompanionActionsTest extends TestCase
{
    #[DataProvider('promisedActions')]
    public function testAPromisedActionReachesTheBot(string $command): void
    {
        $world = $this->createMock(WorldGatewayInterface::class);
        $world->expects(self::once())
            ->method('execute')
            ->with('playerbots exec Menelan !'.$command)
            ->willReturn('ok');

        self::assertStringContainsString('Done', $this->tools($world)->doThis($command));
    }

    /**
     * @return iterable<string, array<int, mixed>>
     */
    public static function promisedActions(): iterable
    {
        yield 'follow' => ['follow'];
        yield 'stay' => ['stay'];
        yield 'repair' => ['repair'];
        yield 'dismount' => ['dismount'];
        // Gear, added after a companion had to refuse a reasonable request.
        yield 'equip upgrade' => ['equip upgrade'];
    }

    /**
     * Each of these is in the mod's vocabulary and was therefore executable, while the tool
     * description offered twenty-one harmless verbs and never mentioned any of them.
     */
    #[DataProvider('neverPromised')]
    public function testAVerbTheDescriptionNeverOfferedIsRefused(string $command): void
    {
        $world = $this->createMock(WorldGatewayInterface::class);
        $world->expects(self::never())->method('execute');

        self::assertStringContainsString('do not know how', $this->tools($world)->doThis($command));
    }

    /**
     * @return iterable<string, array<int, mixed>>
     */
    public static function neverPromised(): iterable
    {
        yield 'destroys items' => ['destroy'];
        yield 'mails them away' => ['sendmail'];
        yield 'kicks a guild member' => ['guild remove'];
        yield 'drops something' => ['drop'];
        yield 'conjures fresh gear' => ['autogear'];
        yield 'conjures best-in-slot gear' => ['autogear bis'];
        yield 'invented entirely' => ['perform a heroic charge'];
    }

    /**
     * The mod's `equip` parses item links and nothing else — `ChatHelper::parseItems` looks for
     * the substring `Hitem:` and has no name matching at all. Sending the name the player said
     * parsed to an empty set, so the bot did nothing and the tool still reported success. The
     * name therefore has to be resolved to an entry id and sent as a link.
     */
    public function testItWearsSomethingByName(): void
    {
        $bags = $this->createMock(BagsInterface::class);
        $bags->method('wearableNamed')
            ->with(1, "Journeyman's Pants")
            ->willReturn(['entry' => 7281, 'name' => "Journeyman's Pants", 'quality' => 2, 'item_level' => 25]);

        $world = $this->createMock(WorldGatewayInterface::class);
        $world->expects(self::once())
            ->method('execute')
            ->with('playerbots exec Menelan !e |Hitem:7281:0:0:0:0:0:0:0:0|h[item]|h|r')
            ->willReturn('ok');

        self::assertStringContainsString(
            "Journeyman's Pants",
            $this->tools($world, $bags)->wearThis("Journeyman's Pants"),
        );
    }

    /**
     * The old wording was "You have put on the %s, if it was in your bags" for every request,
     * true or not. A companion that cannot find the thing should say so.
     */
    public function testItSaysSoWhenItIsNotCarryingTheThing(): void
    {
        $bags = $this->createMock(BagsInterface::class);
        $bags->method('wearableNamed')->willReturn(null);

        $world = $this->createMock(WorldGatewayInterface::class);
        $world->expects(self::never())->method('execute');

        self::assertStringContainsString(
            'not carrying',
            $this->tools($world, $bags)->wearThis('Thunderfury'),
        );
    }

    /**
     * The name reaches a GM console, so it has to look like a name.
     */
    public function testSomethingThatIsNotAnItemNameIsRefused(): void
    {
        $world = $this->createMock(WorldGatewayInterface::class);
        $world->expects(self::never())->method('execute');

        self::assertStringContainsString(
            'not the name of a thing',
            $this->tools($world)->wearThis("pants\nserver shutdown 1"),
        );
    }

    /**
     * Bare verbs only. An argument is reaching for something the list does not cover, and
     * "follow" with a tail is not "follow".
     */
    public function testAPromisedVerbCarryingArgumentsIsRefused(): void
    {
        $world = $this->createMock(WorldGatewayInterface::class);
        $world->expects(self::never())->method('execute');

        self::assertStringContainsString('do not know how', $this->tools($world)->doThis('follow; destroy'));
    }

    private function tools(WorldGatewayInterface $world, ?BagsInterface $bags = null): CompanionTools
    {
        $context = new BotCommandContext();
        $context->enter(ActorMother::bot(1, 'Menelan'), ActorMother::player(100), null);

        $runner = new PlayerbotCommandRunner($world);

        return new CompanionTools(
            $context,
            $runner,
            $this->createStub(QuestLogInterface::class),
            $bags ?? $this->createStub(BagsInterface::class),
            $this->createStub(MerchantsInterface::class),
            $this->createStub(GameKnowledgeInterface::class),
            $this->createStub(LevelProgressInterface::class),
            new BotSpeechDispatcher($this->createStub(Connection::class)),
            new ErrandBook(),
            new Journeys($world, $runner, new LeadOrders(new Outbox($this->createStub(Connection::class)))),
            new LeadOrders(new Outbox($this->createStub(Connection::class))),
            new ZoneBounds('/nonexistent'),
        );
    }
}
