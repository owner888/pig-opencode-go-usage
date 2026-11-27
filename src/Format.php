<?php

declare(strict_types=1);

namespace PigOpencodeGoUsage;

/**
 * Upstream's `daysLeftEstimate()`, the monthly pace estimate. Its `bar()`, `countdown()` and
 * `formatDaysLeft()` are not ported: pig draws the bars, the resets and the estimate's words
 * (`UsageView`), in the page's language.
 */
final class Format
{
    private const int DAY_MS = 86_400_000;

    /**
     * Days the monthly quota will last at the pace so far. The period covers one calendar month
     * ending at `$resetsAt`, so it started the same instant a month earlier. Null when there is no
     * pace to extrapolate (no reset, 0 % used, period already over) and 0 once the quota is used up.
     */
    public static function daysLeftEstimate(float $percent, ?string $resetsAt, ?float $nowMs = null): ?float
    {
        $nowMs ??= self::nowMs();
        $end = UsageClient::parseMs($resetsAt);

        if ($end === null || $percent <= 0 || $nowMs >= $end) {
            return null;
        }

        if ($percent >= 100) {
            return 0.0;
        }

        // A month earlier, at the same time of day; a 31st end ("31 Feb") falls back to the
        // shorter month's last day, as upstream's `setUTCDate(0)` does.
        $endDate = new \DateTimeImmutable('@' . intdiv((int) $end, 1000));
        $year = (int) $endDate->format('Y');
        $month = (int) $endDate->format('n') - 1;

        if ($month === 0) {
            $month = 12;
            $year--;
        }

        $day = min((int) $endDate->format('j'), (int) $endDate->setDate($year, $month, 1)->format('t'));
        $start = $endDate->setDate($year, $month, $day);
        $startMs = (float) $start->format('U') * 1000 + fmod($end, 1000);
        $elapsedDays = ($nowMs - $startMs) / self::DAY_MS;

        if (!($elapsedDays > 0)) {
            return null;
        }

        return (100 - $percent) * $elapsedDays / $percent;
    }

    public static function nowMs(): float
    {
        return floor(microtime(true) * 1000);
    }
}
