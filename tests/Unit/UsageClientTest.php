<?php

declare(strict_types=1);

namespace PigOpencodeGoUsage\Test\Unit;

use PHPUnit\Framework\TestCase;
use PigOpencodeGoUsage\FetchFailure;
use PigOpencodeGoUsage\MeterKind;
use PigOpencodeGoUsage\UsageClient;
use PigOpencodeGoUsage\UsageMeter;
use PigOpencodeGoUsage\Test\FakeTransport;
use Pig\Async\Async;

/** Upstream's `test/parse.test.ts`, the parse and fetch half, and the API-key endpoint. */
final class UsageClientTest extends TestCase
{
    /**
     * Shape of `GET /console/api/go/status`: micro-cents are bigints, so they arrive as JSON
     * strings; `month` carries no rolling window.
     */
    private const array FIXTURE = [
        'subscriberUserID' => 'usr_01ABC',
        'cancelAtPeriodEnd' => false,
        'renewalPending' => false,
        'access' => [
            'startsAt' => '2026-09-01T00:00:00.000Z',
            'endsAt' => '2026-10-01T00:00:00.000Z',
            'cancelAtPeriodEnd' => false,
            'meters' => [
                'fiveHour' => [
                    'startsAt' => '2026-09-19T10:00:00.000Z',
                    'resetsAt' => '2026-09-19T15:00:00.000Z',
                    'limitMicroCents' => '1200000000',
                    'usedMicroCents' => '744000000',
                ],
                'week' => [
                    'startsAt' => '2026-09-14T00:00:00.000Z',
                    'resetsAt' => '2026-09-21T00:00:00.000Z',
                    'limitMicroCents' => 8000000000,
                    'usedMicroCents' => 2480000000,
                ],
                'month' => ['limitMicroCents' => '20000000000', 'usedMicroCents' => '8800000000'],
            ],
        ],
    ];

    public function testParseMapsTheThreeConsoleWindows(): void
    {
        $meters = UsageClient::parseGoStatus(self::FIXTURE);
        $this->assertSame([MeterKind::FiveHour, MeterKind::CalendarWeek, MeterKind::ProductPeriod], array_map(static fn (UsageMeter $m) => $m->kind, $meters));
        [$five, $week, $month] = $meters;
        $this->assertSame(62.0, $five->percent);
        $this->assertSame('2026-09-19T15:00:00.000Z', $five->resetsAt);
        // Numeric and string micro-cent encodings both count; each window keeps its own reset.
        $this->assertSame(31.0, $week->percent);
        $this->assertSame('2026-09-21T00:00:00.000Z', $week->resetsAt);
        $this->assertSame(44.0, $month->percent);
        // The month meter has no window: the console shows the paid period end.
        $this->assertSame('2026-10-01T00:00:00.000Z', $month->resetsAt);
    }

    public function testParseLeavesTheMonthResetNullWithoutAPeriodEnd(): void
    {
        $meters = UsageClient::parseGoStatus(['access' => ['meters' => ['month' => ['limitMicroCents' => '20000000000', 'usedMicroCents' => '8800000000']]]]);
        $this->assertEquals([new UsageMeter(MeterKind::ProductPeriod, 44.0, null)], $meters);
    }

    public function testParseAlwaysLandsOnAFinitePercentage(): void
    {
        $meters = UsageClient::parseGoStatus(['access' => ['meters' => ['fiveHour' => ['limitMicroCents' => '0', 'usedMicroCents' => '500']]]]);
        $this->assertCount(1, $meters);
        $this->assertSame(0.0, $meters[0]->percent);
        $this->assertNull($meters[0]->resetsAt);
    }

    public function testParseReportsNothingWithoutMeterWindows(): void
    {
        $this->assertSame([], UsageClient::parseGoStatus(null));
        $this->assertSame([], UsageClient::parseGoStatus([]));
        $this->assertSame([], UsageClient::parseGoStatus(['access' => []]));
        $this->assertSame([], UsageClient::parseGoStatus(['access' => ['meters' => ['fiveHour' => ['unlimited' => true]]]]));
    }

    public function testParseReadsTheApiKeyShape(): void
    {
        $meters = UsageClient::parseGoStatus(['usage' => [
            'rolling' => ['status' => 'ok', 'percent' => 62, 'resetsAt' => '2026-09-19T15:00:00.000Z'],
            'weekly' => ['status' => 'ok', 'percent' => '31.04', 'resetsAt' => '2026-09-21T00:00:00.000Z'],
            'monthly' => ['status' => 'ok', 'percent' => 140, 'resetsAt' => null],
        ]]);

        $this->assertEquals([
            new UsageMeter(MeterKind::FiveHour, 62.0, '2026-09-19T15:00:00.000Z'),
            new UsageMeter(MeterKind::CalendarWeek, 31.0, '2026-09-21T00:00:00.000Z'),
            new UsageMeter(MeterKind::ProductPeriod, 100.0, null),
        ], $meters);
    }

