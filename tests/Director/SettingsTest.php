<?php

declare(strict_types=1);

namespace App\Tests\Director;

use App\Director\Settings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Decisions made while playing, rather than configuration set once.
 *
 * These are written over MCP by an assistant filling in arguments from a tool description, so
 * the bounds matter as much as the storage: "much busier" could arrive as 9,000.
 */
#[CoversClass(Settings::class)]
final class SettingsTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir().'/anm-settings-'.bin2hex(random_bytes(6)).'.sqlite';
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    private function settings(): Settings
    {
        return new Settings($this->path, defaultCrowdSize: 50, defaultLevelTolerance: 5, defaultReplyCooldown: 20);
    }

    public function testUntouchedSettingsFallBackToTheConfiguredDefaults(): void
    {
        $settings = $this->settings();

        self::assertSame(50, $settings->crowdSize());
        self::assertSame(5, $settings->levelTolerance());
        self::assertTrue($settings->isHerding());
        self::assertSame([], $settings->followed());
    }

    public function testAChangeSurvivesARestart(): void
    {
        $this->settings()->setNumber('crowd_size', 120);

        // A fresh instance, as the background process would be after a restart.
        self::assertSame(120, $this->settings()->crowdSize());
    }

    public function testAnOutOfRangeValueIsRefusedWithItsBounds(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/between 0 and 400/');

        $this->settings()->setNumber('crowd_size', 9000);
    }

    public function testAnUnknownSettingListsTheRealOnes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/crowd_size/');

        $this->settings()->setNumber('make_it_fun', 11);
    }

    public function testFollowingIsRememberedAndNotDuplicated(): void
    {
        $settings = $this->settings();
        $settings->follow('Doppel');
        // Case differs because a player types their own name however they please.
        $settings->follow('doppel');

        self::assertSame(['Doppel'], $settings->followed());
    }

    public function testSeveralCharactersCanBeFollowed(): void
    {
        $settings = $this->settings();
        $settings->follow('Doppel');
        $settings->follow('Menelan');

        self::assertSame(['Doppel', 'Menelan'], $settings->followed());
    }

    public function testDroppingTheLastFollowedCharacterGoesBackToEveryone(): void
    {
        $settings = $this->settings();
        $settings->follow('Doppel');

        self::assertTrue($settings->stopFollowing('Doppel'));
        self::assertSame([], $settings->followed());
    }

    public function testDroppingSomeoneWhoWasNotFollowedSaysSo(): void
    {
        self::assertFalse($this->settings()->stopFollowing('Nobody'));
    }

    public function testHerdingCanBeTurnedOffAndBackOn(): void
    {
        $settings = $this->settings();

        $settings->setHerding(false);
        self::assertFalse($this->settings()->isHerding());

        $settings->setHerding(true);
        self::assertTrue($this->settings()->isHerding());
    }
}
