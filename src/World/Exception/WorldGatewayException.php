<?php

declare(strict_types=1);

namespace App\World\Exception;

/**
 * Thrown when the game server cannot be reached, or answers something we cannot parse.
 */
class WorldGatewayException extends \RuntimeException
{
}
