<?php

declare(strict_types=1);

namespace PigOpencodeGoUsage;

use Closure;
use Pig\CodingAgent\Config;
use RuntimeException;
use Throwable;

/**
 * The body of upstream's `opencodeGoUsage()` factory: which credentials win, the status line, the
 * report widget, a refresh, and `/opencode-go` with its subcommands. The extension's entry wires it
 * to pig's hooks and its timer.
 *
 * Where this is pig's:
 *
 * - **The key pig already has counts.** After upstream's `OPENCODE_GO_API_KEY`, `OPENCODE_API_KEY`,
 *   `OPENCODE_ZEN_API_KEY` and the saved key come pig's own sign-ins for `opencode-go` and then
 *   `opencode` (`/login`, or `auth.json`): somebody who uses OpenCode Go in pig has nothing to set up.
 * - **A saved file that cannot be read is said**, on the status line, rather than read as empty.
 * - **A JSON report that cannot be written says why**, not only that it failed.
 */
final class Tracker
{
    public const string KEY = 'opencode-go';

    public const int REFRESH_SECONDS = 300;

    /** The variables an API key is read from, first non-empty wins. */
    public const array API_KEY_VARIABLES = ['OPENCODE_GO_API_KEY', 'OPENCODE_API_KEY', 'OPENCODE_ZEN_API_KEY'];

    /** pig's providers whose sign-in is an OpenCode API key, in the order they are asked. */
    public const array PROVIDERS = ['opencode-go', 'opencode'];

    public const string COMMAND_DESCRIPTION = 'Show OpenCode Go usage. Subcommands: --connect <api-key> | --connect <wrk> <cookie> | --api-key <key> | --workspace <id> | --cookie <v> | --disconnect | --refresh | --compact [on|off] | --estimate [on|off] | --json';

    private SavedConfig $config;

    /** @var list<UsageMeter> */
    private array $meters = [];

    private ?string $lastError = null;

    /** Why the saved file could not be read, until it can be. */
    private ?string $configError = null;

    private float $lastFetchedAt = 0;

    /**
     * @param (Closure(string): ?string)|null $signedInKey pig's stored key for a provider, null for none
     * @param (Closure(): float)|null $now milliseconds since the epoch
     */
    public function __construct(
        private readonly UsageClient $client = new UsageClient(),
        private readonly ?Closure $signedInKey = null,
        private readonly ?Closure $now = null,
    ) {
        $this->config = new SavedConfig();
    }

    /** Read the saved file again — at session start, as upstream does. */
    public function loadConfig(): void
    {
        try {
            $this->config = SavedConfig::load();
            $this->configError = null;
        } catch (RuntimeException $error) {
            $this->config = new SavedConfig();
            $this->configError = $error->getMessage();
        }
    }

    public function config(): SavedConfig
    {
        return $this->config;
    }

