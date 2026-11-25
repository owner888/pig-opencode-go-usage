<?php

declare(strict_types=1);

namespace PigOpencodeGoUsage;

use Closure;
use Pig\CodingAgent\Extensions\ExtensionUi;

/**
 * The three things the tracker does to a screen — upstream's `UiCtx`: a status line, a widget above
 * the editor, a notification. A seam of its own so a test can watch them without a terminal.
 */
final readonly class Screen
{
    /**
     * @param Closure(string, ?string): void $setStatus
     * @param Closure(string, ?list<string>): void $setWidget
     * @param Closure(string, string): void $notify
     */
    public function __construct(
        public Closure $setStatus,
        public Closure $setWidget,
        public Closure $notify,
    ) {
    }

    /**
     * @param bool $dim the status line in the theme's `dim`, as the footer's own lines are — in the
     *        terminal only. The footer draws an extension's status as given (upstream's rule: the
     *        extension colours it), and plain it was the one bright line there. An RPC host or a page
     *        would get the escape codes as text, so they get it plain.
     */
    public static function of(ExtensionUi $ui, bool $dim = false): self
    {
        return new self(
            static fn (string $key, ?string $text) => $ui->setStatus($key, $dim && $text !== null ? $ui->theme()->fg('dim', $text) : $text),
            static fn (string $key, ?array $lines) => $ui->setWidget($key, $lines, ['placement' => 'above']),
            static fn (string $message, string $level) => $ui->notify($message, $level),
        );
    }

    public function status(string $key, ?string $text): void
    {
        ($this->setStatus)($key, $text);
    }

    /** @param list<string>|null $lines */
    public function widget(string $key, ?array $lines): void
    {
        ($this->setWidget)($key, $lines);
    }

    public function notify(string $message, string $level = 'info'): void
    {
        ($this->notify)($message, $level);
    }
}
