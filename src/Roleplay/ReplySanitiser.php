<?php

declare(strict_types=1);

namespace App\Roleplay;

/**
 * Decides whether what the model produced is fit to say out loud, and tidies it if so.
 *
 * This exists because a bot once said this in a player's party chat:
 *
 *     {"name": "lookup_object", "parameters": {"name":"The Emerald Dreamcatcher"}}
 *
 * A small model, handed tools, will sometimes emit a tool call as plain text instead of using
 * the protocol — and invent a tool name while doing it. Whatever we do about tool support, the
 * last thing between a model and a player's screen should refuse to pass that through. Silence
 * is always better than breaking the fiction.
 */
final readonly class ReplySanitiser
{
    /**
     * WoW's chat line limit, in bytes. Anything longer is silently dropped by the client, so a
     * reply has to be cut before it leaves here.
     *
     * Bytes, not characters: an em-dash is three of them. Counting characters let a reply
     * full of dashes and accents through at 270 bytes, and the module then cut it again by
     * byte, through the middle of a character.
     */
    private const MAX_CHAT_BYTES = 255;

    private const ELLIPSIS = '…';

    /**
     * Anything a person would never say in a tavern.
     */
    private const MACHINE_MARKERS = [
        '"parameters"',
        '"arguments"',
        '"function"',
        '"tool_call"',
        '<tool_call>',
        '<|python_tag|>',
        '```',
    ];

    /**
     * @return string|null null when the output is not speech at all and the bot should stay quiet
     */
    public function sanitise(string $raw): ?string
    {
        $reply = trim($raw);

        if ('' === $reply) {
            return null;
        }

        if (self::looksMechanical($reply)) {
            return null;
        }

        // Narration in asterisks and wrapping quotes survive even an explicit instruction not to
        // use them, so they are stripped rather than re-prompted for.
        $reply = trim(preg_replace('/\*[^*]*\*/', '', $reply) ?? $reply);

        if (\strlen($reply) > 1 && str_starts_with($reply, '"') && str_ends_with($reply, '"')) {
            $reply = trim(substr($reply, 1, -1));
        }

        $reply = trim($reply);

        if ('' === $reply) {
            return null;
        }

        if (\strlen($reply) <= self::MAX_CHAT_BYTES) {
            return $reply;
        }

        // Prefer to end on a finished sentence. A word-boundary cut leaves things like "work
        // fit for a priest,…" in party chat, which reads as the bot being interrupted rather
        // than as someone who stopped talking.
        $wholeSentences = self::upToLastSentence(self::fitting($reply, self::MAX_CHAT_BYTES));

        if (null !== $wholeSentences) {
            return $wholeSentences;
        }

        // No sentence ended in range — one very long clause. Cut at a word boundary, leaving
        // room for the ellipsis, which is itself three bytes.
        $room = self::fitting($reply, self::MAX_CHAT_BYTES - \strlen(self::ELLIPSIS));

        if (' ' !== ($reply[\strlen($room)] ?? ' ')) {
            $space = mb_strrpos($room, ' ');

            if (false !== $space && $space > 0) {
                $room = mb_substr($room, 0, $space);
            }
        }

        return rtrim($room).self::ELLIPSIS;
    }

    /**
     * The longest start of the text that fits in so many bytes without splitting a character.
     * `substr` would: "Die Prüfung" cut by bytes ends "Pr\xc3", which the client renders as
     * mojibake.
     */
    private static function fitting(string $text, int $bytes): string
    {
        return mb_strcut($text, 0, $bytes, 'UTF-8');
    }

    /**
     * Everything up to the last finished sentence, or null if none finished in time.
     *
     * Deliberately strict about what ends a sentence: a full stop inside "Mr." or "3.5" would
     * cut mid-thought, so the mark has to be followed by a space or be the very end.
     */
    private static function upToLastSentence(string $text): ?string
    {
        $end = null;

        foreach (['.', '!', '?'] as $mark) {
            $at = mb_strrpos($text, $mark);

            while (false !== $at) {
                $next = mb_substr($text, $at + 1, 1);

                if ('' === $next || ' ' === $next) {
                    $end = max($end ?? 0, $at + 1);
                    break;
                }

                $at = $at > 0 ? mb_strrpos(mb_substr($text, 0, $at), $mark) : false;
            }
        }

        // A single short sentence followed by a long one would leave a stub; below this it is
        // better to word-cut the whole thing than to answer in three words.
        if (null === $end || $end < 40) {
            return null;
        }

        return rtrim(mb_substr($text, 0, $end));
    }

    private static function looksMechanical(string $reply): bool
    {
        foreach (self::MACHINE_MARKERS as $marker) {
            if (str_contains($reply, $marker)) {
                return true;
            }
        }

        // A reply that is valid JSON is a tool call that escaped, not something a character said.
        if (str_starts_with($reply, '{') || str_starts_with($reply, '[')) {
            json_decode($reply, true);

            if (\JSON_ERROR_NONE === json_last_error()) {
                return true;
            }
        }

        return false;
    }
}
