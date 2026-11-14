<?php

declare(strict_types=1);

namespace App\Knowledge;

/**
 * Pulls the names of things out of ordinary sentences.
 *
 * A player will shift-click a quest sometimes and simply type its name the rest of the time —
 * "where can I find Sapphire of Sky?" was answered with "never heard of her" while the item sat
 * in our own database. Links are a primary key and need no guessing; a typed name has to be
 * spotted first.
 *
 * The heuristic is capitalisation, which in this game is reliable: quests, items and people are
 * Title Case and ordinary words are not. We only consider runs of two or more capitalised words,
 * because single ones are mostly sentence openings and names of people we have no record of.
 */
final class ProperNouns
{
    /**
     * Words that are capitalised for grammar rather than because they name something.
     */
    private const SENTENCE_WORDS = [
        'I', 'The', 'A', 'An', 'My', 'Your', 'We', 'You', 'It', 'This', 'That', 'There',
        'Where', 'What', 'When', 'Who', 'How', 'Why', 'Can', 'Do', 'Did', 'Is', 'Are',
        'Let', 'Have', 'Has', 'Need', 'Want', 'Go', 'Come',
    ];

    /**
     * @return string[] candidate names, longest first so the most specific is tried before a
     *                  fragment of it
     */
    public static function from(string $message): array
    {
        // Link markup is handled elsewhere and its label would otherwise be found twice.
        $text = GameLink::strip($message);

        if (!preg_match_all('/\b([A-Z][a-z\']+(?:\s+(?:of|the|de|in)\s+[A-Z][a-z\']+|\s+[A-Z][a-z\']+)+)/u', $text, $m)) {
            return [];
        }

        $found = [];

        foreach ($m[1] as $phrase) {
            $phrase = trim($phrase);
            $firstWord = strtok($phrase, ' ');

            // "Can you find Sapphire of Sky" would otherwise yield "Can you".
            if (\in_array($firstWord, self::SENTENCE_WORDS, true)) {
                $phrase = trim(substr($phrase, \strlen((string) $firstWord)));
            }

            if (str_word_count($phrase) >= 2) {
                $found[strtolower($phrase)] = $phrase;
            }
        }

        $phrases = array_values($found);
        usort($phrases, static fn (string $a, string $b): int => \strlen($b) <=> \strlen($a));

        return $phrases;
    }
}
