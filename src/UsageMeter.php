<?php

declare(strict_types=1);

namespace PigOpencodeGoUsage;

/** One usage window: upstream's `UsageMeter`. */
final readonly class UsageMeter
{
    /**
     * @param float $percent 0–100, clamped, one decimal
     * @param string|null $resetsAt ISO timestamp of rollover, or null when the window is not open
     */
    public function __construct(
        public MeterKind $kind,
        public float $percent,
        public ?string $resetsAt,
    ) {
    }

    /** As `--json` writes it. @return array{kind: string, percent: float, resetsAt: ?string} */
    public function toArray(): array
    {
        return ['kind' => $this->kind->value, 'percent' => $this->percent, 'resetsAt' => $this->resetsAt];
    }
}
