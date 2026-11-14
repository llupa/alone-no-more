<?php

declare(strict_types=1);

namespace App\Tests\Roleplay\Personality;

use App\Roleplay\Personality\Culture;
use App\Roleplay\Personality\PersonalityRoller;
use App\Roleplay\Personality\Pride;
use App\World\Model\Actor;
use App\World\Model\CharacterClass;
use App\World\Model\CharacterRace;
use App\World\Model\Gender;
use App\World\Model\Position;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Giving companions a temperament, and the lines we decided not to cross doing it.
 */
#[CoversClass(PersonalityRoller::class)]
#[CoversClass(Culture::class)]
final class PersonalityRollerTest extends TestCase
{
    private function bot(
        int $guid,
        CharacterRace $race = CharacterRace::NightElf,
        CharacterClass $class = CharacterClass::Hunter,
        Gender $gender = Gender::Female,
    ): Actor {
        return new Actor(
            guid: $guid, name: 'Bot'.$guid, race: $race, class: $class, gender: $gender, level: 20,
            position: new Position(0, 0, 0, 1), zoneId: 141, isBot: true, isOnline: true,
        );
    }

    /**
     * A companion whose temperament changed between conversations would be a different person
     * every time you met them, which is worse than having no personality at all.
     */
    public function testACompanionIsTheSamePersonEveryTime(): void
    {
        $roller = new PersonalityRoller();

        self::assertSame(
            $roller->roll($this->bot(42))->label(),
            $roller->roll($this->bot(42))->label(),
        );
    }

    /**
     * The first version rolled four identical night elves out of eight consecutive guids,
     * because an LCG's low bits are exactly the ones `% 4` reads.
     */
    public function testAPeopleIsNotOnePersonRepeated(): void
    {
        $roller = new PersonalityRoller();
        $seen = [];

        for ($guid = 1; $guid <= 60; ++$guid) {
            $seen[$roller->roll($this->bot($guid))->label()] = true;
        }

        self::assertGreaterThan(10, \count($seen), 'sixty companions of one race should not be a handful of people');
    }

    #[DataProvider('everyRace')]
    public function testEveryPlayableRaceRollsSomething(CharacterRace $race): void
    {
        $personality = (new PersonalityRoller())->roll($this->bot(7, $race));

        self::assertNotSame('', trim($personality->adjectives()));
        self::assertNotEmpty($personality->instructions());

        // Every line must be an order, not a description: that is what gets enacted rather
        // than recited back at the player.
        foreach ($personality->instructions() as $instruction) {
            self::assertStringStartsNotWith('You are', $instruction);
            self::assertStringStartsNotWith('You have', $instruction);
        }
    }

    public static function everyRace(): iterable
    {
        foreach (CharacterRace::cases() as $race) {
            yield $race->name => [$race];
        }
    }

    /**
     * Pride is the axis that could turn a companion into someone unpleasant to travel with, so
     * it says out loud what it is not.
     */
    /**
     * Pride is the axis that could turn a companion into someone unpleasant to travel with, so
     * the instruction points it at standards rather than at people.
     */
    public function testPrideIsAboutStandardsNotContempt(): void
    {
        self::assertStringContainsString("people's standards", Pride::Proud->instruction());
    }

    /**
     * Every adjective is one word for the opening sentence. A clause here would turn the
     * character's name into a paragraph, which is what started the model reading its notes out.
     */
    public function testAdjectivesAreSingleWords(): void
    {
        $adjectives = (new PersonalityRoller())->roll($this->bot(3))->adjectives();

        self::assertLessThanOrEqual(3, substr_count($adjectives, ',') + 1);
        self::assertStringNotContainsString('.', $adjectives);
    }

    /**
     * Blood elf hauteur is the one piece of lore here that maps onto real supremacist rhetoric.
     * It judges conduct, never birth, and the prompt has to be explicit about it.
     */
    public function testBloodElfHauteurJudgesConductRatherThanBirth(): void
    {
        $note = Culture::noteFor(CharacterRace::BloodElf, Gender::Male);

        self::assertNotNull($note);
        self::assertStringContainsString('never anyone\'s birth or blood', $note);
    }

    /**
     * Kaldorei matriarchy is a fact about the setting, so it is worth saying. The temperament
     * axes are deliberately not rolled by gender, which is a different thing entirely.
     */
    public function testNightElfStandingDiffersByGenderBecauseTheSettingSaysSo(): void
    {
        $female = Culture::noteFor(CharacterRace::NightElf, Gender::Female);
        $male = Culture::noteFor(CharacterRace::NightElf, Gender::Male);

        self::assertNotNull($female);
        self::assertNotNull($male);
        self::assertNotSame($female, $male);
        self::assertStringContainsString('matriarchal', $female);
    }

    public function testTemperamentDoesNotDependOnGender(): void
    {
        $roller = new PersonalityRoller();

        self::assertSame(
            $roller->roll($this->bot(11, CharacterRace::Human, CharacterClass::Warrior, Gender::Female))->label(),
            $roller->roll($this->bot(11, CharacterRace::Human, CharacterClass::Warrior, Gender::Male))->label(),
        );
    }
}
