<?php

declare(strict_types=1);

namespace PigOpencodeGoUsage\Test\Unit;

use PHPUnit\Framework\TestCase;
use Pig\CodingAgent\Extensions\ExtensionApi;
use Pig\CodingAgent\Extensions\ExtensionLoader;
use Pig\CodingAgent\Usage\UsagePanel;
use PigOpencodeGoUsage\Tracker;

/** The entry as pig loads it: a usage source with a footer line, and `/opencode-go`. */
final class ExtensionTest extends TestCase
{
    private string $home;

    #[\Override]
    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir() . '/pig-ocgo-ext-' . bin2hex(random_bytes(4));
        mkdir($this->home, 0o700, true);
    }

    #[\Override]
    protected function tearDown(): void
    {
        ExtensionApi::forgetUsageSources();
        ExtensionApi::forgetWebPanels();
        rmdir($this->home);
    }

    public function testTheEntryRegistersItsUsageAndCommand(): void
    {
        [$loaded, $errors] = ExtensionLoader::load($this->home, cliPaths: [dirname(__DIR__, 2) . '/extensions/pig-opencode-go-usage.php'], home: $this->home);

        $this->assertSame([], $errors);
        $this->assertCount(1, $loaded);
        $this->assertArrayHasKey(Tracker::KEY, $loaded[0]->api->commands());

        $source = ExtensionApi::usageSource(Tracker::KEY);
        $this->assertNotNull($source);
        $this->assertSame('OpenCode Go', $source->titleFor());
        $this->assertTrue($source->status);
        // pig's own Usage panel, not one of the package's.
        $this->assertNotNull(ExtensionApi::webPanel(UsagePanel::ID));
        $this->assertCount(1, ExtensionApi::webPanels());
    }
}
