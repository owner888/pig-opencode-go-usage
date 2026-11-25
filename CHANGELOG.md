# Changelog

What changed, newest first, in pig's format: `## Unreleased` while working, `## [x.y.z] - YYYY-MM-DD`
at release. The version lives in the git tag only, and follows the pi-opencode-go-usage release the
port is in step with.

## Unreleased

### Changed

- The usage request made with an API key says it is pig (`pig/<version> (…)`), the `User-Agent` pig's model requests to OpenCode carry, instead of upstream's Chrome one, so one key does not show up as two clients. The console-cookie request still sends the browser's, since it uses the browser's session.

### Fixed

- The "update" time is in the machine's time zone (`TZ`, else the zone `/etc/localtime` names), as upstream's is; it was PHP's default, which is UTC on a PHP with no `date.timezone`.
- The status line is drawn in the theme's `dim`, as the footer's own lines are, instead of as the one bright line there. In the terminal only; an RPC host still gets it plain.

## [1.1.3] - 2026-11-26

In step with pi-opencode-go-usage 1.1.3. The first release.

### New Features

- OpenCode Go's rolling 5-hour, weekly and monthly usage on pig's status line, refreshed every five minutes in the terminal, with reset countdowns and, for the month, the days the quota lasts at the pace so far (`/opencode-go --estimate off` hides it, `--compact` shortens the line).
- `/opencode-go` draws the report above the editor; `--connect`, `--api-key`, `--workspace`, `--cookie`, `--disconnect`, `--refresh` and `--json` as upstream has them. The API key's endpoint (`/zen/go/v1/usage`) first, the console cookie (`/console/api/go/status`) for anyone without a key.

### Added

- The key pig already has is used: after `OPENCODE_GO_API_KEY`, `OPENCODE_API_KEY`, `OPENCODE_ZEN_API_KEY` and the saved key, pig's own sign-in for `opencode-go` and then `opencode` (`/login`), so a person who uses OpenCode Go in pig has nothing to set up.
- A `pig web` panel (the header's ⋯ menu, or `/opencode-go` in the page) with the three meters, in English and Chinese, because the page draws no status line.
- The request goes through pig's `Retrying::fetch()`, so a dropped connection or a 5xx is asked again before it shows as an error.
