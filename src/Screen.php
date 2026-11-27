<?php

declare(strict_types=1);

namespace PigOpencodeGoUsage;

use Closure;
use Pig\CodingAgent\Extensions\ExtensionUi;
use Pig\CodingAgent\Theme\Theme;

/**
 * Where `/opencode-go` answers: a notification, and the theme its report is coloured with — none
 * outside the terminal, where an RPC host or a page would get the escape codes as text. A seam of
 * its own so a test can read what was said without a terminal.
 */
final readonly class Screen
{
    /** @param Closure(string, string): void $notify */
    public function __construct(
        public Closure $notify,
        public ?Theme $theme = null,
    ) {
    }

    public static function of(ExtensionUi $ui, bool $terminal): self
    {
        return new self(static fn (string $message, string $level) => $ui->notify($message, $level), $terminal ? $ui->theme() : null);
    }

    public function notify(string $message, string $level = 'info'): void
    {
        ($this->notify)($message, $level);
    }
}