    /** @return list<UsageMeter> */
    public function meters(): array
    {
        return $this->meters;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    public function apiKey(): ?string
    {
        foreach (self::API_KEY_VARIABLES as $name) {
            $value = trim((string) getenv($name));

            if ($value !== '') {
                return $value;
            }
        }

        $saved = trim($this->config->apiKey ?? '');

        if ($saved !== '') {
            return $saved;
        }

        foreach ($this->signedInKey === null ? [] : self::PROVIDERS as $provider) {
            $key = trim((string) ($this->signedInKey)($provider));

            if ($key !== '') {
                return $key;
            }
        }

        return null;
    }

    /** @return array{workspaceId: string, authCookie: string}|null */
    public function credentials(): ?array
    {
        $workspaceId = trim(self::env('OPENCODE_GO_WORKSPACE_ID') ?? $this->config->workspaceId ?? '');
        $authCookie = trim(self::env('OPENCODE_GO_AUTH_COOKIE') ?? $this->config->authCookie ?? '');

        return $workspaceId !== '' && $authCookie !== '' ? ['workspaceId' => $workspaceId, 'authCookie' => $authCookie] : null;
    }

    /**
     * Env credentials win over the saved ones, so a `--connect` or `--disconnect` can do nothing
     * while one is set: said instead.
     */
    public function envOverrideWarning(): ?string
    {
        $envApiKey = null;

        foreach (self::API_KEY_VARIABLES as $name) {
            $envApiKey ??= self::env($name);
        }

        $envWorkspace = self::env('OPENCODE_GO_WORKSPACE_ID');
        $envCookie = self::env('OPENCODE_GO_AUTH_COOKIE');
        $overrides = ($envApiKey !== null && trim($envApiKey) !== ($this->config->apiKey ?? ''))
            || ($envWorkspace !== null && trim($envWorkspace) !== $this->config->workspaceId)
            || ($envCookie !== null && trim($envCookie) !== $this->config->authCookie);

        return $overrides
            ? 'OPENCODE_GO_WORKSPACE_ID / OPENCODE_GO_AUTH_COOKIE / OPENCODE_GO_API_KEY are set and take precedence over saved values — update or unset them'
            : null;
    }

    /** Fetch the meters, with the API key when there is one, else the console cookie. */
    public function refresh(?Screen $screen = null): void
    {
        $apiKey = $this->apiKey();
        $credentials = $this->credentials();

        if ($apiKey === null && $credentials === null) {
            $this->meters = [];
            $this->lastError = null;
            $screen?->status(self::KEY, $this->statusText());

            return;
        }

        try {
            $this->meters = $apiKey !== null
                ? $this->client->fetchWithApiKey($apiKey)
                : $this->client->fetchWithCookie($credentials['workspaceId'], $credentials['authCookie']);
            $this->lastError = null;
            $this->lastFetchedAt = $this->nowMs();
        } catch (FetchFailure $failure) {
            $this->meters = [];
            $this->lastError = $failure->describe();
        }

        $screen?->status(self::KEY, $this->statusText());
    }

    public function statusText(): string
    {
        if ($this->configError !== null) {
            return "OpenCode Go: {$this->configError}";
        }

        if ($this->apiKey() === null && $this->credentials() === null) {
            return 'OpenCode Go: not connected (/opencode-go --connect)';
        }

        if ($this->lastError !== null) {
            return "OpenCode Go: {$this->lastError}";
        }

        if ($this->meters === []) {
            return 'OpenCode Go: loading…';
        }

        $parts = array_map(function (UsageMeter $meter): string {
            $head = $meter->kind->short() . ' ' . Format::percent($meter->percent) . '%';

            if ($this->config->compact === true) {
                return $head;
            }

            $detail = $this->detail($meter);

            return $detail !== '' ? "{$head} ({$detail})" : $head;
        }, $this->meters);
        $text = 'OpenCode Go: ' . implode(' · ', $parts);

        if ($this->config->compact !== true && $this->lastFetchedAt > 0) {
            $text .= ' · ' . $this->updatedAt();
        }

        return $text;
    }

    /** @return list<string> */
    public function reportLines(): array
    {
        $credentials = $this->credentials();
        $lines = ['OpenCode Go Usage'];

        if ($this->configError !== null) {
            $lines[] = "Error: {$this->configError}";
        }

        if ($credentials === null && $this->apiKey() === null) {
            $lines[] = 'Not connected.';
            $lines[] = 'Run /opencode-go --connect <api-key> (or <wrk_…> <console-cookie>), or /login OpenCode Go';
            $lines[] = 'Or set OPENCODE_GO_API_KEY / OPENCODE_GO_WORKSPACE_ID + OPENCODE_GO_AUTH_COOKIE';

            return $lines;
        }

        $lines[] = $credentials !== null ? "Workspace: {$credentials['workspaceId']}" : 'API key: connected';

        if ($this->lastError !== null) {
            $lines[] = "Error: {$this->lastError}";
        } elseif ($this->meters === []) {
            $lines[] = 'Loading…';
        } else {
            foreach ($this->meters as $meter) {
                $detail = $this->detail($meter);
                $lines[] = str_pad($meter->kind->label(), 10) . ' ' . Format::bar($meter->percent) . '  ' . Format::percent($meter->percent) . '%' . ($detail !== '' ? " · {$detail}" : '');
            }
        }

        if ($this->lastFetchedAt > 0) {
            $lines[] = $this->updatedAt();
        }

        return $lines;
    }

    /** `/opencode-go [subcommand]`. */
    public function command(string $args, Screen $screen): void
    {
        $tokens = preg_split('/\s+/', trim($args), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $sub = $tokens[0] ?? null;
        $rest = array_slice($tokens, 1);

        match ($sub) {
            '--connect', '--setup' => $this->connect($rest, $screen),
            '--workspace' => $this->saveOne($screen, 'workspaceId', $rest[0] ?? '', 'Usage: /opencode-go --workspace <wrk_…>', static fn (string $v): string => "Workspace set to {$v}"),
            '--cookie' => $this->saveOne($screen, 'authCookie', implode(' ', $rest), 'Usage: /opencode-go --cookie <console-cookie>', static fn (): string => 'Cookie saved'),
            '--api-key' => $this->saveOne($screen, 'apiKey', implode(' ', $rest), 'Usage: /opencode-go --api-key <key>', static fn (): string => 'API key saved'),
            '--disconnect' => $this->disconnect($screen),
            '--compact' => $this->toggle($screen, 'compact', $rest[0] ?? null),
            '--estimate' => $this->toggle($screen, 'showEstimate', $rest[0] ?? null),
            '--json' => $this->writeJson($screen),
            default => $this->show($screen),
        };
    }

    /** @param list<string> $rest */
    private function connect(array $rest, Screen $screen): void
    {
        // One token is an API key; two are the legacy workspace and cookie.
        if (count($rest) === 1) {
            $this->config->apiKey = trim($rest[0]);
        } else {
            $workspaceId = $rest[0] ?? '';
            $cookie = implode(' ', array_slice($rest, 1));

            if ($workspaceId === '' || $cookie === '') {
                $screen->notify('Usage: /opencode-go --connect <api-key>  OR  /opencode-go --connect <wrk_…> <console-cookie>', 'warning');

                return;
            }

            $this->config->workspaceId = trim($workspaceId);
            $this->config->authCookie = trim($cookie);
        }

        $this->save();
        $this->warnIfShadowed($screen);
        $screen->notify('Saved. Fetching usage…');
        $this->show($screen);
    }

    /** @param Closure(string): string $said */
    private function saveOne(Screen $screen, string $field, string $value, string $usage, Closure $said): void
    {
        if (trim($value) === '') {
            $screen->notify($usage, 'warning');

            return;
        }

        $this->config->{$field} = trim($value);
        $this->save();
        $this->warnIfShadowed($screen);
        $screen->notify($said(trim($value)));
    }

    private function disconnect(Screen $screen): void
    {
        $warning = $this->envOverrideWarning();
        $this->config->workspaceId = $this->config->authCookie = $this->config->apiKey = null;
        $this->save();
        $this->meters = [];
        $this->lastError = null;
        $screen->status(self::KEY, $this->statusText());
        $screen->widget(self::KEY, null);

        if ($warning !== null) {
            $screen->notify($warning, 'warning');
        }

        $screen->notify('Disconnected');
    }

    private function toggle(Screen $screen, string $field, ?string $arg): void
    {
        if ($field === 'compact') {
            $this->config->compact = $arg !== null ? $arg !== 'off' && $arg !== 'false' : $this->config->compact !== true;
            $said = 'Compact mode ' . ($this->config->compact ? 'on' : 'off');
        } else {
            $this->config->showEstimate = $arg !== null ? !($arg === 'off' || $arg === 'false') : $this->config->showEstimate === false;
            $said = 'Usage estimate ' . ($this->config->showEstimate === false ? 'off' : 'on');
        }

        $this->save();
        $screen->status(self::KEY, $this->statusText());
        $screen->notify($said);
    }

    private function writeJson(Screen $screen): void
    {
        $this->refresh($screen);
        $path = Config::home() . '/opencode-go-usage-report.json';
        $report = [
            'workspaceId' => $this->credentials()['workspaceId'] ?? null,
            'fetchedAt' => $this->lastFetchedAt > 0 ? UsageClient::isoString($this->lastFetchedAt) : null,
            'error' => $this->lastError,
            'meters' => array_map(static fn (UsageMeter $meter): array => $meter->toArray(), $this->meters),
        ];

        try {
            SavedConfig::writeAtomically($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $screen->notify("JSON report written to {$path}");
        } catch (Throwable $error) {
            $screen->notify("Failed to write JSON report: {$error->getMessage()}", 'error');
        }
    }

    private function show(Screen $screen): void
    {
        $this->refresh($screen);
        $screen->widget(self::KEY, $this->reportLines());
    }

    /** A save replaces whatever the file held, a broken one too. */
    private function save(): void
    {
        $this->config->save();
        $this->configError = null;
    }

    private function warnIfShadowed(Screen $screen): void
    {
        $warning = $this->envOverrideWarning();

        if ($warning !== null) {
            $screen->notify($warning, 'warning');
        }
    }

    /** The countdown, and for the monthly meter the pace estimate after a `/`. */
    private function detail(UsageMeter $meter): string
    {
        return implode(' / ', array_filter([Format::countdown($meter->resetsAt, $this->nowMs()), $this->monthEta($meter)]));
    }

    /** Monthly only: quota days left at the current pace, e.g. `~18d left`. */
    private function monthEta(UsageMeter $meter): ?string
    {
        if ($meter->kind !== MeterKind::ProductPeriod || $this->config->showEstimate === false) {
            return null;
        }

        $days = Format::daysLeftEstimate($meter->percent, $meter->resetsAt, $this->nowMs());

        return $days === null ? null : Format::formatDaysLeft($days);
    }

    /** `update 2:32 PM (Asia/Singapore)`. */
    private function updatedAt(): string
    {
        return 'update ' . date('g:i A', (int) ($this->lastFetchedAt / 1000)) . ' (' . date_default_timezone_get() . ')';
    }

    private function nowMs(): float
    {
        return $this->now !== null ? ($this->now)() : Format::nowMs();
    }

    private static function env(string $name): ?string
    {
        $value = getenv($name);

        return $value === false ? null : $value;
    }
}
