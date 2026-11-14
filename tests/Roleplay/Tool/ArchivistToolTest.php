<?php

declare(strict_types=1);

namespace App\Tests\Roleplay\Tool;

use App\Roleplay\Tool\ArchivistTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\Execution\ExecutionInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ThinkingResult;

/**
 * Asking the archivist, and surviving it having thought first.
 *
 * Symfony AI's own Subagent tool asserts the agent returned a `TextResult`, which stops being
 * true the moment extended thinking is on: the result is then a `ThinkingResult` followed by
 * the `TextResult`. Every lookup threw, and companions told players the archives would not
 * answer them — a headline feature failing quietly behind an in-character apology.
 */
#[CoversClass(ArchivistTool::class)]
final class ArchivistToolTest extends TestCase
{
    public function testItReadsAnAnswerThatCameWithThinking(): void
    {
        $tool = new ArchivistTool($this->archivistReturning([
            new ThinkingResult(''),
            new TextResult('Goldtooth is in Jasperlode Mine, east of Goldshire.'),
        ]));

        self::assertSame('Goldtooth is in Jasperlode Mine, east of Goldshire.', $tool->ask('Where is Goldtooth?'));
    }

    public function testItReadsAPlainAnswerToo(): void
    {
        $tool = new ArchivistTool($this->archivistReturning('Hogger is in Elwynn Forest.'));

        self::assertSame('Hogger is in Elwynn Forest.', $tool->ask('Where is Hogger?'));
    }

    /**
     * A tool that throws loses the companion's whole reply. Saying "the archives had nothing"
     * leaves them something to say, which is what they already do well.
     */
    public function testAFailureIsAnAnswerRatherThanAnException(): void
    {
        $agent = $this->createMock(AgentInterface::class);
        $agent->method('call')->willThrowException(new \RuntimeException('no platform'));

        self::assertStringContainsString('did not answer', (new ArchivistTool($agent))->ask('anything'));
    }

    public function testNothingFoundIsSaidPlainly(): void
    {
        self::assertStringContainsString('nothing on that', (new ArchivistTool($this->archivistReturning('')))->ask('?'));
    }

    private function archivistReturning(mixed $content): AgentInterface
    {
        $inner = $this->createStub(ResultInterface::class);
        $inner->method('getContent')->willReturn($content);

        $result = $this->createStub(ExecutionInterface::class);
        $result->method('getResult')->willReturn($inner);

        $agent = $this->createStub(AgentInterface::class);
        $agent->method('call')->willReturn($result);

        return $agent;
    }
}
