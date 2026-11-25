<?php

declare(strict_types=1);

namespace PigOpencodeGoUsage\Test\Unit;

use PHPUnit\Framework\TestCase;
use PigOpencodeGoUsage\MeterKind;
use PigOpencodeGoUsage\UsageMeter;
use PigOpencodeGoUsage\UsagePanel;
use Pig\CodingAgent\Extensions\ExtensionApi;
use Pig\CodingAgent\Extensions\ExtensionLoader;

/** The entry as pig loads it, and the web panel's blocks. */
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
        ExtensionApi::forgetWebPanels();
        rmdir($this->home);
    }

    public function testTheEntryRegistersItsHooksCommandAndPanel(): void
    {
        [$loaded, $errors] = ExtensionLoader::load($this->home, cliPaths: [dirname(__DIR__, 2) . '/extensions/pig-opencode-go-usage.php'], home: $this->home);

        $this->assertSame([], $errors);
        $this->assertCount(1, $loaded);
        $api = $loaded[0]->api;
        $this->assertArrayHasKey('opencode-go', $api->commands());
        $this->assertCount(1, $api->handlers('session_start'));
        $this->assertCount(1, $api->handlers('session_shutdown'));
        $this->assertNotNull(ExtensionApi::webPanel(UsagePanel::ID));
        $this->assertSame(['opencode-go'], ExtensionApi::webPanel(UsagePanel::ID)->commands);
    }

    public function testThePanelDrawsWhatIsLeftAndSaysWhatIsUsed(): void
    {
        $now = 1_790_000_000_000.0;
        $blocks = UsagePanel::usageBlocks([
            new UsageMeter(MeterKind::FiveHour, 62.0, null),
            new UsageMeter(MeterKind::ProductPeriod, 50.0, null),
        ], null, 'zh-CN', nowMs: $now);

        $this->assertSame(['type' => 'meter', 'label' => '5 小时滚动', 'value' => 0.38, 'detail' => '已用 62%'], $blocks[0]);
        $this->assertSame('每月', $blocks[1]['label']);
        $this->assertSame([['type' => 'text', 'text' => 'HTTP 500', 'tone' => 'error']], UsagePanel::usageBlocks([], 'HTTP 500', 'en'));
        $this->assertSame('lazy', UsagePanel::blocks(true, 'en')[0]['type']);
        $this->assertStringContainsString('/login', UsagePanel::blocks(false, 'en')[0]['text']);
    }
}
