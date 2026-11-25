<?php

declare(strict_types=1);

namespace PigOpencodeGoUsage;

use RuntimeException;
use Throwable;

/**
 * Upstream's `FetchFailure` union, as one exception with its `kind`; `describe()` is its
 * `describeFailure()`, word for word.
 */
final class FetchFailure extends RuntimeException
{
    public const string NO_CREDENTIALS = 'noCredentials';
    public const string TIMEOUT = 'timeout';
    public const string NETWORK = 'network';
    public const string UNAUTHORIZED = 'unauthorized';
    public const string HTTP = 'http';
    public const string NO_SUBSCRIPTION = 'noSubscription';
    public const string NO_PAYLOAD = 'noPayload';

    private function __construct(
        public readonly string $kind,
        public readonly ?string $detail = null,
        public readonly ?int $status = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct('', 0, $previous);
        $this->message = $this->describe();
    }

    public static function of(string $kind, ?Throwable $previous = null): self
    {
        return new self($kind, previous: $previous);
    }

    public static function network(string $detail, ?Throwable $previous = null): self
    {
        return new self(self::NETWORK, detail: $detail, previous: $previous);
    }

    public static function http(int $status): self
    {
        return new self(self::HTTP, status: $status);
    }

    public function describe(): string
    {
        return match ($this->kind) {
            self::NO_CREDENTIALS => 'Not connected. Run /opencode-go --connect <wrk_…> <console-cookie>',
            self::TIMEOUT => 'Request timed out',
            self::NETWORK => "Network error: {$this->detail}",
            self::UNAUTHORIZED => 'Session expired — reconnect with a fresh console cookie',
            self::HTTP => "HTTP {$this->status}",
            self::NO_SUBSCRIPTION => 'No Go subscription on this workspace',
            self::NO_PAYLOAD => 'Console API response unrecognised — opencode.ai may have changed its API',
        };
    }
}
