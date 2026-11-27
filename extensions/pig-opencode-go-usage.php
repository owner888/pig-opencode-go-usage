<?php

declare(strict_types=1);

/**
 * OpenCode Go usage — rolling 5 hours, weekly and monthly — as pig draws every plan's usage
 * (`registerUsage()`): a line in the footer, `/usage`, and the Usage panel in `pig web`. Plus
 * `/opencode-go` with its subcommands for connecting. A PHP port of pi-opencode-go-usage
 * (`extensions/opencode-go-usage.ts`); `src/Tracker.php` is the body of its factory,
 * `src/UsageClient.php` its fetch and parse.
 *
 * Where the wiring is pig's: upstream sets its own status line on a five-minute timer from
 * `session_start` and draws its own report widget; here pig does both for it, at the same five
 * minutes, and the timer runs in the terminal only. A refresh that fails is pig's to say.
 */

use Pig\CodingAgent\Auth;
use Pig\CodingAgent\Extensions\ExtensionApi;
use Pig\CodingAgent\Extensions\ExtensionContext;
use PigOpencodeGoUsage\Screen;
use PigOpencodeGoUsage\Tracker;

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

    $pig->registerUsage(Tracker::KEY, Tracker::TITLE, static fn () => $tracker->report(), status: true);

    $pig->registerCommand(
        Tracker::KEY,
        static function (string $args, ExtensionContext $ctx) use ($tracker): void {
            $tracker->loadConfig();
            $tracker->command($args, Screen::of($ctx->ui, $ctx->hasUi && $ctx->mode() === 'tui'));
        },
        Tracker::COMMAND_DESCRIPTION,
    );
};
