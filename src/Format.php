<?php

declare(strict_types=1);

namespace PigOpencodeGoUsage;

/** Upstream's `bar()`, `countdown()`, `daysLeftEstimate()` and `formatDaysLeft()`. */
final class Format
{
    private const int DAY_MS = 86_400_000;

    public static function bar(float $percent, int $width = 10): string
    {
        $clamped = min(100.0, max(0.0, $percent));
        $filled = (int) round($clamped / 100 * $width);

        return str_repeat('█', $filled) . str_repeat('░', $width - $filled);
    }

    /** `20m`, `1h 12m`, `3d 4h`, `resets now`; null without a reset. */
    public static function countdown(?string $resetsAt, ?float $nowMs = null): ?string
    {
        $target = UsageClient::parseMs($resetsAt);

        if ($target === null) {
            return null;
        }

        $ms = $target - ($nowMs ?? self::nowMs());

        if ($ms <= 0) {
            return 'resets now';
        }

        $totalMinutes = (int) floor($ms / 60_000);
        $days = intdiv($totalMinutes, 60 * 24);
        $hours = intdiv($totalMinutes % (60 * 24), 60);
        $minutes = $totalMinutes % 60;

        if ($days > 0) {
            return "{$days}d {$hours}h";
        }

        return $hours > 0 ? "{$hours}h {$minutes}m" : "{$minutes}m";
    }

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

    /** `~18d left` / `<1d left` / `quota used up` — short enough for a status bar. */
    public static function formatDaysLeft(float $days): string
    {
        if ($days <= 0) {
            return 'quota used up';
        }

        return $days < 1 ? '<1d left' : '~' . (int) round($days) . 'd left';
    }

    /** A percentage as JavaScript prints a number: `62`, `62.5`. */
    public static function percent(float $percent): string
    {
        return (string) (floor($percent) === $percent ? (int) $percent : $percent);
    }

    public static function nowMs(): float
    {
        return floor(microtime(true) * 1000);
    }
}
