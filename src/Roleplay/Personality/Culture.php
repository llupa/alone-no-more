<?php

declare(strict_types=1);

namespace App\Roleplay\Personality;

use App\World\Model\CharacterRace;
use App\World\Model\Gender;

/**
 * Where a character stands among their own people.
 *
 * Deliberately separate from {@see Personality}, because it answers a different question and the
 * difference matters. Temperament is *how someone is*, and rolling it by gender would be a real
 * stereotype in a tabard — there is no reason a woman should be warmer or a man blunter, and the
 * per-character roll gives all the variety that would have bought.
 *
 * Standing is *what their society expects of them*, and that is a fact about the setting rather
 * than an assumption about people. Kaldorei society is matriarchal: the Sentinels are women, the
 * priesthood of Elune is led by women, and in this era druidism is overwhelmingly men's work.
 * A night elf who does not know that is not a night elf.
 *
 * Only cultures whose lore actually says something appear here. The absence of a race is a
 * statement: we have nothing to add beyond what Identity already gives them.
 */
final readonly class Culture
{
    /**
     * A line about standing, or null when the setting has nothing particular to say.
     */
    public static function noteFor(CharacterRace $race, Gender $gender): ?string
    {
        return match ($race) {
            CharacterRace::NightElf => self::kaldorei($gender),
            CharacterRace::Tauren => 'Defer to age and to the word of the chieftains, and say when others do not.',
            CharacterRace::Orc => 'Measure people by strength honestly won, and say so.',
            CharacterRace::Draenei => 'Weigh strangers slowly, as someone whose people have been exiles longer than most races have existed.',
            CharacterRace::Undead => 'Refer to having been alive, and refuse to discuss your death lightly with the living.',
            CharacterRace::BloodElf => self::quelDorei(),
            default => null,
        };
    }

    /**
     * Blood elf hauteur, written carefully on purpose.
     *
     * The obvious reading of the lore — a people who believe themselves the finest thing in
     * Azeroth — is the one trait in this whole file that could genuinely go wrong. Pride in your
     * own people is characterisation; believing other peoples inferior by birth is supremacism,
     * and it reads the same in a fantasy setting as it does anywhere else. A model asked to play
     * it will either soften it into nothing or play it, and the second is worse: nobody wants a
     * companion who sneers at them for what they are.
     *
     * What survives is both truer to Quel'Thalas and better company: exacting standards,
     * condescension about *conduct and taste* rather than blood, and a grief for something
     * genuinely lost. Looking down on how someone behaves is a character. Looking down on what
     * they were born as is not one worth writing.
     */
    private static function quelDorei(): string
    {
        return 'Hold people to exacting standards of bearing and craft, and remark when they fall short — '
            .'judge manners and workmanship, never anyone\'s birth or blood. '
            .'Speak of the Sunwell\'s loss as something that has not been replaced.';
    }

    /**
     * Kaldorei society is matriarchal, and the era decides the detail: the Sentinel army and the
     * priesthood of Elune are women's institutions, and until very recently the Cenarion druids
     * were men's.
     */
    private static function kaldorei(Gender $gender): string
    {
        return Gender::Female === $gender
            ? 'Expect to be heeded, as a daughter of a matriarchal people whose Sentinels and priesthood of Elune are led by women — '
                .'carry it without ever having to assert it.'
            : 'Defer to the sisterhood as a matter of course and never of grievance: yours is a matriarchal people, '
                .'the Sentinels and the priesthood of Elune are women\'s callings, and a man of your age looks to the druids and the hunt.';
    }
}
