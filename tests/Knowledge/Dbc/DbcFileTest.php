<?php

declare(strict_types=1);

namespace App\Tests\Knowledge\Dbc;

use App\Knowledge\Dbc\DbcException;
use App\Knowledge\Dbc\DbcFile;
use PHPUnit\Framework\TestCase;

/**
 * The reader is tested against a DBC built in memory, so these run anywhere — no client data,
 * no WoW install.
 */
final class DbcFileTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'dbc');
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    /**
     * @param array<int, array<int, int>> $records
     */
    private function writeDbc(array $records, int $fieldCount, string $stringBlock): void
    {
        $body = '';

        foreach ($records as $fields) {
            foreach ($fields as $value) {
                $body .= pack('V', $value);
            }
        }

        file_put_contents($this->path, 'WDBC'
            .pack('V', \count($records))
            .pack('V', $fieldCount)
            .pack('V', $fieldCount * 4)
            .pack('V', \strlen($stringBlock))
            .$body
            .$stringBlock);
    }

    public function testItReadsRecordsAndFields(): void
    {
        $this->writeDbc([[1, 10], [2, 20]], 2, "\0");

        $dbc = new DbcFile($this->path);

        self::assertSame(2, $dbc->recordCount());
        self::assertSame(2, $dbc->fieldCount());
        self::assertSame([[1, 10], [2, 20]], iterator_to_array($dbc->records(), false));
    }

    /**
     * Regression: the first version seeked on the shared handle to resolve a string, which
     * silently destroyed the sequential read and threw "Truncated record 241" partway through
     * a real file. Resolving strings mid-iteration must be safe.
     */
    public function testResolvingStringsWhileIteratingDoesNotDisturbTheRead(): void
    {
        $block = "\0Teldrassil\0Shadowglen\0";
        $this->writeDbc([[1, 1], [2, 12], [3, 1]], 2, $block);

        $dbc = new DbcFile($this->path);
        $seen = [];

        foreach ($dbc->records() as $fields) {
            $seen[] = $dbc->string($fields[1]);
        }

        self::assertSame(['Teldrassil', 'Shadowglen', 'Teldrassil'], $seen);
    }

    public function testOffsetZeroMeansNoStringRatherThanTheFirstOne(): void
    {
        $this->writeDbc([[1, 0]], 2, "\0Teldrassil\0");

        self::assertSame('', (new DbcFile($this->path))->string(0));
    }

    public function testItRejectsSomethingThatIsNotADbc(): void
    {
        file_put_contents($this->path, 'NOPE'.str_repeat("\0", 32));

        $this->expectException(DbcException::class);
        $this->expectExceptionMessage('not a WDBC file');

        new DbcFile($this->path);
    }

    public function testItRejectsAFileTooShortToHaveAHeader(): void
    {
        file_put_contents($this->path, 'WDBC');

        $this->expectException(DbcException::class);

        new DbcFile($this->path);
    }

    public function testItRejectsVariableWidthFields(): void
    {
        file_put_contents($this->path, 'WDBC'.pack('V', 1).pack('V', 2).pack('V', 99).pack('V', 0));

        $this->expectException(DbcException::class);
        $this->expectExceptionMessage('fixed 4-byte fields');

        new DbcFile($this->path);
    }
}
