<?php

declare(strict_types=1);

namespace PigOpencodeGoUsage;

use JsonException;
use Pig\CodingAgent\Config;
use RuntimeException;

/**
 * Upstream's `Config` and its `loadConfig()` / `saveConfig()`: what `/opencode-go` saves, in a file
 * only its owner can read (`0600`), because the key and the cookie are credentials.
 *
 * Where this is pig's: the file is `~/.pig/agent/opencode-go-usage.json` (upstream's is under
 * `~/.omp/agent/`), and a file that is there but cannot be read or is not JSON is an error saying
 * so, where upstream reads it as empty and the next save overwrites it.
 */
final class SavedConfig
{
    public function __construct(
        public ?string $workspaceId = null,
        public ?string $authCookie = null,
        public ?string $apiKey = null,
        /** The monthly pace estimate after the reset countdown; shown unless false. */
        public ?bool $showEstimate = null,
    ) {
    }

    /** `OPENCODE_GO_CONFIG_PATH`, else beside pig's own settings. Resolved per call, for tests. */
    public static function path(): string
    {
        $override = getenv('OPENCODE_GO_CONFIG_PATH');

        return $override !== false && $override !== '' ? $override : Config::home() . '/opencode-go-usage.json';
    }

    public static function load(): self
    {
        $path = self::path();

        if (!is_file($path)) {
            return new self();
        }

        $text = self::quietly(static fn () => file_get_contents($path), "Cannot read {$path}");

        try {
            $data = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException("{$path} is not JSON: {$error->getMessage()}", 0, $error);
        }

        if (!is_array($data)) {
            throw new RuntimeException("{$path} is not a JSON object.");
        }

        $string = static fn (string $key): ?string => is_string($data[$key] ?? null) ? $data[$key] : null;
        $bool = static fn (string $key): ?bool => is_bool($data[$key] ?? null) ? $data[$key] : null;

        return new self($string('workspaceId'), $string('authCookie'), $string('apiKey'), $bool('showEstimate'));
    }

    public function save(): void
    {
        $path = self::path();
        $data = array_filter([
            'workspaceId' => $this->workspaceId,
            'authCookie' => $this->authCookie,
            'apiKey' => $this->apiKey,
            'showEstimate' => $this->showEstimate,
        ], static fn (mixed $value): bool => $value !== null);

        self::writeAtomically($path, json_encode((object) $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 0o600);
    }

    /** Write to `<path>.tmp` with `$mode`, then rename over `$path`. */
    public static function writeAtomically(string $path, string $contents, int $mode = 0o644): void
    {
        $tmp = "{$path}.tmp";
        self::quietly(static fn () => file_put_contents($tmp, ''), "Cannot write {$tmp}");
        self::quietly(static fn () => chmod($tmp, $mode), "Cannot set the mode of {$tmp}");
        self::quietly(static fn () => file_put_contents($tmp, $contents), "Cannot write {$tmp}");
        self::quietly(static fn () => rename($tmp, $path), "Cannot move {$tmp} to {$path}");
    }

    /**
     * Run a filesystem call, and turn `false` or a warning into an exception with the warning's text.
     *
     * @template T
     * @param \Closure(): (T|false) $call
     * @return T
     */
    private static function quietly(\Closure $call, string $what): mixed
    {
        $warning = null;
        set_error_handler(static function (int $level, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        try {
            $result = $call();
        } finally {
            restore_error_handler();
        }

        if ($result === false || $warning !== null) {
            throw new RuntimeException($what . ($warning !== null ? ": {$warning}" : '.'));
        }

        return $result;
    }
}
