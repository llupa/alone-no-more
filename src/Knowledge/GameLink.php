<?php

declare(strict_types=1);

namespace App\Knowledge;

/**
 * A thing the player shift-clicked into chat.
 *
 * The client sends links in its own markup, e.g.
 *   |cffffff00|Hquest:3521:4|h[Iverron's Antidote]|h|r
 *   |cff1eff00|Hitem:19019:0:0:0:0:0:0:0|h[Thunderfury]|h|r
 * The id in there is a primary key into our own world database, so a linked thing can be
 * answered exactly rather than guessed at from its name.
 */
final readonly class GameLink
{
    public function __construct(
        public string $type,
        public int $id,
        public string $label,
    ) {
    }

    /**
     * @return self[] every link found, in the order they appear
     */
    public static function parseAll(string $message): array
    {
        if (!preg_match_all('/\|H(?<type>[a-z]+):(?<id>\d+)[^|]*\|h\[(?<label>[^\]]*)\]\|h/i', $message, $matches, \PREG_SET_ORDER)) {
            return [];
        }

        return array_map(
            static fn (array $m): self => new self(strtolower($m['type']), (int) $m['id'], $m['label']),
            $matches,
        );
    }

    /**
     * Item quality to the colour the client paints its name, as seen in links the client itself
     * sends: |cff9d9d9d for a grey Recruit's Pants, |cffffffff for a white Timberling Seed.
     * The leading "ff" is the alpha channel.
     */
    private const QUALITY_COLOURS = [
        0 => '9d9d9d', // poor
        1 => 'ffffff', // common
        2 => '1eff00', // uncommon
        3 => '0070dd', // rare
        4 => 'a335ee', // epic
        5 => 'ff8000', // legendary
        6 => 'e6cc80', // artifact
        7 => 'e6cc80', // heirloom
    ];

    /**
     * Build an item link a player can shift-click, hover and link onward, exactly as the client
     * would. The trailing field is the level of whoever is speaking, which is what the client
     * puts there.
     */
    public static function toItemLink(int $id, string $name, int $quality, int $speakerLevel): string
    {
        $colour = self::QUALITY_COLOURS[$quality] ?? self::QUALITY_COLOURS[1];

        return \sprintf('|cff%s|Hitem:%d:0:0:0:0:0:0:0:%d|h[%s]|h|r', $colour, $id, $speakerLevel, $name);
    }

    /**
     * Quest links are always the same yellow, whatever the quest.
     */
    public static function toQuestLink(int $id, string $title, int $level): string
    {
        return \sprintf('|cffffff00|Hquest:%d:%d|h[%s]|h|r', $id, $level, $title);
    }

    /**
     * The message as a person reads it, with markup replaced by the plain names. An LLM handed
     * raw colour codes will sometimes echo them back into chat.
     */
    public static function strip(string $message): string
    {
        $plain = preg_replace('/\|c[0-9a-f]{8}\|H[^|]*\|h\[([^\]]*)\]\|h\|r/i', '$1', $message) ?? $message;
        $plain = preg_replace('/\|H[^|]*\|h\[([^\]]*)\]\|h/i', '$1', $plain) ?? $plain;
        $plain = preg_replace('/\|c[0-9a-f]{8}|\|r/i', '', $plain) ?? $plain;

        return trim($plain);
    }
}
