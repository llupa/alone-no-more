<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\AskCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\MockAgent;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Asking the archivist directly, as someone trying the project does before anything else.
 */
#[CoversClass(AskCommand::class)]
final class AskCommandTest extends TestCase
{
    public function testTheArchivistsAnswerIsPrinted(): void
    {
        $tester = $this->ask(new MockAgent(['Where is the moonshine?' => 'Barkeep Daniels in Lakeshire sells it.']));
        $tester->execute(['question' => 'Where is the moonshine?']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Barkeep Daniels in Lakeshire sells it.', $tester->getDisplay());
    }

    /**
     * Without a key the provider says "x-api-key header is required", which names neither the
     * key nor the fact that the archivist always runs on Claude.
     */
    public function testAFailureSaysWhichKeyTheArchivistNeeds(): void
    {
        $tester = $this->ask(new MockAgent());
        $tester->execute(['question' => 'Where is the moonshine?']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('ANTHROPIC_API_KEY', $tester->getDisplay());
    }

    private function ask(MockAgent $archivist): CommandTester
    {
        $application = new Application();
        $application->addCommand(new AskCommand($archivist));

        return new CommandTester($application->find('anm:ask'));
    }
}
