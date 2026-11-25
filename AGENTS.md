# pig-opencode-go-usage — notes for agents

OpenCode Go usage for pig, as a pig package: a PHP port of
[pi-opencode-go-usage](https://github.com/Dakai/pi-opencode-go-usage). `README.md` says what it
does; this says how it is worked on. Keep this file under 100 lines.

## What follows what

- **Upstream is `extensions/opencode-go-usage.ts`, one file, split here by what it does.**
  `UsageClient` is `parseGoStatus()`, `fetchUsageApiKey()` and the cookie half of `fetchUsage()`;
  `Format` is `bar()`, `countdown()`, `daysLeftEstimate()` and `formatDaysLeft()`; `FetchFailure` is
  the `FetchFailure` union and `describeFailure()`; `SavedConfig` is `Config` with `loadConfig()` /
  `saveConfig()`; `Tracker` is the body of the default-export factory; `MeterKind` and `UsageMeter`
  are its types. `test/parse.test.ts` is `tests/Unit/{UsageClient,Format,Tracker}Test.php`. A
  difference from upstream is a bug unless the code's own comment records it as the developer's
  choice.
- **When pi-opencode-go-usage releases**: read the diff since the release this port is in step
  with (named in `CHANGELOG.md`), port it, and move that name. The package's version follows
  upstream's.
- Strings the person reads (status line, report, notifications) are upstream's, word for word,
  except where a comment says otherwise.

## Where this is pig's on purpose

- **Keys**: after upstream's three variables and the saved key, pig's own sign-in for
  `opencode-go`, then `opencode` (`Tracker::apiKey()`).
- **Files**: `~/.pig/agent/opencode-go-usage.json` (`0600`) and `opencode-go-usage-report.json`;
  `OPENCODE_GO_CONFIG_PATH` still overrides the first. A saved file that cannot be read or is not
  JSON is said on the status line, not read as empty.
- **The timer runs in the terminal only** (`mode() === 'tui'`), and a refresh that throws is logged.
- **The web panel** (`UsagePanel`) is pig's own: the page draws no status line. Its meters are
  what is left, as the page draws every quota; the text says what is used.
- **Requests** go through `Pig\Ai\Http\Retrying::fetch()` with `send()`, never `follow()`: a
  redirect is judged, as upstream's `redirect: "manual"` is.
- Upstream's aliases (`parseUsage`, `fetchGoUsage`, …) and its overloaded `fetchUsage()` are not
  ported: `fetchWithApiKey()` and `fetchWithCookie()` say which is which.

## Rules

- No silent fallback: `catch` re-throws or turns the failure into what the person reads. No `@`
  suppression (`SavedConfig::quietly()` keeps a warning's text). `declare(strict_types=1)`, one
  class per file.
- Code that sends takes `Pig\Ai\Http\HttpTransport`, so a test hands it `tests/FakeTransport.php`.
- A key or a cookie is never logged, written into an error, or put in a fixture that looks real.
- The developer's name does not appear in this repository; write "the developer".
- No composer dependencies. A git-installed package is not run through Composer, so
  `extensions/pig-opencode-go-usage.php` registers the autoloader itself.
- `README.md` and `README.zh-CN.md` are one document: a change to one lands in the other.
- Git (commit, tag, push) is the developer's; report which files changed.

## Tests

`PIG_DIR=~/Development/owner/pig ~/Development/owner/pig/vendor/bin/phpunit -c phpunit.xml` —
`tests/bootstrap.php` points HOME at a throwaway directory and clears the `OPENCODE_*` variables.

## Changelog

`CHANGELOG.md` in pig's format: `## Unreleased` while working, `## [x.y.z] - YYYY-MM-DD` at
release; the version lives in the git tag only.
