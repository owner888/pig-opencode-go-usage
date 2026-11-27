<?php

declare(strict_types=1);

namespace PigOpencodeGoUsage\Test\Unit;

use PHPUnit\Framework\TestCase;
use PigOpencodeGoUsage\Format;
use PigOpencodeGoUsage\UsageClient;

/** Upstream's `test/parse.test.ts`, the pace estimate. */
final class FormatTest extends TestCase
{
    private const float DAY = 86_400_000;

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
}