    public function testFetchWithCookieAsksTheConsoleForThisWorkspace(): void
    {
        $http = FakeTransport::always(200, json_encode(self::FIXTURE, JSON_THROW_ON_ERROR));
        $meters = Async::run(static fn () => (new UsageClient($http))->fetchWithCookie('wrk_01TEST', 'Fe26.2**opaque'));

        $this->assertSame('https://opencode.ai/console/api/go/status', $http->requests[0]->url);
        $this->assertSame('wrk_01TEST', $http->requests[0]->headers['x-org-id']);
        $this->assertSame('__Host-console_session=Fe26.2**opaque', $http->requests[0]->headers['Cookie']);
        // The browser's session, sent as the browser.
        $this->assertSame(UsageClient::BROWSER_USER_AGENT, $http->requests[0]->headers['User-Agent']);
        $this->assertSame([62.0, 31.0, 44.0], array_map(static fn (UsageMeter $m) => $m->percent, $meters));
    }

    public function testFetchWithApiKeySendsItAsABearerToken(): void
    {
        $http = FakeTransport::always(200, '{"usage":{"rolling":{"status":"ok","percent":10,"resetsAt":"2026-09-19T15:00:00.000Z"}}}');
        $meters = Async::run(static fn () => (new UsageClient($http))->fetchWithApiKey(' sk-test '));

        $this->assertSame('https://opencode.ai/zen/go/v1/usage', $http->requests[0]->url);
        $this->assertSame('Bearer sk-test', $http->requests[0]->headers['Authorization']);
        $this->assertSame('GET', $http->requests[0]->method);
        // pig's own, as its model requests to OpenCode say, not upstream's Chrome.
        $this->assertSame(\Pig\Ai\Utils\PigUserAgent::get(), $http->requests[0]->headers['User-Agent']);
        $this->assertStringStartsWith('pig', $http->requests[0]->headers['User-Agent']);
        $this->assertEquals([new UsageMeter(MeterKind::FiveHour, 10.0, '2026-09-19T15:00:00.000Z')], $meters);
    }

    public function testFetchSurfacesAnExpiredSessionAndAnUnsubscribedWorkspace(): void
    {
        $this->assertFailure(FetchFailure::UNAUTHORIZED, FakeTransport::always(401, '{"_tag":"Unauthorized"}'), cookie: true);
        $this->assertFailure(FetchFailure::NO_SUBSCRIPTION, FakeTransport::always(200, 'null'), cookie: true);
        $this->assertFailure(FetchFailure::NO_PAYLOAD, FakeTransport::always(200, '{"access":{}}'), cookie: true);
    }

    public function testFetchJudgesRedirectsStatusesAndBodies(): void
    {
        $this->assertFailure(FetchFailure::UNAUTHORIZED, FakeTransport::always(302, '', ['Location' => '/auth/authorize']));
        $this->assertFailure(FetchFailure::HTTP, FakeTransport::always(302, '', ['Location' => '/elsewhere']));
        $this->assertFailure(FetchFailure::UNAUTHORIZED, FakeTransport::always(403, ''));
        $this->assertFailure(FetchFailure::HTTP, FakeTransport::always(404, 'Not Found'));
        $this->assertFailure(FetchFailure::NO_PAYLOAD, FakeTransport::always(200, '<html>'));
        $this->assertFailure(FetchFailure::NO_PAYLOAD, FakeTransport::always(200, 'null'));
        $this->assertFailure(FetchFailure::NO_CREDENTIALS, FakeTransport::always(200, '{}'), key: '  ');
    }

    public function testABusyServerIsAskedAgainBeforeItFails(): void
    {
        $http = new FakeTransport([[503, 'busy'], [200, '{"usage":{"weekly":{"percent":5}}}']]);
        $meters = Async::run(static fn () => (new UsageClient($http))->fetchWithApiKey('sk-test'));

        $this->assertCount(2, $http->requests);
        $this->assertSame(5.0, $meters[0]->percent);
    }

    public function testAFailureSaysWhatUpstreamSays(): void
    {
        $this->assertSame('HTTP 404', FetchFailure::http(404)->getMessage());
        $this->assertSame('Network error: refused', FetchFailure::network('refused')->describe());
        $this->assertSame('No Go subscription on this workspace', FetchFailure::of(FetchFailure::NO_SUBSCRIPTION)->describe());
    }

    public function testACookieIsSentAsGiven(): void
    {
        $this->assertSame('__Host-console_session=abc', UsageClient::cookieHeader(' abc; '));
        $this->assertSame('a=1; b=2', UsageClient::cookieHeader('a=1; b=2;'));
    }

    private function assertFailure(string $kind, FakeTransport $http, bool $cookie = false, string $key = 'sk-test'): void
    {
        $client = new UsageClient($http);

        try {
            Async::run(static fn () => $cookie ? $client->fetchWithCookie('wrk_01TEST', 'fresh') : $client->fetchWithApiKey($key));
            $this->fail("Expected {$kind}.");
        } catch (FetchFailure $failure) {
            $this->assertSame($kind, $failure->kind);
        }
    }
}
