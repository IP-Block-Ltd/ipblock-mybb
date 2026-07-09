> ⚠️ **Status: untested.** This extension is provided as-is and has **not been tested in production**. Please feel free to fork, modify, improve, and open pull requests.
>
> Licensed under **GNU GPLv3** (see [LICENSE](LICENSE)).

# IP Block Protection - MyBB Plugin

Screens every front-end request against the [ip-block.com](https://www.ip-block.com)
IP-screening service before the page is rendered. Blocked visitors are redirected
(or shown an HTTP 403); everyone else is untouched.

- **Platform:** MyBB
- **Tested against:** MyBB **1.8.40** (1.8.x line, compatibility `18*`)
- **Requires:** PHP with `cURL` (a stream fallback is included)

## How it works

| Concern | Implementation |
| --- | --- |
| Earliest hook | `global_start` (fires near the top of `global.php`) |
| Never lock out the Admin CP | The Admin CP does not load `global.php`, so the hook never runs there |
| Real client IP | `REMOTE_ADDR`, or `CF-Connecting-IP` / `X-Forwarded-For` when *behind a proxy* |
| Whitelist | Always honoured, checked before any API call |
| Caching | MyBB data cache (`$cache`), keyed by `md5(ip\|user_agent\|referrer)`, TTL configurable |
| Fail mode | On timeout / error / non-2xx / missing action, apply **fail open** (default) |
| API call | `POST`, 1 second timeout, `api_key` in the JSON body |

## API contract

```
POST https://api.ip-block.com/v1/check
Content-Type: application/json

{ "api_key": "...", "site_id": "...", "ip": "...", "user_agent": "...", "referrer": "..." }
```

Response: `{"action":"allow"}` or `{"action":"block"}`. Blocked **only** when
`action === "block"`.

## Files

```
inc/plugins/ipblock.php                     Main plugin (info/install/activate/hook)
inc/languages/english/ipblock.lang.php      Front-end language strings
```

## Installation

1. Copy `inc/plugins/ipblock.php` and `inc/languages/english/ipblock.lang.php`
   into the matching folders of your MyBB installation.
2. Admin CP -> Configuration -> **Plugins** -> find *IP Block Protection* ->
   **Install & Activate**.
3. Admin CP -> Configuration -> **Settings** -> **IP Block**: enter your
   **Site ID** and **API key**, then set *Enable IP screening* to **Yes**.

## Settings (Admin CP -> Settings -> IP Block)

| Setting | Default | Notes |
| --- | --- | --- |
| Enable IP screening | No | Master switch |
| Site ID | *(empty)* | Your ip-block.com site id |
| API key | *(empty)* | Sent in the request body |
| API URL | `https://api.ip-block.com/v1/check` | |
| Fail open | Yes | Allow visitors when the API is unreachable |
| Cache lifetime (seconds) | `300` | `0` = check every request |
| Behind a proxy / CDN | No | Read real IP from CF / XFF headers |
| Block action | Redirect | Redirect, or HTTP 403 message |
| Block message | *(text)* | Used with the 403 message action |
| IP whitelist | *(empty)* | One IP per line, always allowed |

## Uninstall

Admin CP -> Configuration -> Plugins -> *IP Block Protection* -> **Uninstall**.
All settings and the decision cache are removed.

## License

GNU General Public License v2.0 only.
