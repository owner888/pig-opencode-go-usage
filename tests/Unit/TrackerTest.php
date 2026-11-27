<?php

declare(strict_types=1);

namespace PigOpencodeGoUsage\Test\Unit;

use PHPUnit\Framework\TestCase;
use PigOpencodeGoUsage\Screen;
use PigOpencodeGoUsage\Tracker;
use PigOpencodeGoUsage\UsageClient;
use PigOpencodeGoUsage\Test\FakeTransport;
use Pig\Async\Async;
use Pig\CodingAgent\Usage\UsageMeter;
use Pig\CodingAgent\Usage\UsageReport;
use Pig\CodingAgent\Usage\UsageView;
use RuntimeException;

/** Upstream's `test/parse.test.ts`, the factory half: the credentials, the report pig draws, the subcommands. */
final class TrackerTest extends TestCase
{
    private string $dir;

    /** @var list<array{0: string, 1: string}> */
    private array $notices = [];

    #[\Override]
    protected function setUp(): void
    {
        // Never let a test touch the real saved file.
        $this->dir = sys_get_temp_dir() . '/opencode-go-usage-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0o700, true);
        putenv("OPENCODE_GO_CONFIG_PATH={$this->dir}/opencode-go-usage.json");
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach (['OPENCODE_GO_CONFIG_PATH', 'OPENCODE_API_KEY', 'OPENCODE_GO_WORKSPACE_ID', 'OPENCODE_GO_AUTH_COOKIE'] as $name) {
            putenv($name);
        }

        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function testTheReportIsWhatIsLeftWithTheMonthlyPaceHideable(): void
    {
        putenv('OPENCODE_API_KEY=sk-test');
        $iso = static fn (float $ms): string => UsageClient::isoString(floor(microtime(true) * 1000) + $ms);
        $tracker = new Tracker(new UsageClient(FakeTransport::always(200, json_encode(['usage' => [
            'rolling' => ['percent' => 10, 'resetsAt' => $iso(3_600_000)],
            'weekly' => ['percent' => 20, 'resetsAt' => $iso(86_400_000)],
            'monthly' => ['percent' => 50, 'resetsAt' => $iso(15 * 86_400_000)],
        ]], JSON_THROW_ON_ERROR))));

        $report = Async::run(static fn (): UsageReport => $tracker->report());
        $meters = $report->groups[0]->meters;
        $this->assertSame(['Rolling 5h', 'Weekly', 'Monthly'], array_map(static fn (UsageMeter $m): string => $m->label, $meters));
        $this->assertSame([0.9, 0.8, 0.5], array_map(static fn (UsageMeter $m): float => $m->remaining, $meters));
        $this->assertSame(['5h', 'wk', 'mo'], array_map(static fn (UsageMeter $m): ?string => $m->short, $meters));
        // Only the monthly meter is extrapolated.
        $this->assertNull($meters[1]->detail);
        $this->assertMatchesRegularExpression('/^~\d+d left$/', (string) $meters[2]->detail);
        $this->assertSame('OpenCode Go: 5h 90% · wk 80% · mo 50%', UsageView::statusLine(Tracker::TITLE, $report));

        $this->command($tracker, '--estimate off');
        $this->assertFalse(json_decode((string) file_get_contents("{$this->dir}/opencode-go-usage.json"), true)['showEstimate']);
        $report = Async::run(static fn (): UsageReport => $tracker->report());
        $this->assertNull($report->groups[0]->meters[2]->detail);
    }

    public function testWithoutCredentialsTheReportSaysNotConnected(): void
    {
        $tracker = $this->tracker(FakeTransport::always(500, ''));

        $this->assertSame(Tracker::NOT_CONNECTED, Async::run(static fn (): UsageReport => $tracker->report())->note);
        $this->command($tracker, '');
        $this->assertStringContainsString('Not connected', $this->notices[0][0]);
    }

    public function testAFailedFetchIsThrownForPigToSay(): void
    {
        putenv('OPENCODE_API_KEY=sk-test');
        $tracker = $this->tracker(FakeTransport::always(401, ''));

        try {
            Async::run(static fn (): UsageReport => $tracker->report());
            $this->fail('A refused key was reported as usage.');
        } catch (RuntimeException $error) {
            $this->assertSame('Session expired — reconnect with a fresh console cookie', $error->getMessage());
        }

        $this->command($tracker, '--refresh');
        $this->assertSame(['OpenCode Go: Session expired — reconnect with a fresh console cookie', 'error'], end($this->notices));
    }

    public function testPigsOwnSignInIsTheKeyWhenNothingElseIs(): void
    {
        $asked = [];
        $http = FakeTransport::always(200, '{"usage":{"rolling":{"percent":1}}}');
        $tracker = new Tracker(new UsageClient($http), static function (string $provider) use (&$asked): ?string {
            $asked[] = $provider;

            return $provider === 'opencode' ? 'sk-zen' : null;
        });

        $this->assertSame('sk-zen', $tracker->apiKey());
        $this->assertSame(['opencode-go', 'opencode'], $asked);
        Async::run(static fn () => $tracker->refresh());
        $this->assertSame('Bearer sk-zen', $http->requests[0]->headers['Authorization']);

        // A saved key comes before it, and the environment before that.
        $this->command($tracker, '--api-key sk-saved');
        $this->assertSame('sk-saved', $tracker->apiKey());
        putenv('OPENCODE_API_KEY=sk-env');
        $this->assertSame('sk-env', $tracker->apiKey());
    }

    public function testSavingCredentialsWarnsWhileEnvironmentVariablesShadowThem(): void
    {
        $tracker = $this->tracker(FakeTransport::always(500, ''));
        putenv('OPENCODE_GO_WORKSPACE_ID=wrk_env');
        putenv('OPENCODE_GO_AUTH_COOKIE=env-cookie');

        $this->command($tracker, '--cookie fresh-cookie');
        // The save landed in the scratch file, but the env value wins at read time, so the user is told.
        $this->assertSame('fresh-cookie', json_decode((string) file_get_contents("{$this->dir}/opencode-go-usage.json"), true)['authCookie']);
        $this->assertTrue($this->warned('OPENCODE_GO_AUTH_COOKIE'));
        $this->assertSame('0600', substr(sprintf('%o', fileperms("{$this->dir}/opencode-go-usage.json")), -4));

        $this->notices = [];
        putenv('OPENCODE_GO_WORKSPACE_ID');
        putenv('OPENCODE_GO_AUTH_COOKIE=fresh-cookie');
        $this->command($tracker, '--cookie fresh-cookie');
        $this->assertFalse($this->warned(''));
    }

    public function testConnectWithTwoTokensIsTheConsoleCookie(): void
    {
        $http = FakeTransport::always(200, '{"access":{"meters":{"week":{"usedMicroCents":"1","limitMicroCents":"4"}}}}');
        $tracker = $this->tracker($http);

        $this->command($tracker, '--connect wrk_01 Fe26.2**opaque');

        $this->assertSame('wrk_01', $http->requests[0]->headers['x-org-id']);
        $this->assertStringContainsString('Weekly: 75% left', implode("\n", array_column($this->notices, 0)));

        $this->command($tracker, '--disconnect');
        $this->assertSame(Tracker::NOT_CONNECTED, Async::run(static fn (): UsageReport => $tracker->report())->note);
    }

    public function testASavedFileThatIsNotJsonIsSaid(): void
    {
        file_put_contents("{$this->dir}/opencode-go-usage.json", '{broken');
        $tracker = $this->tracker(FakeTransport::always(500, ''));

        $this->expectExceptionMessage('is not JSON');
        $tracker->report();
    }

    private function tracker(FakeTransport $http): Tracker
    {
        $tracker = new Tracker(new UsageClient($http));
        $tracker->loadConfig();

        return $tracker;
    }

    private function command(Tracker $tracker, string $args): void
    {
        Async::run(fn () => $tracker->command($args, new Screen(function (string $message, string $level): void {
            $this->notices[] = [$message, $level];
        })));
    }

    private function warned(string $needle): bool
    {
        foreach ($this->notices as [$message, $level]) {
            if ($level === 'warning' && str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }
}
