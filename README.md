# pig-opencode-go-usage

OpenCode Go usage for the [pig](https://github.com/owner888/pig) coding agent: the **rolling 5-hour, weekly and monthly** limits on the status line, in a `/opencode-go` report, and in a panel in `pig web`. A PHP port of [pi-opencode-go-usage](https://github.com/Dakai/pi-opencode-go-usage), in step with its 1.1.3.

[简体中文](README.zh-CN.md)

```
Status line:  OpenCode Go: 5h 62% (1h 12m) · wk 31% (3d 4h) · mo 44% (12d 0h / ~15d left) · update 2:32 PM (Asia/Singapore)

/opencode-go:
  OpenCode Go Usage
  API key: connected
  Rolling 5h ██████░░░░  62% · 1h 12m
  Weekly     ███░░░░░░░  31% · 3d 4h
  Monthly    ████░░░░░░  44% · 12d 0h / ~15d left
  update 2:32 PM (Asia/Singapore)
```

The monthly countdown is followed by an estimate: at the pace so far, the quota lasts about 15 more days.

## Install

```bash
pig install git:github.com/owner888/pig-opencode-go-usage
```

No Composer dependencies.

## Connect

If you signed in to OpenCode Go in pig (`/login`, then OpenCode Go or OpenCode Zen — the same OpenCode API key), there is nothing to do. The key is taken from the first of these that has one:

1. `OPENCODE_GO_API_KEY`
2. `OPENCODE_API_KEY`
3. `OPENCODE_ZEN_API_KEY`
4. the key saved with `/opencode-go --connect <api-key>`
5. pig's sign-in for `opencode-go`, then `opencode`

With a key the usage comes from `GET https://opencode.ai/zen/go/v1/usage`.

Without one, the console's own API works with your browser session, as upstream allows:

1. **Workspace ID** — the `wrk_…` in `opencode.ai/console/`**`wrk_…`**`/go`
2. **Session cookie** — on that page, F12 → Application → Cookies → `https://opencode.ai` → the `__Host-console_session` row's value

```
/opencode-go --connect wrk_… <session-cookie-value>
```

or `OPENCODE_GO_WORKSPACE_ID` and `OPENCODE_GO_AUTH_COOKIE`. A bare value is sent as `__Host-console_session=<value>`; a `name=value; name2=value2` string is sent as it is.

Environment variables win over what is saved; `/opencode-go` warns when one shadows what you just saved. What is saved lives in `~/.pig/agent/opencode-go-usage.json` (mode `0600`), or wherever `OPENCODE_GO_CONFIG_PATH` says.

## Commands

| Command | Effect |
| --- | --- |
| `/opencode-go` | Fetch and show the report |
| `/opencode-go --connect <api-key>` | Save the API key, fetch, show |
| `/opencode-go --api-key <api-key>` | Save the API key only |
| `/opencode-go --connect <wrk> <cookie>` | Save workspace and cookie, fetch, show |
| `/opencode-go --workspace <id>` | Save the workspace only |
| `/opencode-go --cookie <value>` | Save the cookie only |
| `/opencode-go --disconnect` | Forget what was saved |
| `/opencode-go --refresh` | Fetch again now |
| `/opencode-go --compact [on\|off]` | Shorter status line (`5h 0% · wk 2% · mo 2%`) |
| `/opencode-go --estimate [on\|off]` | Show or hide the monthly days-left estimate |
| `/opencode-go --json` | Write the report to `~/.pig/agent/opencode-go-usage-report.json` |

In the terminal the status line refreshes every five minutes. In `pig web`, the OpenCode Go panel is in the header's ⋯ menu, and `/opencode-go` opens it; it fetches when opened.

## When it fails

| Status text | Meaning | Fix |
| --- | --- | --- |
| `Session expired` | The key or console session was refused | Reconnect with a fresh key or cookie |
| `No Go subscription on this workspace` | The console reports no Go plan | Check the workspace |
| `Console API response unrecognised` | The API changed shape | Update this package |
| `Network error` / `Request timed out` / `HTTP 5xx` | Transient, after two tries again | Wait, or `--refresh` |

## Security

An authenticated read of your own usage, with an API key or a browser session cookie kept in a `0600` file or in environment variables. It shows only what the endpoint shows; when the API changes it says so rather than showing a confident zero.

## License

MIT. See [NOTICE](NOTICE) for what comes from pi-opencode-go-usage.
