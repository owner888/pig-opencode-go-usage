<?php

declare(strict_types=1);

namespace PigOpencodeGoUsage;

/** Upstream's `MeterKind`, with its `METER_LABEL` and `METER_SHORT` tables. */
enum MeterKind: string
{
    case FiveHour = 'five_hour';
    case CalendarWeek = 'calendar_week';
    case ProductPeriod = 'product_period';

    /** The report's name for the meter (`METER_LABEL`). */
    public function label(): string
    {
        return match ($this) {
            self::FiveHour => 'Rolling 5h',
            self::CalendarWeek => 'Weekly',
            self::ProductPeriod => 'Monthly',
        };
    }

    /** The status bar's name for the meter (`METER_SHORT`). */
    public function short(): string
    {
        return match ($this) {
            self::FiveHour => '5h',
            self::CalendarWeek => 'wk',
            self::ProductPeriod => 'mo',
        };
    }
}
