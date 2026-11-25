<?php

declare(strict_types=1);

namespace PigOpencodeGoUsage;

use JsonException;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\HttpTransport;
use Pig\Ai\Http\Request;
use Pig\Ai\Http\Response;
use Pig\Ai\Http\Retrying;
use Pig\Async\AbortError;
use Throwable;

/**
 * Reading the usage: upstream's `parseGoStatus()`, `fetchUsageApiKey()` and the console-cookie half
 * of `fetchUsage()`, request for request — the URL, the headers, a redirect not followed, and which
 * answer is which failure.
 *
 * Where this is pig's: the request goes through `Retrying::fetch()` (pig's rule for a plain HTTP
 * request), so a dropped connection or a 5xx is asked again before it becomes a failure; upstream
 * sends once. Upstream's aliases (`parseUsage`, `fetchGoUsage`, …) and its overloaded
 * `fetchUsage(apiKeyOrWorkspace, cookieOrOrigin)` are not here: two methods say which is which.
 */
final class UsageClient
{
    public const string DEFAULT_ORIGIN = 'https://opencode.ai';

    /** Console session cookie; browsers on https use the `__Host-` prefixed name. */
    public const string DEFAULT_COOKIE_NAME = '__Host-console_session';

    public const float REQUEST_TIMEOUT = 20.0;

    public const string USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    /** Upstream's `WINDOW_KEYS`: the legacy console's meters; the month falls back to the period end. */
    private const array WINDOW_KEYS = [
        ['fiveHour', MeterKind::FiveHour, false],
        ['week', MeterKind::CalendarWeek, false],
        ['month', MeterKind::ProductPeriod, true],
    ];

    /** The API-key endpoint's windows. */
    private const array USAGE_KEYS = [
        ['rolling', MeterKind::FiveHour],
        ['weekly', MeterKind::CalendarWeek],
        ['monthly', MeterKind::ProductPeriod],
    ];

    public function __construct(
        private readonly ?HttpTransport $http = null,
        private readonly string $origin = self::DEFAULT_ORIGIN,
    ) {
    }

    /**
     * Reads either payload: the API key's `{usage: {rolling, weekly, monthly}}`, else the console's
     * `{access: {meters: {fiveHour, week, month}}}`. Empty when it carries no meter windows — the
     * caller reports that as a changed API rather than as a confident zero.
     *
     * @return list<UsageMeter>
     */
    public static function parseGoStatus(mixed $payload): array
    {
        $usage = self::record(self::record($payload)['usage'] ?? null);

        if ($usage !== null) {
            $result = [];

            foreach (self::USAGE_KEYS as [$key, $kind]) {
                $window = self::record($usage[$key] ?? null);
                $percent = self::toNumber($window['percent'] ?? null);

                if ($window === null || $percent === null) {
                    continue;
                }

                $resetsAt = $window['resetsAt'] ?? null;
                $result[] = new UsageMeter($kind, self::clampPercent($percent), is_string($resetsAt) ? $resetsAt : null);
            }

            if ($result !== []) {
                return $result;
            }
        }

        // The legacy console shape.
        $access = self::record(self::record($payload)['access'] ?? null);
        $meters = self::record($access['meters'] ?? null);

        if ($meters === null) {
            return [];
        }

        $periodEnd = self::parseMs($access['endsAt'] ?? null);
        $result = [];

        foreach (self::WINDOW_KEYS as [$key, $kind, $fallsBackToPeriodEnd]) {
            $window = self::record($meters[$key] ?? null);

            if ($window === null) {
                continue;
            }

            $used = self::toNumber($window['usedMicroCents'] ?? null);
            $limit = self::toNumber($window['limitMicroCents'] ?? null);

            if ($used === null || $limit === null) {
                continue;
            }

            $percent = $limit > 0 ? self::clampPercent($used / $limit * 100) : 0.0;
            $resetsAt = self::parseMs($window['resetsAt'] ?? null) ?? ($fallsBackToPeriodEnd ? $periodEnd : null);
            $result[] = new UsageMeter($kind, $percent, $resetsAt === null ? null : self::isoString($resetsAt));
        }

        return $result;
    }

    /**
     * `GET /zen/go/v1/usage` with the API key as a bearer token.
     *
     * @return list<UsageMeter>
     * @throws FetchFailure
     */
    public function fetchWithApiKey(string $apiKey): array
    {
        if (trim($apiKey) === '') {
            throw FetchFailure::of(FetchFailure::NO_CREDENTIALS);
        }

        [$response, $body] = $this->get('/zen/go/v1/usage', ['Authorization' => 'Bearer ' . trim($apiKey)]);
        self::judge($response);
        $payload = self::decode($body);

        if ($payload === null) {
            throw FetchFailure::of(FetchFailure::NO_PAYLOAD);
        }

        return self::metersOrFail($payload);
    }

