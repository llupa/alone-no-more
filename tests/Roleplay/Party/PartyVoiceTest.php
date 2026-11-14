<?php

declare(strict_types=1);

namespace App\Tests\Roleplay\Party;

use App\Roleplay\Party\PartyVoice;
use App\Tests\Roleplay\ActorMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Who holds the floor, and what happens when it changes hands.
 *
 * Seniority has to survive a restart, because "the first one you invited" is a fact about your
 * evening, not about this process.
 */
#[CoversClass(PartyVoice::class)]
final class PartyVoiceTest extends TestCase
{
    private const PARTY = 'party:100';

    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir().'/anm-voice-'.bin2hex(random_bytes(6)).'.sqlite';
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    private function voice(): PartyVoice
    {
        return new PartyVoice($this->path);
    }

    public function testTheFirstMemberSeenSpeaksForTheParty(): void
    {
        $menelan = ActorMother::bot(1, 'Menelan');
        $voice = $this->voice();

        $voice->speakerFor(self::PARTY, [$menelan]);
        // Lyladaen arrives later and does not displace him.
        $chosen = $voice->speakerFor(self::PARTY, [ActorMother::bot(2, 'Lyladaen'), $menelan]);

        self::assertNotNull($chosen);
        self::assertSame('Menelan', $chosen->name);
    }

    public function testSeniorityOutlivesTheProcess(): void
    {
        $menelan = ActorMother::bot(1, 'Menelan');
        $this->voice()->speakerFor(self::PARTY, [$menelan]);

        // A fresh instance, as after a restart.
        $chosen = $this->voice()->speakerFor(self::PARTY, [ActorMother::bot(2, 'Lyladaen'), $menelan]);

        self::assertNotNull($chosen);
        self::assertSame('Menelan', $chosen->name);
    }

    /**
     * The succession the player never has to arrange: dismiss the voice and the next
     * longest-serving member picks it up.
     */
    public function testTheNextLongestServingTakesOverWhenTheVoiceLeaves(): void
    {
        $voice = $this->voice();
        $menelan = ActorMother::bot(1, 'Menelan');
        $lyladaen = ActorMother::bot(2, 'Lyladaen');
        $nobred = ActorMother::bot(3, 'Nobred');

        $voice->speakerFor(self::PARTY, [$menelan]);
        $voice->speakerFor(self::PARTY, [$menelan, $lyladaen]);
        $voice->speakerFor(self::PARTY, [$menelan, $lyladaen, $nobred]);

        $chosen = $voice->speakerFor(self::PARTY, [$lyladaen, $nobred]);

        self::assertNotNull($chosen);
        self::assertSame('Lyladaen', $chosen->name);
    }

    public function testTheFirstWordIsNotAHandover(): void
    {
        self::assertNull($this->voice()->takeOver(self::PARTY, ActorMother::bot(1, 'Menelan')));
    }

    public function testSpeakingAgainIsNotAHandover(): void
    {
        $voice = $this->voice();
        $menelan = ActorMother::bot(1, 'Menelan');

        $voice->takeOver(self::PARTY, $menelan);

        self::assertNull($voice->takeOver(self::PARTY, $menelan));
    }

    /**
     * The caller drops the party's turns on a handover, so it has to be told — otherwise the
     * successor reads its predecessor's words in its own voice.
     */
    public function testAChangeOfSpeakerNamesWhoHeldItBefore(): void
    {
        $voice = $this->voice();
        $voice->takeOver(self::PARTY, ActorMother::bot(1, 'Menelan'));

        self::assertSame('Menelan', $voice->takeOver(self::PARTY, ActorMother::bot(2, 'Lyladaen')));
    }

    public function testADisbandedPartyIsForgotten(): void
    {
        $voice = $this->voice();
        $menelan = ActorMother::bot(1, 'Menelan');
        $voice->speakerFor(self::PARTY, [$menelan]);
        $voice->takeOver(self::PARTY, $menelan);

        $voice->forget(self::PARTY);

        // Seniority is gone, so whoever is present now is the voice and it is a first word.
        $chosen = $voice->speakerFor(self::PARTY, [ActorMother::bot(2, 'Lyladaen')]);
        self::assertNotNull($chosen);
        self::assertSame('Lyladaen', $chosen->name);
        self::assertNull($voice->takeOver(self::PARTY, $chosen));
    }

    public function testAnEmptyPartyHasNoVoice(): void
    {
        self::assertNull($this->voice()->speakerFor(self::PARTY, []));
    }
}
