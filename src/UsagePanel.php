<?php

declare(strict_types=1);

namespace PigOpencodeGoUsage;

/**
 * The usage in `pig web`, as a panel — pig's own; upstream has no web page, and the page does not
 * draw an extension's status line. It sits where pig-antigravity's quota is (the header's ⋯ menu),
 * and `/opencode-go` typed in the page opens it.
 *
 * A meter's bar is what is left, as the page draws every quota; the text beside it says how much
 * is used, as the status line does, and when it fills again.
 */
final class UsagePanel
{
    public const string ID = 'opencode-go';

    /** The section drawn after the rest, because it waits on the network. */
    public const string USAGE = 'usage';

    public const array TITLE = ['en' => 'OpenCode Go Usage', 'zh-CN' => 'OpenCode Go 用量'];

    public const array LABEL = ['en' => 'OpenCode Go', 'zh-CN' => 'OpenCode Go'];

    private const array STRINGS = [
        'en' => [
            'five_hour' => 'Rolling 5h',
            'calendar_week' => 'Weekly',
            'product_period' => 'Monthly',
            'fetching' => 'Fetching…',
            'not_connected' => 'Not connected. Run /login and choose OpenCode Go, or /opencode-go --connect <api-key> in the terminal.',
            'used' => '{p}% used',
            'resets' => 'resets in {t}',
            'resets_now' => 'resets now',
            'left' => '{d} left at this pace',
            'used_up' => 'quota used up',
            'less_than_a_day' => 'under a day',
            'days' => '~{d}d',
        ],
        'zh-CN' => [
            'five_hour' => '5 小时滚动',
            'calendar_week' => '每周',
            'product_period' => '每月',
            'fetching' => '正在获取用量…',
            'not_connected' => '未连接。请在终端执行 /login 选择 OpenCode Go，或 /opencode-go --connect <api-key>。',
            'used' => '已用 {p}%',
            'resets' => '{t} 后重置',
            'resets_now' => '立即重置',
            'left' => '按当前速度还够 {d}',
            'used_up' => '额度已用完',
            'less_than_a_day' => '不到 1 天',
            'days' => '约 {d} 天',
        ],
    ];

    /** The panel before the network is asked: the usage drawn later, or why there is none. */
    public static function blocks(bool $connected, string $locale): array
    {
        if (!$connected) {
            return [['type' => 'text', 'text' => self::say($locale, 'not_connected'), 'tone' => 'muted']];
        }

        return [['type' => 'lazy', 'section' => self::USAGE, 'placeholder' => self::say($locale, 'fetching')]];
    }

    /**
     * The meters after a refresh, or its error.
     *
     * @param list<UsageMeter> $meters
     * @return list<array<string, mixed>>
     */
    public static function usageBlocks(array $meters, ?string $error, string $locale, bool $showEstimate = true, ?float $nowMs = null): array
    {
        if ($error !== null) {
            return [['type' => 'text', 'text' => $error, 'tone' => 'error']];
        }

        $nowMs ??= Format::nowMs();

        return array_map(static function (UsageMeter $meter) use ($locale, $showEstimate, $nowMs): array {
            $detail = [self::say($locale, 'used', ['p' => Format::percent($meter->percent)])];
            $countdown = Format::countdown($meter->resetsAt, $nowMs);

            if ($countdown !== null) {
                $detail[] = $countdown === 'resets now' ? self::say($locale, 'resets_now') : self::say($locale, 'resets', ['t' => $countdown]);
            }

            if ($showEstimate && $meter->kind === MeterKind::ProductPeriod) {
                $days = Format::daysLeftEstimate($meter->percent, $meter->resetsAt, $nowMs);

                if ($days !== null) {
                    $detail[] = $days <= 0 ? self::say($locale, 'used_up') : self::say($locale, 'left', [
                        'd' => $days < 1 ? self::say($locale, 'less_than_a_day') : self::say($locale, 'days', ['d' => (int) round($days)]),
                    ]);
                }
            }

            return [
                'type' => 'meter',
                'label' => self::say($locale, $meter->kind->value),
                'value' => (100 - $meter->percent) / 100,
                'detail' => implode(' · ', $detail),
            ];
        }, $meters);
    }

    /** A string in `$locale` — Chinese for any `zh`, else English — with its `{params}` filled in. */
    public static function say(string $locale, string $key, array $params = []): string
    {
        $strings = str_starts_with(strtolower($locale), 'zh') ? self::STRINGS['zh-CN'] : self::STRINGS['en'];
        $text = $strings[$key];

        foreach ($params as $name => $value) {
            $text = str_replace('{' . $name . '}', (string) $value, $text);
        }

        return $text;
    }
}
