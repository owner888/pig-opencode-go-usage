<?php

declare(strict_types=1);

/*
 * The tests run against a pig checkout: its Composer autoloader brings PHPUnit and pig's own
 * classes. Where pig is: $PIG_DIR, else a `pig` directory next to this package.
 *
 *     PIG_DIR=~/Development/owner/pig ~/Development/owner/pig/vendor/bin/phpunit -c phpunit.xml
 *
 * HOME is pointed at a throwaway directory for the run, so nothing a test does can write under the
 * developer's real ~/.pig, and the variables a key or a cookie is read from are cleared, so the
 * machine's own cannot decide a test.
 */

$pig = getenv('PIG_DIR') ?: dirname(__DIR__, 2) . '/pig';
$autoload = rtrim($pig, '/') . '/vendor/autoload.php';

if (!is_file($autoload)) {
    fwrite(STDERR, "pig-opencode-go-usage tests need a pig checkout with vendor/ installed; set PIG_DIR (tried {$pig}).\n");
    exit(1);
}

require $autoload;

spl_autoload_register(static function (string $class): void {
    foreach (['PigOpencodeGoUsage\\Test\\' => __DIR__ . '/', 'PigOpencodeGoUsage\\' => dirname(__DIR__) . '/src/'] as $prefix => $directory) {
        if (str_starts_with($class, $prefix)) {
            $file = $directory . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

            if (is_file($file)) {
                require_once $file;
            }

            return;
        }
    }
});

$home = sys_get_temp_dir() . '/pig-opencode-go-usage-test-home-' . getmypid() . '-' . bin2hex(random_bytes(3));
mkdir($home . '/.pig/agent', 0o700, true);
putenv("HOME={$home}");
$_ENV['HOME'] = $_SERVER['HOME'] = $home;
putenv('PIG_CODING_AGENT_DIR');

foreach (['OPENCODE_GO_API_KEY', 'OPENCODE_API_KEY', 'OPENCODE_ZEN_API_KEY', 'OPENCODE_GO_WORKSPACE_ID', 'OPENCODE_GO_AUTH_COOKIE', 'OPENCODE_GO_CONFIG_PATH'] as $name) {
    putenv($name);
}

register_shutdown_function(static function () use ($home): void {
    exec('rm -rf ' . escapeshellarg($home));
});
