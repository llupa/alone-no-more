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
use PHPUnit\Framework\TestCase;

/**
 * Emptying the bags: selling to a merchant, and handing things to the player.
 *
 * Both came out of play. Told to sell, a companion answered "I have no means of selling to him
 * from here" — true, and useless. Told to trade, it could only ask the player to open the window
 * themselves, which nobody knows how to do mid-conversation.
 */
final class SellAndTradeTest extends TestCase
{
    public function testItSellsTheGreyThingsAtAMerchant(): void
    {
        $sent = [];
        $world = $this->recording($sent);

        $answer = $this->tools($world, $this->carrying([
            ['entry' => 1413, 'name' => 'Feeble Sword', 'quality' => 0, 'count' => 1],
            ['entry' => 2589, 'name' => 'Linen Cloth', 'quality' => 0, 'count' => 4],
        ]), $this->merchantAtHand())->sellJunk();

        self::assertContains('playerbots exec Menelan !s gray', $sent);
        self::assertStringContainsString('Feeble Sword', $answer);
        self::assertStringContainsString('Linen Cloth x4', $answer);
    }

    /**
     * "Sell your white items" is a thing players say after a long afternoon, and it has to mean
     * white rather than quietly meaning grey.
     */
    public function testItCanBeAskedForWhiteThingsToo(): void
    {
        $sent = [];
        $world = $this->recording($sent);

        $this->tools($world, $this->carrying([
            ['entry' => 2512, 'name' => 'Rough Arrow', 'quality' => 1, 'count' => 200],
        ]), $this->merchantAtHand())->sellJunk('white');

        self::assertContains('playerbots exec Menelan !s white', $sent);
    }

    /**
     * And nothing better. Green and above is gear somebody chose, and no sentence said in
     * passing should be able to sell it.
     */
    public function testItRefusesToSellAnythingGood(): void
    {
        $sent = [];
        $world = $this->recording($sent);

        $answer = $this->tools($world, $this->carrying([]), $this->merchantAtHand())->sellJunk('green');

        self::assertSame([], $sent);
        self::assertStringContainsString('only grey', $answer);
    }

    /**
     * The mod's `sell` silently does nothing without a vendor in reach, which from out here is
     * indistinguishable from having sold. Naming the nearest one is more use than a refusal.
     */
    public function testWithoutAMerchantItSaysHowFarTheNearestIs(): void
    {
        $sent = [];
        $world = $this->recording($sent);

        $merchants = $this->createStub(MerchantsInterface::class);
        $merchants->method('isOneAtHand')->willReturn(false);
        $merchants->method('nearest')->willReturn(['name' => 'Christopher Hewen', 'yards' => 140.0]);

        $answer = $this->tools($world, $this->carrying([]), $merchants)->sellJunk();

        self::assertSame([], $sent, 'nothing should have been sent');
        self::assertStringContainsString('Christopher Hewen', $answer);
        self::assertStringContainsString('140', $answer);
    }

    public function testNothingWorthlessIsNotASale(): void
    {
        $sent = [];
        $world = $this->recording($sent);

        $answer = $this->tools($world, $this->carrying([]), $this->merchantAtHand())->sellJunk();

        self::assertSame([], $sent);
        self::assertStringContainsString('nothing worthless', $answer);
    }

    /**
     * There is no quiet transfer: `GiveItemAction` refuses unless the receiver is another bot,
     * so handing something to a player means opening a trade window and putting it in. The link
     * is built from the entry id, never from the model's words.
     */
    public function testItOpensATradeAndPutsTheItemIn(): void
    {
        $sent = [];
        $world = $this->recording($sent);

        $bags = $this->createStub(BagsInterface::class);
        $bags->method('anythingNamed')->willReturn(
            ['entry' => 1413, 'name' => 'Feeble Sword', 'quality' => 0, 'item_level' => 1, 'count' => 1],
        );

        $answer = $this->tools($world, $bags, $this->merchantAtHand())->handOver('Feeble Sword');

        self::assertSame('playerbots exec Menelan !trade', $sent[0] ?? null);
        self::assertSame('playerbots exec Menelan !trade |Hitem:1413:0:0:0:0:0:0:0:0|h[item]|h|r 1', $sent[1] ?? null);
        self::assertStringContainsString('accept', $answer);
    }

    public function testItCannotHandOverWhatItIsNotCarrying(): void
    {
        $sent = [];
        $world = $this->recording($sent);

        $answer = $this->tools($world, $this->createStub(BagsInterface::class), $this->merchantAtHand())
            ->handOver('Thunderfury');

        self::assertSame([], $sent);
        self::assertStringContainsString('not carrying', $answer);
    }

    /**
     * @param array<int, array{entry: int, name: string, quality: int, count: int}> $junk
     */
    private function carrying(array $junk): BagsInterface
    {
        $bags = $this->createStub(BagsInterface::class);
        $bags->method('noBetterThan')->willReturn($junk);

        return $bags;
    }

    private function merchantAtHand(): MerchantsInterface
    {
        $merchants = $this->createStub(MerchantsInterface::class);
        $merchants->method('isOneAtHand')->willReturn(true);

        return $merchants;
    }

    /**
     * @param array<int, string> $sent
     */
    private function recording(array &$sent): WorldGatewayInterface
    {
        $world = $this->createMock(WorldGatewayInterface::class);
        $world->method('execute')->willReturnCallback(static function (string $c) use (&$sent): string {
            $sent[] = $c;

            return 'ok';
        });

        return $world;
    }

    private function tools(WorldGatewayInterface $world, BagsInterface $bags, MerchantsInterface $merchants): CompanionTools
    {
        $context = new BotCommandContext();
        $context->enter(ActorMother::bot(1, 'Menelan'), ActorMother::player(100), null);

        $runner = new PlayerbotCommandRunner($world);

        return new CompanionTools(
            $context,
            $runner,
            $this->createStub(QuestLogInterface::class),
            $bags,
            $merchants,
            $this->createStub(GameKnowledgeInterface::class),
            $this->createStub(LevelProgressInterface::class),
            new BotSpeechDispatcher($this->createStub(Connection::class)),
            new ErrandBook(),
            new Journeys($world, $runner),
            new LeadOrders(new Outbox($this->createStub(Connection::class))),
            new ZoneBounds('/nonexistent'),
        );
    }
}
