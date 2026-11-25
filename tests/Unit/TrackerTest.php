<?php

declare(strict_types=1);

namespace PigOpencodeGoUsage\Test\Unit;

use PHPUnit\Framework\TestCase;
use PigOpencodeGoUsage\Screen;
use PigOpencodeGoUsage\Tracker;
use PigOpencodeGoUsage\UsageClient;
use PigOpencodeGoUsage\Test\FakeTransport;
use Pig\Async\Async;

/** Upstream's `test/parse.test.ts`, the factory half: the status line, the report and the subcommands. */
final class TrackerTest extends TestCase
{
    private string $dir;

    /** @var list<array{0: string, 1: ?string}> */
    private array $statuses = [];

    /** @var list<?list<string>> */
    private array $widgets = [];

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

    public function testTheMonthlyStatusGetsAPaceEstimateHideable(): void
    {
        putenv('OPENCODE_API_KEY=sk-test');
        $iso = static fn (float $ms): string => UsageClient::isoString(floor(microtime(true) * 1000) + $ms);
        $tracker = $this->tracker(FakeTransport::always(200, json_encode(['usage' => [
            'rolling' => ['percent' => 10, 'resetsAt' => $iso(3_600_000)],
            'weekly' => ['percent' => 20, 'resetsAt' => $iso(86_400_000)],
            'monthly' => ['percent' => 50, 'resetsAt' => $iso(15 * 86_400_000)],
        ]], JSON_THROW_ON_ERROR)));

        $this->command($tracker, '');
        // "mo 50% (15d 0h / ~15d left)" — only the monthly meter is extrapolated.
        $this->assertMatchesRegularExpression('#mo 50% \(\d+d \d+h / ~\d+d left\)#', $this->lastStatus());
        $this->assertDoesNotMatchRegularExpression('#wk 20% \([^)]*left#', $this->lastStatus());
        $this->assertStringContainsString('API key: connected', implode("\n", (array) end($this->widgets)));

        $this->command($tracker, '--estimate off');
        $this->assertMatchesRegularExpression('#mo 50% \(\d+d \d+h\)#', $this->lastStatus());
        $this->assertStringNotContainsString('left', $this->lastStatus());
        $this->assertFalse(json_decode((string) file_get_contents("{$this->dir}/opencode-go-usage.json"), true)['showEstimate']);

        $this->command($tracker, '--estimate on');
        $this->assertStringContainsString('left', $this->lastStatus());

        $this->command($tracker, '--compact');
        $this->assertSame('OpenCode Go: 5h 10% · wk 20% · mo 50%', $this->lastStatus());
    }

    public function testWithoutCredentialsTheReportSaysNotConnected(): void
    {
        $tracker = $this->tracker(FakeTransport::always(500, ''));
        $this->command($tracker, '');

        $this->assertSame('OpenCode Go Usage', $this->widgets[0][0]);
        $this->assertStringContainsString('Not connected', implode("\n", $this->widgets[0]));
        $this->assertStringContainsString('not connected', (string) $this->statuses[0][1]);
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
        Async::run(fn () => $tracker->refresh($this->screen()));
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
        $http = FakeTransport::always(401, '');
        $tracker = $this->tracker($http);

        $this->command($tracker, '--connect wrk_01 Fe26.2**opaque');

        $this->assertSame('wrk_01', $http->requests[0]->headers['x-org-id']);
        $this->assertSame('OpenCode Go: Session expired — reconnect with a fresh console cookie', $this->lastStatus());
        $this->assertContains('Workspace: wrk_01', (array) end($this->widgets));

        $this->command($tracker, '--disconnect');
        $this->assertNull(end($this->widgets));
        $this->assertSame('OpenCode Go: not connected (/opencode-go --connect)', $this->lastStatus());
    }

    public function testASavedFileThatIsNotJsonIsSaid(): void
    {
        file_put_contents("{$this->dir}/opencode-go-usage.json", '{broken');
        $tracker = $this->tracker(FakeTransport::always(500, ''));
        $tracker->loadConfig();

        $this->assertStringContainsString('is not JSON', $tracker->statusText());
    }

    private function tracker(FakeTransport $http): Tracker
    {
        $tracker = new Tracker(new UsageClient($http));
        $tracker->loadConfig();

        return $tracker;
    }

    private function command(Tracker $tracker, string $args): void
    {
        Async::run(fn () => $tracker->command($args, $this->screen()));
    }

    private function screen(): Screen
    {
        return new Screen(
            function (string $key, ?string $text): void {
                $this->statuses[] = [$key, $text];
            },
            function (string $key, ?array $lines): void {
                $this->widgets[] = $lines;
            },
            function (string $message, string $level): void {
                $this->notices[] = [$message, $level];
            },
        );
    }

    private function lastStatus(): string
    {
        return (string) end($this->statuses)[1];
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
