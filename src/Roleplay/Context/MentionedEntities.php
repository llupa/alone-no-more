<?php

declare(strict_types=1);

namespace App\Roleplay\Context;

use App\Knowledge\GameLink;

/**
 * Things the prompt told the bot about, collected so the reply can be turned back into real
 * links afterwards.
 *
 * Bots could not link anything before this: a model will not reliably produce
 * `|cffffff00|Hquest:932:7|h[Twisted Hatred]|h|r` — ten colon-separated fields and an alpha
 * channel — and if it guesses an id wrong the player gets a link to the wrong thing, which is
 * worse than plain text.
 *
 * So the model writes prose, naming things in plain words, and we linkify afterwards using ids
 * we already know are correct because we put them in the prompt. Mutable by design: the
 * providers fill it while the prompt is being built.
 */
final class MentionedEntities
{
    /** @var array<string, string> plain name => link markup */
    private array $links = [];

    public function item(int $id, string $name, int $quality, int $speakerLevel): void
    {
        if ('' !== $name) {
            $this->links[$name] = GameLink::toItemLink($id, $name, $quality, $speakerLevel);
        }
    }

    public function quest(int $id, string $title, int $level): void
    {
        if ('' !== $title) {
            $this->links[$title] = GameLink::toQuestLink($id, $title, $level);
        }
    }

    /**
     * Replace plain mentions with real links.
     *
     * Longest names first, so "Twisted Hatred" is matched before a shorter name that happens to
     * be a substring of it. Anything already linked is left alone — the player may have linked
     * it themselves and the bot simply echoed it back.
     */
    public function linkify(string $reply): string
    {
        if ([] === $this->links) {
            return $reply;
        }

        $names = array_keys($this->links);
        usort($names, static fn (string $a, string $b): int => \strlen($b) <=> \strlen($a));

        foreach ($names as $name) {
            // Skip when this name is already inside link markup.
            if (preg_match('/\|h\['.preg_quote($name, '/').'\]\|h/i', $reply)) {
                continue;
            }

            $reply = preg_replace(
                '/(?<![\w\[])'.preg_quote($name, '/').'(?![\w\]])/i',
                str_replace('$', '\\$', $this->links[$name]),
                $reply,
                1,
            ) ?? $reply;
        }

        return $reply;
    }

    public function isEmpty(): bool
    {
        return [] === $this->links;
    }
}
