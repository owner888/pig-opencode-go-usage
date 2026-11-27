# pig-opencode-go-usage

给 [pig](https://github.com/owner888/pig) 编程智能体用的 OpenCode Go 用量显示：**5 小时滚动、每周、每月**三个限额，显示在 pig 的底部状态栏、`/usage` 和 `pig web` 的「用量」面板里。由 pig 自己绘制（`registerUsage()`），和其他套餐的用量长得一样。它是 [pi-opencode-go-usage](https://github.com/Dakai/pi-opencode-go-usage) 的 PHP 移植，跟随其版本同步（当前 1.1.3）。

[English](README.md)

```
状态栏：  OpenCode Go: 5h 38% · wk 69% · mo 56%

/usage opencode-go：
  OpenCode Go

    [████████------------] 5-hour limit: 38% left · resets 1h 12m
    [██████████████------] Weekly limit: 69% left · resets 3d 4h
    [███████████---------] Monthly limit: 56% left · resets 12d 0h · lasts ~15d at this pace
```

每根条显示的是剩余额度，颜色也按剩余多少来定。月度那行最后是估算：按目前的用量速度，额度大约还够用 15 天。

## 安装

```bash
pig install git:github.com/owner888/pig-opencode-go-usage
```

没有 Composer 依赖。需要带 `registerUsage()` 的 pig（2.0.40 之后的版本）。

## 连接

如果已经在 pig 里登录了 OpenCode Go（`/login` 后选 OpenCode Go 或 OpenCode Zen，两者是同一个 OpenCode API key），无需任何设置。key 按以下顺序取第一个有值的：

1. `OPENCODE_GO_API_KEY`
2. `OPENCODE_API_KEY`
3. `OPENCODE_ZEN_API_KEY`
4. 用 `/opencode-go --connect <api-key>` 保存的 key
5. pig 里 `opencode-go` 的登录，然后是 `opencode`

有 key 时用量来自 `GET https://opencode.ai/zen/go/v1/usage`。

没有 key 时，可以像上游一样用浏览器会话走控制台自己的接口：

1. **Workspace ID**：`opencode.ai/console/`**`wrk_…`**`/go` 里的 `wrk_…`
2. **会话 cookie**：在该页面按 F12 → Application → Cookies → `https://opencode.ai` → `__Host-console_session` 那一行的值

```
/opencode-go --connect wrk_… <会话 cookie 的值>
```

也可以设置 `OPENCODE_GO_WORKSPACE_ID` 和 `OPENCODE_GO_AUTH_COOKIE`。只给值时按 `__Host-console_session=<值>` 发送；`name=value; name2=value2` 形式原样发送。

环境变量优先于保存的值；刚保存的值被环境变量盖住时，`/opencode-go` 会提示。保存的内容在 `~/.pig/agent/opencode-go-usage.json`（权限 `0600`），或 `OPENCODE_GO_CONFIG_PATH` 指定的位置。

## 命令

| 命令 | 作用 |
| --- | --- |
| `/opencode-go` | 获取并显示报告（等同于 `/usage opencode-go`） |
| `/opencode-go --connect <api-key>` | 保存 API key，获取并显示 |
| `/opencode-go --api-key <api-key>` | 只保存 API key |
| `/opencode-go --connect <wrk> <cookie>` | 保存 workspace 和 cookie，获取并显示 |
| `/opencode-go --workspace <id>` | 只保存 workspace |
| `/opencode-go --cookie <值>` | 只保存 cookie |
| `/opencode-go --disconnect` | 清除保存的凭据 |
| `/opencode-go --refresh` | 立即重新获取 |
| `/opencode-go --estimate [on\|off]` | 显示或隐藏月度剩余天数估算 |
| `/opencode-go --json` | 把报告写到 `~/.pig/agent/opencode-go-usage-report.json` |

终端里 pig 每 5 分钟刷新一次状态栏。`pig web` 里，「用量」面板在页面顶部的 ⋯ 菜单中，输入 `/usage` 也会打开它；打开时获取。

## 出错时

| 提示 | 含义 | 处理 |
| --- | --- | --- |
| `Session expired` | key 或控制台会话被拒绝 | 换新的 key 或 cookie 重新连接 |
| `No Go subscription on this workspace` | 控制台显示该 workspace 没有 Go 套餐 | 检查 workspace |
| `Console API response unrecognised` | 接口格式变了 | 更新本包 |
| `Network error` / `Request timed out` / `HTTP 5xx` | 临时故障，已自动重试两次 | 稍等，或 `--refresh` |

## 安全

这是用你自己的 API key 或浏览器会话 cookie 读取你自己的用量，凭据保存在 `0600` 文件或环境变量里。它只显示接口返回的内容；接口变化时会直说，而不是显示一个看似可信的 0。

## 许可

MIT。哪些部分来自 pi-opencode-go-usage，见 [NOTICE](NOTICE)。
