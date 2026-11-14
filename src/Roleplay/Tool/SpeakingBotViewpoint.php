<?php

declare(strict_types=1);

namespace App\Roleplay\Tool;

use App\Knowledge\Viewpoint;
use App\World\Model\Position;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * The archives, answered from where the speaking companion stands.
 *
 * The bot is with the player, so its position is the right frame of reference for "how far is
 * it from here". Outside a conversation — the MCP server, `anm:ask` — there is no bot and no
 * here, and the archives fall back to naming the zone.
 */
#[AsAlias(Viewpoint::class)]
final readonly class SpeakingBotViewpoint implements Viewpoint
{
    public function __construct(private BotCommandContext $context)
    {
    }

    public function position(): ?Position
    {
        return $this->context->isActive() ? $this->context->bot()->position : null;
    }
}
