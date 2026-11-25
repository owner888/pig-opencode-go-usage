<?php

declare(strict_types=1);

/**
 * OpenCode Go usage — rolling 5 hours, weekly and monthly — on pig's status line, in a
 * `/opencode-go` report above the editor, and in a panel in `pig web`. A PHP port of
 * pi-opencode-go-usage (`extensions/opencode-go-usage.ts`); `src/Tracker.php` is the body of its
 * factory, `src/UsageClient.php` its fetch and parse, `src/Format.php` its bars and countdowns.
 *
 * Where the wiring is pig's:
 *
 * - **The refresh every five minutes runs in the terminal only** (`$ctx->mode() === 'tui'`), where a
 *   status line is drawn. Upstream starts it in every mode; print and JSON runs are over before it
 *   fires, and each `pig web` session would poll for a line the page does not show — the page has
 *   the panel, which asks when it is opened.
 * - **A refresh that throws is logged**, not dropped: upstream's `.catch(() => {})` would leave an
 *   unobserved failure in pig's loop.
 * - **The web panel** is pig's own (`UsagePanel`), where pig-antigravity's quota is.
 */

use Pig\Async\Async;
use Pig\Async\Loop;
use Pig\CodingAgent\Auth;
use Pig\CodingAgent\Extensions\ExtensionApi;
use Pig\CodingAgent\Extensions\ExtensionContext;
use Pig\CodingAgent\Logger;
use PigOpencodeGoUsage\Screen;
use PigOpencodeGoUsage\Tracker;
use PigOpencodeGoUsage\UsagePanel;

// A package installed from git is not run through Composer, so nothing autoloads its classes;
// this registers the loader for the package's own namespace, once.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'PigOpencodeGoUsage\\')) {
        $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 19)) . '.php';

        if (is_file($file)) {
            require_once $file;
        }
    }
});

return function (ExtensionApi $pig): void {
    $auth = $pig->auth() ?? Auth::discover();
    $tracker = new Tracker(signedInKey: static fn (string $provider): ?string => $auth->apiKey($provider));
    $timer = null;

    $refresh = static function (?Screen $screen) use ($tracker): void {
        try {
            $tracker->refresh($screen);
        } catch (Throwable $error) {
            Logger::warning('OpenCode Go usage refresh failed: ' . $error->getMessage());
        }
    };

    $stop = static function () use (&$timer): void {
        if ($timer !== null) {
            Loop::get()->cancel($timer);
            $timer = null;
        }
    };

    $pig->on('session_start', static function (mixed $event, ExtensionContext $ctx) use ($tracker, $refresh, $stop, &$timer): void {
        $tracker->loadConfig();
        $stop();

        if (!$ctx->hasUi || $ctx->mode() !== 'tui') {
            return;
        }

        $screen = Screen::of($ctx->ui, dim: true);

        if ($tracker->apiKey() !== null || $tracker->credentials() !== null) {
            $screen->notify('OpenCode Go usage tracker loaded');
        }

        // In the background, so startup does not wait for the network; then again every five minutes.
        $tick = static function () use (&$tick, &$timer, $refresh, $screen): void {
            Async::spawn(static fn () => $refresh($screen));
            $timer = Loop::get()->delay(Tracker::REFRESH_SECONDS, static fn () => $tick());
        };
        $tick();
    });

    $pig->on('session_shutdown', static function () use ($stop): void {
        $stop();
    });

    $pig->registerCommand(
        Tracker::KEY,
        static function (string $args, ExtensionContext $ctx) use ($tracker): void {
            $tracker->command($args, Screen::of($ctx->ui, dim: $ctx->hasUi && $ctx->mode() === 'tui'));
        },
        Tracker::COMMAND_DESCRIPTION,
    );

    $pig->registerWebPanel(
        UsagePanel::ID,
        UsagePanel::TITLE,
        static function (array $request) use ($tracker): array {
            // The page's server outlives any one session: what was saved is read again each time.
            $tracker->loadConfig();
            $connected = $tracker->apiKey() !== null || $tracker->credentials() !== null;

            if ($request['section'] !== UsagePanel::USAGE) {
                return UsagePanel::blocks($connected, $request['locale']);
            }

            $tracker->refresh();

            return UsagePanel::usageBlocks($tracker->meters(), $tracker->lastError(), $request['locale'], $tracker->config()->showEstimate !== false);
        },
        icon: '📊',
        commands: [Tracker::KEY],
        placement: 'header',
        label: UsagePanel::LABEL,
    );
};
