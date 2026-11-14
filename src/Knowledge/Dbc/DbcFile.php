<?php

declare(strict_types=1);

namespace App\Knowledge\Dbc;

/**
 * Reader for Blizzard's WDBC files, the client-side data tables for 3.3.5a.
 *
 * The format is deliberately simple: a 20-byte header, then `recordCount` fixed-width records of
 * `fieldCount` 4-byte fields, then one string block. Text fields hold an offset into that block
 * rather than the text itself.
 *
 * We read these because some facts only exist client-side. The server stores `characters.zone`
 * as a number and has no table of zone names — which is why a bot could say "Teldrassil" only by
 * asking the playerbots command server, a network round trip per reply, and could never say
 * "Shadowglen" at all.
 */
final class DbcFile
{
    private const HEADER_SIZE = 20;
    private const FIELD_SIZE = 4;
    private const MAGIC = 'WDBC';

    /** @var resource */
    private $handle;

    private int $recordCount;
    private int $fieldCount;
    private int $recordSize;
    private int $stringBlockOffset;
    private int $stringBlockSize;

    /**
     * The whole string block, held in memory.
     *
     * Read up front rather than seeking per lookup: callers resolve strings *while* iterating
     * records, and seeking on the shared handle silently destroys the sequential read. (It did:
     * the first version threw "Truncated record 241" because resolving a name moved the file
     * pointer out from under the generator.) The block is tens of kilobytes, so this is cheap.
     */
    private string $stringBlock = '';

    public function __construct(private readonly string $path)
    {
        if (!is_readable($path)) {
            throw new DbcException(\sprintf('Cannot read "%s". DBC files come from the client data (wowgaming/client-data); see the README.', $path));
        }

        $handle = fopen($path, 'rb');

        if (false === $handle) {
            throw new DbcException(\sprintf('Cannot open "%s".', $path));
        }

        $this->handle = $handle;
        $this->readHeader();
    }

    public function __destruct()
    {
        if (\is_resource($this->handle)) {
            fclose($this->handle);
        }
    }

    public function recordCount(): int
    {
        return $this->recordCount;
    }

    public function fieldCount(): int
    {
        return $this->fieldCount;
    }

    /**
     * Every record, as arrays of raw 32-bit unsigned fields.
     *
     * Yielded rather than returned: AreaTable is 2307 records of 36 fields and callers normally
     * want to fold it into a small map, not hold it all.
     *
     * @return \Generator<int, array<int, int>>
     */
    public function records(): \Generator
    {
        if ($this->recordSize < 1) {
            throw new DbcException(\sprintf('"%s" declares a record size of %d.', $this->path, $this->recordSize));
        }

        fseek($this->handle, self::HEADER_SIZE);

        for ($i = 0; $i < $this->recordCount; ++$i) {
            $raw = fread($this->handle, $this->recordSize);

            if (false === $raw || \strlen($raw) !== $this->recordSize) {
                throw new DbcException(\sprintf('Truncated record %d in "%s".', $i, $this->path));
            }

            /** @var array<int, int> $fields */
            $fields = array_values(unpack('V*', $raw) ?: []);

            yield $i => $fields;
        }
    }

    /**
     * Resolve a string field. The value stored in a record is a byte offset into the string
     * block; 0 means "no string", which is not the same as an empty one.
     */
    public function string(int $offset): string
    {
        if ($offset <= 0 || $offset >= \strlen($this->stringBlock)) {
            return '';
        }

        $end = strpos($this->stringBlock, "\0", $offset);

        return false === $end
            ? substr($this->stringBlock, $offset)
            : substr($this->stringBlock, $offset, $end - $offset);
    }

    private function readHeader(): void
    {
        $header = fread($this->handle, self::HEADER_SIZE);

        if (false === $header || self::HEADER_SIZE !== \strlen($header)) {
            throw new DbcException(\sprintf('"%s" is too short to be a DBC file.', $this->path));
        }

        $parsed = unpack('a4magic/Vrecords/Vfields/VrecordSize/VstringSize', $header);

        if (false === $parsed || self::MAGIC !== $parsed['magic']) {
            throw new DbcException(\sprintf('"%s" is not a WDBC file.', $this->path));
        }

        $this->recordCount = $parsed['records'];
        $this->fieldCount = $parsed['fields'];
        $this->recordSize = $parsed['recordSize'];
        $this->stringBlockSize = $parsed['stringSize'];
        $this->stringBlockOffset = self::HEADER_SIZE + ($this->recordCount * $this->recordSize);

        fseek($this->handle, $this->stringBlockOffset);
        $block = $this->stringBlockSize > 0 ? fread($this->handle, $this->stringBlockSize) : '';
        $this->stringBlock = false !== $block ? $block : '';

        if ($this->recordSize !== $this->fieldCount * self::FIELD_SIZE) {
            throw new DbcException(\sprintf('"%s" has %d fields but a record size of %d; expected %d. This reader only handles fixed 4-byte fields.', $this->path, $this->fieldCount, $this->recordSize, $this->fieldCount * self::FIELD_SIZE));
        }
    }
}
