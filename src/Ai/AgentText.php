<?php

declare(strict_types=1);

namespace App\Ai;

use Symfony\AI\Platform\Result\TextResult;

/**
 * The words an agent actually said.
 *
 * `getContent()` returns a plain string on a simple reply, but an array of result objects once
 * thinking is on — a `ThinkingResult` carrying an empty string and an opaque signature, followed
 * by the `TextResult` that holds the answer. Both shapes come back from the same call depending
 * on whether the model reasoned, so anything reading a reply has to handle both.
 *
 * Getting this wrong is not subtle: passing the array where a string is expected is a TypeError
 * on the first message, which is how it was found.
 */
final class AgentText
{
    public static function of(mixed $content): string
    {
        if (\is_string($content)) {
            return trim($content);
        }

        if (!\is_array($content)) {
            return '';
        }

        $spoken = [];

        foreach ($content as $part) {
            // Thinking blocks carry no text worth saying — their content is empty by default and
            // the reasoning itself is never returned.
            if ($part instanceof TextResult) {
                $spoken[] = $part->getContent();

                continue;
            }

            if (\is_string($part)) {
                $spoken[] = $part;
            }
        }

        return trim(implode(' ', array_filter($spoken)));
    }
}