    /**
     * The legacy console API, `GET /console/api/go/status` for the workspace in `x-org-id`, with the
     * browser's session cookie.
     *
     * @return list<UsageMeter>
     * @throws FetchFailure
     */
    public function fetchWithCookie(string $workspaceId, string $authCookie): array
    {
        if (trim($workspaceId) === '' || trim($authCookie) === '') {
            throw FetchFailure::of(FetchFailure::NO_CREDENTIALS);
        }

        [$response, $body] = $this->get('/console/api/go/status', [
            'Cookie' => self::cookieHeader($authCookie),
            'x-org-id' => trim($workspaceId),
        ]);
        self::judge($response);
        $payload = self::decode($body);

        // A literal `null`, or no `access`, is the console saying there is no plan on this workspace.
        if (self::record(self::record($payload)['access'] ?? null) === null) {
            throw FetchFailure::of(FetchFailure::NO_SUBSCRIPTION);
        }

        return self::metersOrFail($payload);
    }

    /** A bare value is the console session; a `name=value` string is sent as it is. */
    public static function cookieHeader(string $authCookie): string
    {
        $trimmed = preg_replace('/;$/', '', trim($authCookie)) ?? trim($authCookie);

        return str_contains($trimmed, '=') ? $trimmed : self::DEFAULT_COOKIE_NAME . '=' . $trimmed;
    }

    /**
     * @param array<string, string> $headers
     * @return array{0: Response, 1: string}
     */
    private function get(string $path, array $headers): array
    {
        $request = new Request('GET', rtrim($this->origin, '/') . $path, [
            ...$headers,
            'User-Agent' => self::USER_AGENT,
            'Accept' => 'application/json',
        ]);

        try {
            // `send()`, not `follow()`: a redirect is upstream's `redirect: "manual"`, judged below.
            return Retrying::fetch($this->http ?? new HttpClient(self::REQUEST_TIMEOUT), $request);
        } catch (AbortError $error) {
            throw $error;
        } catch (Throwable $error) {
            if (stripos($error->getMessage(), 'timed out') !== false) {
                throw FetchFailure::of(FetchFailure::TIMEOUT, $error);
            }

            throw FetchFailure::network($error->getMessage(), $error);
        }
    }

    /** @throws FetchFailure for a redirect, a refused key or session, or any other failed status */
    private static function judge(Response $response): void
    {
        if ($response->status >= 300 && $response->status < 400) {
            if (preg_match('/auth|login|sign-?in/i', $response->header('location') ?? '') === 1) {
                throw FetchFailure::of(FetchFailure::UNAUTHORIZED);
            }

            throw FetchFailure::http($response->status);
        }

        if ($response->status === 401 || $response->status === 403) {
            throw FetchFailure::of(FetchFailure::UNAUTHORIZED);
        }

        if (!$response->isSuccessful()) {
            throw FetchFailure::http($response->status);
        }
    }

    /** The body as JSON; one that is not JSON is the API having changed. */
    private static function decode(string $body): mixed
    {
        try {
            return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw FetchFailure::of(FetchFailure::NO_PAYLOAD, $error);
        }
    }

    /** @return list<UsageMeter> */
    private static function metersOrFail(mixed $payload): array
    {
        $meters = self::parseGoStatus($payload);

        if ($meters === []) {
            throw FetchFailure::of(FetchFailure::NO_PAYLOAD);
        }

        return $meters;
    }

    /** @return array<string, mixed>|null */
    private static function record(mixed $value): ?array
    {
        return is_array($value) ? $value : null;
    }

    /** Money fields arrive as JSON strings (the server models them as bigint). */
    private static function toNumber(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return is_finite((float) $value) ? (float) $value : null;
        }

        if (is_string($value) && trim($value) !== '' && is_numeric(trim($value))) {
            return (float) trim($value);
        }

        return null;
    }

    private static function clampPercent(float $percent): float
    {
        return round(min(100.0, max(0.0, $percent)) * 10) / 10;
    }

    /** An ISO timestamp in milliseconds since the epoch, or null — upstream's `Date.parse()`. */
    public static function parseMs(mixed $iso): ?float
    {
        if (!is_string($iso) || trim($iso) === '') {
            return null;
        }

        $date = date_create($iso);

        return $date === false ? null : (float) $date->format('U.v') * 1000;
    }

    /** Milliseconds since the epoch as `Date.prototype.toISOString()` writes them. */
    public static function isoString(float $ms): string
    {
        $date = \DateTimeImmutable::createFromFormat('U.v', sprintf('%.3F', $ms / 1000), new \DateTimeZone('UTC'));
        \assert($date !== false);

        return $date->format('Y-m-d\TH:i:s.v\Z');
    }
}
