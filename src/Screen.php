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

    public static function of(ExtensionUi $ui): self
    {
        return new self(
            static fn (string $key, ?string $text) => $ui->setStatus($key, $text),
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
