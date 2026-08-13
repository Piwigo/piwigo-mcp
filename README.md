# Piwigo MCP (beta)

Turn your Piwigo gallery into an [MCP](https://modelcontextprotocol.io) server, so an AI
assistant such as Claude Code or ChatGPT can query it directly.

The plugin exposes a single HTTP endpoint, `plugins/piwigo-mcp/mcp.php`, protected by a
Piwigo **API key**. Every call runs with the permissions of the account that owns the key.

## Requirements

- **PHP 8.1 or newer**
- An admin or webmaster account, to create the API key.
- HTTPS is strongly recommended: the API key travels in a request header.

## Install

1. Via your Piwigo `menu > plugins > add` or drop the `piwigo-mcp` folder into `plugins/` and activate it from **Administration >
   Plugins**. Dependencies are bundled, there is nothing to install.
2. Create an API key: **your profile > API keys**. Copy it like
   `<key_id>:<key_secret>` (`pkid-xxx-xx:xxx`). It is shown only once.
3. Note your endpoint URL: `https://your-piwigo.com/plugins/piwigo-mcp/mcp.php`

## Connect Claude Code

```bash
claude mcp add --transport http piwigo \
  https://your-piwigo.com/plugins/piwigo-mcp/mcp.php \
  --header "Authorization: Bearer <key_id>:<key_secret>" \
  --scope user
```

`--scope user` makes the server available in all your projects; drop it to keep it to the
current one. Start a new session, then run `/mcp` to check that the server shows
`connected`. A failure shows the HTTP status returned by the server. A `401` means the key
is wrong or belongs to a non-admin account.

Adding the server from the claude.ai connector dialog is **not supported**: it only offers
OAuth, with no way to pass an API key.

## Connect ChatGPT / Codex

Go to `Settings > Plugins > add MCP server`

| Field | Value |
|---|---|
| Type | Streamable HTTP |
| URL | `https://your-piwigo.com/plugins/piwigo-mcp/mcp.php` |
| Header key | `Authorization` |
| Header value | `Bearer <key_id>:<key_secret>` |

Leave the *bearer token env var* field empty. It expects the **name** of an environment
variable, not the key itself. Use either that field or the header, never both.


## Configuration

MCP protocol sessions are stored outside the web root, under the system temp directory.
To place them elsewhere, set this in `local/config/config.inc.php`:

```php
$conf['piwigo_mcp_session_dir'] = '/var/lib/piwigo-mcp/sessions';
```

The directory must be writable by the web server, and must not be reachable over HTTP.

## Troubleshooting

**`401 Unauthorized`**. Missing, wrong or revoked key, or the key belongs to an account
that is not admin. The response body says which header is expected.

**`403 Forbidden: Invalid Host header`**. The host you called does not match the one
Piwigo derives from the request. Check any reverse proxy in front of the gallery.

**`404 Session not found or has expired`**. The session directory is not writable, or was
cleared. Sessions expire after one hour; the client reconnects on its own.

You can reproduce any of these with curl, which shows the raw error:

```bash
curl -i -X POST "https://your-piwigo.com/plugins/piwigo-mcp/mcp.php" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json, text/event-stream" \
  -H "Authorization: Bearer <key_id>:<key_secret>" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"curl","version":"1"}}}'
```
