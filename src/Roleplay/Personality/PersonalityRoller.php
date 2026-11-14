<?php

declare(strict_types=1);

namespace App\Roleplay\Personality;

use App\World\Model\Actor;
use App\World\Model\CharacterClass;
use App\World\Model\CharacterRace;

/**
 * Rolls a companion a personality the first time you meet them.
 *
 * Race and class *lean* rather than decide. Listing a race's fixed temperament would make every
 * night elf the same person, which is both duller and closer to the thing fantasy settings are
 * routinely criticised for; weighted leanings give you a world where most night elves are formal
 * and proud and some are not, which is how peoples actually work.
 *
 * Gender is deliberately absent. Race and class are invented and nobody is harmed by saying orcs
 * are blunt, but "women are warmer, men are blunter" is a real stereotype in a tabard, and the
 * per-individual roll already supplies all the variety it would have bought.
 *
 * The roll is seeded by character guid, so a given bot is the same person every time you meet
 * them even before anything is written down.
 */
final readonly class PersonalityRoller
{
    /**
     * Leanings by race: each axis lists the values that race tends toward, and a value may be
     * repeated to weight it further.
     *
     * @var array<string, array{warmth: Warmth[], manner: Manner[], humour: Humour[], pride: Pride[]}>
     */
    private const RACES = [
        'NightElf' => [
            'warmth' => [Warmth::Reserved, Warmth::Reserved, Warmth::Cold, Warmth::Amiable],
            'manner' => [Manner::Formal, Manner::Archaic, Manner::Archaic, Manner::Plain],
            'humour' => [Humour::Dry, Humour::Grave, Humour::Grave, Humour::Sardonic],
            'pride' => [Pride::Proud, Pride::Proud, Pride::Assured, Pride::Humble],
        ],
        'Human' => [
            'warmth' => [Warmth::Amiable, Warmth::Amiable, Warmth::Warm, Warmth::Reserved],
            'manner' => [Manner::Plain, Manner::Plain, Manner::Formal, Manner::Blunt],
            'humour' => [Humour::Dry, Humour::Playful, Humour::Grave, Humour::Sardonic],
            'pride' => [Pride::Assured, Pride::Humble, Pride::Proud, Pride::Boastful],
        ],
        'Dwarf' => [
            'warmth' => [Warmth::Warm, Warmth::Warm, Warmth::Amiable, Warmth::Reserved],
            'manner' => [Manner::Blunt, Manner::Blunt, Manner::Plain, Manner::Plain],
            'humour' => [Humour::Playful, Humour::Playful, Humour::Dry, Humour::Sardonic],
            'pride' => [Pride::Boastful, Pride::Assured, Pride::Proud, Pride::Humble],
        ],
        'Gnome' => [
            'warmth' => [Warmth::Amiable, Warmth::Warm, Warmth::Amiable, Warmth::Reserved],
            'manner' => [Manner::Plain, Manner::Plain, Manner::Blunt, Manner::Formal],
            'humour' => [Humour::Playful, Humour::Playful, Humour::Dry, Humour::Sardonic],
            'pride' => [Pride::Assured, Pride::Boastful, Pride::Proud, Pride::Humble],
        ],
        'Draenei' => [
            'warmth' => [Warmth::Warm, Warmth::Amiable, Warmth::Reserved, Warmth::Warm],
            'manner' => [Manner::Formal, Manner::Formal, Manner::Archaic, Manner::Plain],
            'humour' => [Humour::Grave, Humour::Dry, Humour::Grave, Humour::Playful],
            'pride' => [Pride::Humble, Pride::Humble, Pride::Assured, Pride::Proud],
        ],
        'Orc' => [
            'warmth' => [Warmth::Reserved, Warmth::Amiable, Warmth::Reserved, Warmth::Warm],
            'manner' => [Manner::Blunt, Manner::Blunt, Manner::Plain, Manner::Formal],
            'humour' => [Humour::Grave, Humour::Dry, Humour::Sardonic, Humour::Playful],
            'pride' => [Pride::Proud, Pride::Proud, Pride::Assured, Pride::Humble],
        ],
        'Undead' => [
            // The Forsaken are blunt and morbid, which is characterisation; what they are not is
            // cruel to the living person asking them for help.
            'warmth' => [Warmth::Cold, Warmth::Cold, Warmth::Reserved, Warmth::Amiable],
            'manner' => [Manner::Blunt, Manner::Blunt, Manner::Formal, Manner::Archaic],
            'humour' => [Humour::Sardonic, Humour::Sardonic, Humour::Grave, Humour::Dry],
            'pride' => [Pride::Proud, Pride::Assured, Pride::Humble, Pride::Boastful],
        ],
        'Tauren' => [
            'warmth' => [Warmth::Warm, Warmth::Warm, Warmth::Amiable, Warmth::Reserved],
            'manner' => [Manner::Formal, Manner::Archaic, Manner::Plain, Manner::Plain],
            'humour' => [Humour::Grave, Humour::Dry, Humour::Playful, Humour::Grave],
            'pride' => [Pride::Humble, Pride::Humble, Pride::Assured, Pride::Proud],
        ],
        'Troll' => [
            'warmth' => [Warmth::Amiable, Warmth::Reserved, Warmth::Warm, Warmth::Cold],
            'manner' => [Manner::Plain, Manner::Blunt, Manner::Archaic, Manner::Plain],
            'humour' => [Humour::Playful, Humour::Dry, Humour::Sardonic, Humour::Playful],
            'pride' => [Pride::Assured, Pride::Proud, Pride::Humble, Pride::Boastful],
        ],
        'BloodElf' => [
            'warmth' => [Warmth::Cold, Warmth::Reserved, Warmth::Reserved, Warmth::Amiable],
            'manner' => [Manner::Formal, Manner::Formal, Manner::Archaic, Manner::Blunt],
            'humour' => [Humour::Sardonic, Humour::Dry, Humour::Sardonic, Humour::Grave],
            'pride' => [Pride::Proud, Pride::Proud, Pride::Boastful, Pride::Assured],
        ],
    ];

    /**
     * Class nudges one axis each, applied on top of the race roll — a priest is gentler than
     * their people, a warlock colder. Only one axis so a class never overwrites a character.
     *
     * @var array<string, array{axis: string, value: Warmth|Manner|Humour|Pride}>
     */
    private const CLASSES = [
        'Warrior' => ['axis' => 'manner', 'value' => Manner::Blunt],
        'Paladin' => ['axis' => 'manner', 'value' => Manner::Formal],
        'Hunter' => ['axis' => 'humour', 'value' => Humour::Dry],
        'Rogue' => ['axis' => 'humour', 'value' => Humour::Sardonic],
        'Priest' => ['axis' => 'warmth', 'value' => Warmth::Warm],
        'DeathKnight' => ['axis' => 'warmth', 'value' => Warmth::Cold],
        'Shaman' => ['axis' => 'pride', 'value' => Pride::Humble],
        'Mage' => ['axis' => 'pride', 'value' => Pride::Proud],
        'Warlock' => ['axis' => 'warmth', 'value' => Warmth::Cold],
        'Monk' => ['axis' => 'pride', 'value' => Pride::Humble],
        'Druid' => ['axis' => 'manner', 'value' => Manner::Archaic],
    ];

    /**
     * Habits that belong to a people, from the setting's own detail.
     *
     * These do far more work than the generic list below, because they are specific and true:
     * the Forsaken really are preoccupied with perfumes and embalming oils, being painfully
     * aware of what they smell like to the living. A companion with one concrete preoccupation
     * reads as a person; one with a tasteful blend of adjectives reads as a character sheet.
     *
     * @var array<string, string[]>
     */
    private const RACE_QUIRKS = [
        'Undead' => [
            'Fuss about your perfumes and embalming oils, and worry aloud about what the living smell when you stand close.',
            'Report, without self-pity, which parts of you are holding up and which are not.',
            'Recall being alive in flashes — a kitchen, a name — at odd moments.',
            'Find the squeamishness of the living funny, and never say so out loud.',
        ],
        'NightElf' => [
            'Complain about the hour whenever it is daylight.',
            'Mention the moon and what phase it is in.',
            'Speak of decades as though they were weeks.',
            'Refer to how things were done before your people began to die of age.',
        ],
        'Dwarf' => [
            'Rank every tavern you pass by its ale.',
            'Remark on worked stone: who cut it, and how badly.',
            'Insist the Titans left something buried nearby, and say you intend to find it.',
        ],
        'Gnome' => [
            'Give exact measurements for things nobody asked you to measure.',
            'Mention the thing you are building, which is in your pocket.',
            'Speak of Gnomeregan as a problem still to be solved, not a place that was lost.',
        ],
        'Draenei' => [
            'Give distances the old way and then correct yourself.',
            'Refer to people and places that have been gone for generations.',
            'Give thanks aloud for small things, briefly, out of habit.',
        ],
        'Orc' => [
            'Hold people to their exact words, and keep yours exactly.',
            'Change the subject when the demon blood comes up.',
            'Compare this sky to Draenor\'s red one.',
        ],
        'Tauren' => [
            'Name the ancestors quietly before anything difficult.',
            'Object aloud to waste of any kind.',
            'Answer as though the question will still matter in a hundred years.',
        ],
        'Troll' => [
            'Read omens into ordinary things and act on them.',
            'Touch your charm and mention it before trouble.',
            'Speak of the loa as neighbours rather than gods.',
        ],
        'BloodElf' => [
            'Deflect any question about your appetite for magic.',
            'Speak of the Sunwell as others speak of a death in the family.',
            'Remark on manners — yours, and other people\'s.',
        ],
        'Human' => [
            'Mention someone you know in whatever town comes up.',
            'Give your opinion of the nobility, freely.',
            'Speak of Lordaeron as somewhere people still live.',
        ],
    ];

    /**
     * Small habits that belong to nobody in particular, rolled when a race has none left to give
     * or simply by chance, so two companions of the same people are still different.
     *
     * @var string[]
     */
    private const QUIRKS = [
        'Mention the weather more than anyone needs you to.',
        'Mention, now and then, that you are hungry.',
        'Refer to your weapon by the name you have given it.',
        'Say so when magic is involved that you did not see cast.',
        'Count things out loud.',
        'Give your opinion on which roads are safe, whether or not it was asked for.',
        'Mention your dislike of starting a fight before midday.',
        'Quote something your old teacher used to say.',
        'Point out birds.',
        'Complain about money someone back home still owes you.',
        'Ask what people are carrying.',
        'Start stories and trail off before the end.',
    ];

    /**
     * How one bot is, every time you meet them.
     *
     * Seeded by guid rather than randomly: a companion whose temperament changed between
     * conversations would be a different person each time, which is worse than having none.
     */
    public function roll(Actor $bot): Personality
    {
        $seed = crc32('anm.personality.'.$bot->guid);
        $pick = static function (array $options) use (&$seed): mixed {
            // A tiny LCG. We need the same answer on every machine, and PHP's shuffle/rand are
            // not promised to be stable across versions.
            //
            // Taking the *high* bits is not fussiness: an LCG's low bits cycle with a very short
            // period, and with four options `% 4` reads exactly those. The first version of this
            // rolled four identical night elves out of eight consecutive guids.
            $seed = ($seed * 1103515245 + 12345) & 0x7FFFFFFF;

            return $options[($seed >> 16) % \count($options)];
        };

        $leaning = self::RACES[$bot->race->name] ?? self::RACES['Human'];

        $rolled = [
            'warmth' => $pick($leaning['warmth']),
            'manner' => $pick($leaning['manner']),
            'humour' => $pick($leaning['humour']),
            'pride' => $pick($leaning['pride']),
        ];

        // A quirk of their people most of the time; a quirk of their own the rest, so the race
        // flavours the world without every Forsaken fussing about the same bottle.
        $raceQuirks = self::RACE_QUIRKS[$bot->race->name] ?? [];
        $quirk = ([] !== $raceQuirks && $pick([0, 1, 2]) > 0)
            ? $pick($raceQuirks)
            : $pick(self::QUIRKS);

        // The class nudge lands about half the time, so a trade shapes a character without
        // flattening every priest into the same warm, gentle person.
        $nudge = self::CLASSES[$bot->class->name] ?? null;

        if (null !== $nudge && 0 === $pick([0, 1])) {
            $rolled[$nudge['axis']] = $nudge['value'];
        }

        return new Personality($rolled['warmth'], $rolled['manner'], $rolled['humour'], $rolled['pride'], $quirk);
    }

    /**
     * Every race the matrix knows, for tests and for anyone adding one.
     *
     * @return string[]
     */
    public static function knownRaces(): array
    {
        return array_keys(self::RACES);
    }

    public static function raceIsKnown(CharacterRace $race): bool
    {
        return isset(self::RACES[$race->name]);
    }

    public static function classIsKnown(CharacterClass $class): bool
    {
        return isset(self::CLASSES[$class->name]);
    }
}
