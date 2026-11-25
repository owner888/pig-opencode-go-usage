<?php

declare(strict_types=1);

namespace PigOpencodeGoUsage\Test\Unit;

use PHPUnit\Framework\TestCase;
use PigOpencodeGoUsage\Format;
use PigOpencodeGoUsage\UsageClient;

/** Upstream's `test/parse.test.ts`, the formatting half. */
final class FormatTest extends TestCase
{
    private const float DAY = 86_400_000;

    public function testCountdown(): void
    {
        $now = (float) UsageClient::parseMs('2026-08-16T00:00:00Z');
        $at = static fn (float $ms): string => UsageClient::isoString($now + $ms);

        $this->assertSame('20m', Format::countdown($at(1_200_000), $now));
        $this->assertSame('1h 12m', Format::countdown($at(4_320_000), $now));
        $this->assertSame('3d 4h', Format::countdown($at(273_600_000), $now));
        $this->assertSame('resets now', Format::countdown($at(-1), $now));
        $this->assertNull(Format::countdown(null, $now));
    }

    public function testBar(): void
    {
        $this->assertSame('██████░░░░', Format::bar(62, 10));
        $this->assertSame('░░░░░░░░░░', Format::bar(0, 10));
        $this->assertSame('██████████', Format::bar(100, 10));
        $this->assertSame('██████████', Format::bar(150, 10));
    }

    public function testDaysLeftEstimateExtrapolatesTheMonthlyPace(): void
    {
        $end = '2026-09-21T00:00:00Z';
        $start = (float) UsageClient::parseMs('2026-08-21T00:00:00Z');
        // 44 % used after 12 of 31 days leaves ~15 more days of quota at that pace.
        $now = $start + 12 * self::DAY;
        $this->assertEqualsWithDelta(56 * 12 / 44, Format::daysLeftEstimate(44, $end, $now), 1e-6);
        // A period ending on the 31st clamps back to the shorter month's last day.
        $clamped = Format::daysLeftEstimate(44, '2026-03-31T12:00:00Z', (float) UsageClient::parseMs('2026-03-15T12:00:00Z'));
        $this->assertEqualsWithDelta(56 * 15 / 44, $clamped, 1e-6);
        // January's period started in December.
        $january = Format::daysLeftEstimate(50, '2027-01-10T00:00:00Z', (float) UsageClient::parseMs('2026-12-20T00:00:00Z'));
        $this->assertEqualsWithDelta(10.0, $january, 1e-6);
        // Nothing to extrapolate from.
        $this->assertNull(Format::daysLeftEstimate(0, $end, $now));
        $this->assertNull(Format::daysLeftEstimate(44, null, $now));
        $this->assertNull(Format::daysLeftEstimate(44, 'not-a-date', $now));
        $this->assertNull(Format::daysLeftEstimate(44, $end, (float) UsageClient::parseMs($end)));
        // Fully used up.
        $this->assertSame(0.0, Format::daysLeftEstimate(100, $end, $now));
    }

    public function testFormatDaysLeftStaysShort(): void
    {
        $this->assertSame('~18d left', Format::formatDaysLeft(18.4));
        $this->assertSame('<1d left', Format::formatDaysLeft(0.4));
        $this->assertSame('quota used up', Format::formatDaysLeft(0));
    }

    public function testAPercentagePrintsAsJavaScriptPrintsIt(): void
    {
        $this->assertSame('62', Format::percent(62.0));
        $this->assertSame('62.5', Format::percent(62.5));
        $this->assertSame('0', Format::percent(0.0));
    }
}
