<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Director\CompanionRequests;
use App\Herding\BotRoster;
use App\World\Model\CharacterClass;
use App\World\Model\CharacterRace;
use App\World\Model\Gender;
use App\World\WorldGatewayInterface;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;

/**
 * Asking, in advance, for someone particular to be there.
 *
 * The herder fills a zone with level-appropriate strangers, which is right for making a world
 * feel inhabited and no help at all when what you want is a healer. This is the other half: name
 * a calling, get a name back, and find them waiting when you log in.
 *
 * It is the clearest thing MCP buys this project. "I'll play Doppel tonight and I'd like a priest
 * of my level" is a sentence, not a command — there is no console for it, and a dashboard with a
 * class dropdown would be a worse version of asking.
 */
final readonly class WorldCompanions
{
    public function __construct(
        private WorldGatewayInterface $world,
        private BotRoster $roster,
        private CompanionRequests $requests,
    ) {
    }

    #[McpTool(
        name: 'prepare_companion',
        description: <<<'TXT'
            Pick out a companion for a character to play with later and have them waiting next
            time that character logs in. Say which calling is wanted — priest, warrior, mage,
            rogue, druid, hunter, paladin, shaman, warlock, death knight — and optionally a race
            (night elf, human, dwarf, gnome, draenei, orc, troll, tauren, forsaken, blood elf)
            and a gender. A bot of the right level and faction is chosen and named now, so the
            player knows who to invite. Use for "I want to level with a healer tonight", "find me
            a tank for Doppel", "prepare a male night elf priest".
            TXT,
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false),
    )]
    public function prepare(
        string $forCharacter,
        string $calling = '',
        string $race = '',
        string $gender = '',
        int $levelTolerance = 2,
    ): string {
        $player = $this->world->findActor($forCharacter);

        if (null === $player) {
            return \sprintf('No character called "%s" exists on this server.', $forCharacter);
        }

        $class = self::callingNamed($calling);

        if ('' !== trim($calling) && null === $class) {
            return \sprintf(
                '"%s" is not a calling. Try one of: %s.',
                $calling,
                implode(', ', array_map(static fn (CharacterClass $c): string => strtolower($c->label()), CharacterClass::cases())),
            );
        }

        $wantedRace = self::raceNamed($race);

        if ('' !== trim($race) && null === $wantedRace) {
            return \sprintf(
                '"%s" is not a playable race. Try one of: %s.',
                $race,
                implode(', ', array_map(static fn (CharacterRace $r): string => strtolower($r->label()), CharacterRace::cases())),
            );
        }

        // A race on the wrong side could never group with them, and the request would quietly
        // produce someone unusable rather than saying so.
        if (null !== $wantedRace && $wantedRace->faction() !== $player->faction()) {
            return \sprintf(
                '%s are %s, and %s is %s. They could never group.',
                $wantedRace->label(),
                $wantedRace->faction()->value,
                $player->name,
                $player->faction()->value,
            );
        }

        $wantedGender = self::genderNamed($gender);

        if ('' !== trim($gender) && null === $wantedGender) {
            return \sprintf('"%s" is not a gender. Use male or female, or leave it out.', $gender);
        }

        // Same faction, or they could never group — which is the entire point of the request.
        $found = $this->roster->dormantOfClass(
            $player,
            $class?->value,
            $levelTolerance,
            $player->faction(),
            $wantedRace?->value,
            $wantedGender?->value,
        );

        if (null === $found) {
            return \sprintf(
                'Nobody dormant matches: a %s of about level %d on %s\'s side. Try a wider level range, or drop one of the details.',
                trim(implode(' ', array_filter([
                    $wantedGender?->label() ? strtolower($wantedGender->label()) : '',
                    $wantedRace?->label() ? strtolower($wantedRace->label()) : '',
                    '' === trim($calling) ? 'companion' : strtolower($calling),
                ]))),
                $player->level,
                $player->name,
            );
        }

        $wanted = '' === trim($calling) ? 'a companion' : strtolower($calling);
        $this->requests->add($player->guid, $player->name, $found['guid'], $found['name'], $wanted);

        return \sprintf(
            '%s, a level %d %s %s, will be brought online near %s and kept there. Invite them to the party when you log in.',
            $found['name'],
            $found['level'],
            CharacterRace::from($found['race'])->label(),
            strtolower(CharacterClass::from($found['class'])->label()),
            $player->name,
        );
    }

    #[McpTool(
        name: 'planned_companions',
        description: 'Who has been set aside to play with a character. Use for "who is waiting for me" or to check before asking for more.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    public function planned(string $forCharacter): string
    {
        $player = $this->world->findActor($forCharacter);

        if (null === $player) {
            return \sprintf('No character called "%s" exists on this server.', $forCharacter);
        }

        $waiting = $this->requests->forCharacter($player->guid);

        if ([] === $waiting) {
            return \sprintf('Nobody is set aside for %s.', $forCharacter);
        }

        return \sprintf('Waiting for %s: %s.', $forCharacter, implode(', ', array_map(
            static fn (array $r): string => \sprintf('%s (%s)', $r['bot_name'], $r['wanted']),
            $waiting,
        )));
    }

    #[McpTool(
        name: 'cancel_companion',
        description: 'Stop keeping a companion aside for a character. Pass their name, or "all" to clear the lot.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false),
    )]
    public function cancel(string $forCharacter, string $companionName): string
    {
        $player = $this->world->findActor($forCharacter);

        if (null === $player) {
            return \sprintf('No character called "%s" exists on this server.', $forCharacter);
        }

        if (0 === strcasecmp(trim($companionName), 'all')) {
            $gone = $this->requests->forgetAll($player->guid);

            return 0 === $gone
                ? \sprintf('Nobody was set aside for %s.', $forCharacter)
                : \sprintf('Cleared %d set aside for %s.', $gone, $forCharacter);
        }

        return $this->requests->forget($player->guid, $companionName)
            ? \sprintf('%s is no longer being kept for %s.', $companionName, $forCharacter)
            : \sprintf('%s was not being kept for %s.', $companionName, $forCharacter);
    }

    private static function raceNamed(string $race): ?CharacterRace
    {
        $wanted = strtolower(str_replace([' ', "'"], '', trim($race)));

        if ('' === $wanted) {
            return null;
        }

        foreach (CharacterRace::cases() as $case) {
            if (strtolower(str_replace(' ', '', $case->label())) === $wanted || strtolower($case->name) === $wanted) {
                return $case;
            }
        }

        // What people call them, rather than what the enum calls them.
        return match ($wanted) {
            'undead', 'forsaken' => CharacterRace::Undead,
            'belf', 'bloodelf' => CharacterRace::BloodElf,
            'nelf', 'nightelf' => CharacterRace::NightElf,
            default => null,
        };
    }

    private static function genderNamed(string $gender): ?Gender
    {
        return match (strtolower(trim($gender))) {
            'male', 'man', 'm' => Gender::Male,
            'female', 'woman', 'f' => Gender::Female,
            default => null,
        };
    }

    private static function callingNamed(string $calling): ?CharacterClass
    {
        $wanted = strtolower(trim($calling));

        if ('' === $wanted) {
            return null;
        }

        foreach (CharacterClass::cases() as $class) {
            if (strtolower($class->label()) === $wanted || strtolower($class->name) === str_replace(' ', '', $wanted)) {
                return $class;
            }
        }

        // The words people actually use.
        return match ($wanted) {
            'healer', 'priest' => CharacterClass::Priest,
            'tank' => CharacterClass::Warrior,
            'dk' => CharacterClass::DeathKnight,
            'lock' => CharacterClass::Warlock,
            default => null,
        };
    }
}
