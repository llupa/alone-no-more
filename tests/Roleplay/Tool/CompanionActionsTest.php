<?php

declare(strict_types=1);

namespace App\Tests\Roleplay\Tool;

use App\Knowledge\GameKnowledgeInterface;
use App\Knowledge\QuestLogInterface;
use App\Roleplay\Errand\ErrandBook;
use App\Roleplay\Tool\BotCommandContext;
use App\Roleplay\Tool\CompanionTools;
use App\Roleplay\Tool\PlayerbotCommandRunner;
use App\Roleplay\Travel\Journeys;
use App\Tests\Roleplay\ActorMother;
use App\World\Chat\BotSpeechDispatcher;
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
        yield 'invented entirely' => ['perform a heroic charge'];
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

    private function tools(WorldGatewayInterface $world): CompanionTools
    {
        $context = new BotCommandContext();
        $context->enter(ActorMother::bot(1, 'Menelan'), ActorMother::player(100), null);

        $runner = new PlayerbotCommandRunner($world);

        return new CompanionTools(
            $context,
            $runner,
            $this->createStub(QuestLogInterface::class),
            $this->createStub(GameKnowledgeInterface::class),
            new BotSpeechDispatcher($this->createStub(Connection::class)),
            new ErrandBook(),
            new Journeys($world, $runner),
        );
    }
}
