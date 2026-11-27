<?php

declare(strict_types=1);

namespace PigOpencodeGoUsage;

use Closure;
use DateTimeImmutable;
use Pig\CodingAgent\Config;
use Pig\CodingAgent\Usage\UsageGroup;
use Pig\CodingAgent\Usage\UsageMeter as Meter;
use Pig\CodingAgent\Usage\UsageReport;
use Pig\CodingAgent\Usage\UsageView;
use RuntimeException;
use Throwable;

/**
 * The body of upstream's `opencodeGoUsage()` factory: which credentials win, a refresh, and
 * `/opencode-go` with its subcommands. The extension's entry registers `report()` with pig.
 *
 * Where this is pig's:
 *
 * - **pig draws it** (`registerUsage()`, the developer's choice): the footer line, `/usage`, the
 *   web panel and `/opencode-go`'s report all come from `report()` through pig's `UsageView`, so
 *   OpenCode Go looks like every other plan — bars of what is *left*, where upstream shows what is
 *   used, with its own status line, report widget, timer and `--compact`. Those are not ported.
 * - **The key pig already has counts.** After upstream's `OPENCODE_GO_API_KEY`, `OPENCODE_API_KEY`,
 *   `OPENCODE_ZEN_API_KEY` and the saved key come pig's own sign-ins for `opencode-go` and then
 *   `opencode` (`/login`, or `auth.json`): somebody who uses OpenCode Go in pig has nothing to set up.
 * - **A saved file that cannot be read is said**, on the status line, rather than read as empty.
 * - **A JSON report that cannot be written says why**, not only that it failed.
 */
final class Tracker
{
    public const string KEY = 'opencode-go';

    /** The variables an API key is read from, first non-empty wins. */
    public const array API_KEY_VARIABLES = ['OPENCODE_GO_API_KEY', 'OPENCODE_API_KEY', 'OPENCODE_ZEN_API_KEY'];

    /** pig's providers whose sign-in is an OpenCode API key, in the order they are asked. */
    public const array PROVIDERS = ['opencode-go', 'opencode'];

    public const string TITLE = 'OpenCode Go';

    public const string NOT_CONNECTED = 'Not connected. Run /login and choose OpenCode Go, or /opencode-go --connect <api-key> (or <wrk_…> <console-cookie>).';

    public const string COMMAND_DESCRIPTION = 'Show OpenCode Go usage. Subcommands: --connect <api-key> | --connect <wrk> <cookie> | --api-key <key> | --workspace <id> | --cookie <v> | --disconnect | --refresh | --estimate [on|off] | --json';

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
    public function refresh(): void
    {
        $apiKey = $this->apiKey();
        $credentials = $this->credentials();

        if ($apiKey === null && $credentials === null) {
            $this->meters = [];
            $this->lastError = null;

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
    }

    /**
     * What pig draws: the saved file read again (pig's web server outlives any one session), a
     * fresh fetch, and each window as what is left. A failure is thrown, so pig says it as one.
     */
    public function report(): UsageReport
    {
        $this->loadConfig();

        if ($this->configError !== null) {
            throw new RuntimeException($this->configError);
        }

        if ($this->apiKey() === null && $this->credentials() === null) {
            return UsageReport::note(self::NOT_CONNECTED);
        }

        $this->refresh();

        if ($this->lastError !== null) {
            throw new RuntimeException($this->lastError);
        }

        return new UsageReport([new UsageGroup(array_map(fn (UsageMeter $meter): Meter => new Meter(
            $meter->kind->label(),
            (100 - $meter->percent) / 100,
            $meter->resetsAt === null ? null : new DateTimeImmutable($meter->resetsAt),
            $meter->kind->short(),
            daysLeft: $this->monthDaysLeft($meter),
        ), $this->meters))]);
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
            '--estimate' => $this->estimate($screen, $rest[0] ?? null),
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

        if ($warning !== null) {
            $screen->notify($warning, 'warning');
        }

        $screen->notify('Disconnected');
    }

    private function estimate(Screen $screen, ?string $arg): void
    {
        $this->config->showEstimate = $arg !== null ? !($arg === 'off' || $arg === 'false') : $this->config->showEstimate === false;
        $this->save();
        $screen->notify('Usage estimate ' . ($this->config->showEstimate === false ? 'off' : 'on'));
    }

    private function writeJson(Screen $screen): void
    {
        $this->refresh();
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

    /** The report as `/usage opencode-go` draws it — the same look as every other plan. */
    private function show(Screen $screen): void
    {
        try {
            $screen->notify(UsageView::text($this->report(), $screen->theme, self::TITLE));
        } catch (RuntimeException $error) {
            $screen->notify(self::TITLE . ': ' . $error->getMessage(), 'error');
        }
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

    /**
     * Monthly only: the days what is left lasts at the pace so far — upstream's estimate; pig words
     * it, in the page's language.
     */
    private function monthDaysLeft(UsageMeter $meter): ?float
    {
        if ($meter->kind !== MeterKind::ProductPeriod || $this->config->showEstimate === false) {
            return null;
        }

        return Format::daysLeftEstimate($meter->percent, $meter->resetsAt, $this->nowMs());
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
