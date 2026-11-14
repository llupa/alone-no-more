<?php

declare(strict_types=1);

namespace App\Tests\Knowledge;

use App\Knowledge\Cities;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Where a companion cannot be walked.
 */
#[CoversClass(Cities::class)]
final class CitiesTest extends TestCase
{
    public function testACapitalIsACity(): void
    {
        self::assertTrue(Cities::isCity(1519));
        self::assertSame('Stormwind City', Cities::name(1519));
    }

    /**
     * Goldshire is a handful of buildings on open ground and leads perfectly well, so the list
     * is capitals and sanctuaries rather than "anywhere with a roof".
     */
    public function testOpenCountryIsNot(): void
    {
        self::assertFalse(Cities::isCity(12));
        self::assertNull(Cities::name(12));
    }

    /**
     * No zone is known for averaged spawn coordinates outside the world map, and an unknown
     * place must not be treated as a city — that would refuse ordinary walks.
     */
    public function testAnUnknownPlaceIsNotACity(): void
    {
        self::assertFalse(Cities::isCity(null));
        self::assertNull(Cities::name(null));
    }
}
