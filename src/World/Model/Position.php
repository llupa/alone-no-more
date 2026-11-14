<?php

declare(strict_types=1);

namespace App\World\Model;

use App\World\Exception\WorldGatewayException;

/**
 * A point in the world, as reported by the playerbots command server or by
 * `playerbots inspect <bot> position` over SOAP. Both speak the same wire format:
 *
 *     -9464.75 62.17 56 0 0.6 |Elwynn Forest|
 *     x        y     z  map o  zone
 *
 * The zone name is optional: the server omits it when the area id resolves to nothing.
 */
final readonly class Position
{
    public function __construct(
        public float $x,
        public float $y,
        public float $z,
        public int $mapId,
        public float $orientation = 0.0,
        public ?string $zone = null,
    ) {
    }

    public static function fromProtocolLine(string $line): self
    {
        $line = trim($line);

        $zone = null;
        if (preg_match('/\|([^|]*)\|\s*$/', $line, $m)) {
            $zone = '' !== $m[1] ? $m[1] : null;
            $line = trim(substr($line, 0, -\strlen($m[0])));
        }

        $parts = preg_split('/\s+/', $line, -1, \PREG_SPLIT_NO_EMPTY) ?: [];

        if (\count($parts) < 4) {
            throw new WorldGatewayException(\sprintf('Malformed position line: "%s".', $line));
        }

        foreach (\array_slice($parts, 0, 5) as $part) {
            if (!is_numeric($part)) {
                throw new WorldGatewayException(\sprintf('Malformed position line: "%s".', $line));
            }
        }

        return new self(
            (float) $parts[0],
            (float) $parts[1],
            (float) $parts[2],
            (int) $parts[3],
            isset($parts[4]) ? (float) $parts[4] : 0.0,
            $zone,
        );
    }

    /**
     * Flat distance to another point, ignoring height. Returns null across different maps,
     * where a distance is meaningless: Elwynn Forest and Orgrimmar share a coordinate space
     * but not a map.
     */
    public function distanceTo(self $other): ?float
    {
        if ($this->mapId !== $other->mapId) {
            return null;
        }

        return hypot($this->x - $other->x, $this->y - $other->y);
    }

    public function equals(self $other): bool
    {
        return $this->mapId === $other->mapId
            && abs($this->x - $other->x) < 0.01
            && abs($this->y - $other->y) < 0.01
            && abs($this->z - $other->z) < 0.01;
    }

    public function __toString(): string
    {
        return \sprintf(
            '%.2f %.2f %.2f map=%d%s',
            $this->x, $this->y, $this->z, $this->mapId,
            null !== $this->zone ? ' ('.$this->zone.')' : '',
        );
    }
}
