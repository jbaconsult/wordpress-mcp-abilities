# MCP Abilities

A WordPress plugin that registers a curated set of [Abilities](https://github.com/WordPress/abilities-api/) and exposes them as MCP tools through the [MCP Adapter](https://github.com/WordPress/mcp-adapter/), letting MCP-compatible clients run a content workflow against the site.

[![License: GPL v2+](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
![WordPress 6.9+](https://img.shields.io/badge/WordPress-6.9%2B-21759B?logo=wordpress&logoColor=white)
![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-777BB4?logo=php&logoColor=white)

The plugin doesn't try to be a generic WordPress-over-MCP gateway. It ships an opinionated, capability-aware surface — posts, pages, taxonomies, media, post meta, plus a few discovery helpers — that's small enough to reason about and large enough to drive a real "idea → Gutenberg draft → publish" loop from an LLM client.

---

## Table of contents

- [Requirements](#requirements)
- [Quickstart](#quickstart)
- [Endpoint](#endpoint)
- [Abilities reference](#abilities-reference)
- [Configuration](#configuration)
- [Connecting an MCP client](#connecting-an-mcp-client)
- [Workflow examples](#workflow-examples)
- [Security model](#security-model)
- [Filters](#filters)
- [Project layout](#project-layout)
- [Development](#development)
- [License](#license)

---

## Requirements

- WordPress **6.9+**
- PHP **8.1+**
- [MCP Adapter](https://github.com/WordPress/mcp-adapter/) plugin (must be installed and activated first)

If the MCP Adapter is missing on activation, MCP Abilities refuses to activate and surfaces an admin notice with a link to the adapter repo.

---

## Quickstart

1. Install and activate the **MCP Adapter** plugin.
2. Clone or copy this repository into `wp-content/plugins/mcp-abilities` and activate **MCP Abilities** on the Plugins screen.
3. Open **Settings → MCP Abilities** and confirm the default selection (read + write enabled, destructive disabled). Save if you change anything.
4. Create a WordPress [Application Password](https://wordpress.org/documentation/article/application-passwords/) for the user that should drive the abilities. Roles still apply — the application password inherits the user's capabilities.
5. Verify the endpoint responds. Send any JSON-RPC request to the MCP endpoint to confirm it's wired up:
   ```bash
   curl -i https://your-site.example/wp-json/mcp-abilities/v1
   ```
   You should get a `400` with `Missing Mcp-Session-Id header` — that's the correct MCP Streamable-HTTP behavior before an `initialize` handshake. If you get a `404`, your permalinks are still on "Plain" — switch to any pretty-permalink setting and re-save.

For the actual handshake plus a `tools/list` call, see [Connecting an MCP client](#connecting-an-mcp-client) below.

---

## Endpoint

```
/wp-json/mcp-abilities/v1
```

This is a [Streamable-HTTP](https://modelcontextprotocol.io/specification/draft/basic/transports#streamable-http) MCP transport endpoint. Every request needs a session, established via the standard `initialize` handshake.

---

## Abilities reference

All abilities live under the `mcp-abilities/` namespace. The "Group" column maps directly to the three groups in the admin settings page.

### Posts

| Ability | Group | Capability | Annotations |
|---|---|---|---|
| `mcp-abilities/list-posts` | read | `edit_posts` | read-only |
| `mcp-abilities/get-post` | read | `edit_post` or `read_post` | read-only |
| `mcp-abilities/create-post` | write | `edit_posts`, plus `publish_posts` for `publish`/`future` | non-destructive |
| `mcp-abilities/update-post` | write | `edit_post`, plus `publish_posts` for `publish`/`future` | idempotent |
| `mcp-abilities/delete-post` | destructive | `delete_post` | destructive, idempotent |

### Pages

| Ability | Group | Capability | Annotations |
|---|---|---|---|
| `mcp-abilities/list-pages` | read | `edit_pages` | read-only |
| `mcp-abilities/get-page` | read | `edit_page` or `read_post` | read-only |
| `mcp-abilities/create-page` | write | `edit_pages`, plus `publish_pages` for `publish`/`future` | non-destructive |
| `mcp-abilities/update-page` | write | `edit_page`, plus `publish_pages` for `publish`/`future` | idempotent |
| `mcp-abilities/delete-page` | destructive | `delete_page` | destructive, idempotent |

### Taxonomies

| Ability | Group | Capability | Annotations |
|---|---|---|---|
| `mcp-abilities/list-categories` | read | `edit_posts` | read-only |
| `mcp-abilities/create-category` | write | `manage_categories` | non-destructive |
| `mcp-abilities/update-category` | write | `manage_categories` | idempotent |
| `mcp-abilities/delete-category` | destructive | `manage_categories` | destructive, idempotent |
| `mcp-abilities/list-tags` | read | `edit_posts` | read-only |
| `mcp-abilities/create-tag` | write | `manage_categories` | non-destructive |
| `mcp-abilities/update-tag` | write | `manage_categories` | idempotent |
| `mcp-abilities/delete-tag` | destructive | `manage_categories` | destructive, idempotent |

Deleting a category or tag does not delete the posts attached to it; they lose the assignment (and may fall back to the default category for posts that would otherwise have no category).

### Media

| Ability | Group | Capability | Annotations |
|---|---|---|---|
| `mcp-abilities/list-media` | read | `upload_files` | read-only |
| `mcp-abilities/get-media` | read | `upload_files` | read-only |
| `mcp-abilities/upload-media-from-url` | write | `upload_files` | non-destructive |
| `mcp-abilities/set-featured-image` | write | `edit_post` (target post) | idempotent |
| `mcp-abilities/delete-media` | destructive | `delete_post` (attachment is a post) | destructive, idempotent |

`upload-media-from-url` downloads a remote file (typically an image) into the WordPress media library, optionally attaching it to a post. `set-featured-image` accepts `attachment_id=0` to remove the thumbnail. `delete-media` defaults to `force=true` because attachments do not have a native trash.

### Post meta

| Ability | Group | Capability | Annotations |
|---|---|---|---|
| `mcp-abilities/get-post-meta` | read | `edit_post` (target post) | read-only |
| `mcp-abilities/set-post-meta` | write | `edit_post` (target post) | idempotent |

`get-post-meta` returns all non-protected meta when called without a `key`. `set-post-meta` blocks protected keys (those starting with `_`) by default; allowlist specific keys via the [`mcp_abilities_meta_allowlist`](#filters) filter.

### Discovery

| Ability | Group | Capability | Annotations |
|---|---|---|---|
| `mcp-abilities/list-post-types` | read | `edit_posts` | read-only |
| `mcp-abilities/get-current-user` | read | `is_user_logged_in()` | read-only |

`get-current-user` is useful as a sanity check at the start of a session — it tells the client which user it's authenticated as and which MCP-Abilities-relevant capabilities that user holds.

### Inventory

| Ability | Group | Capability | Annotations |
|---|---|---|---|
| `mcp-abilities/site-inventory` | read | `manage_options` | read-only |

A compact summary: post/page/media counts, active plugins, theme, WordPress and PHP versions. Designed as the "first call" most clients should make to orient themselves.

---

## Configuration

A settings page lives at **Settings → MCP Abilities** in the WordPress admin. Abilities are grouped into three buckets with sensible defaults:

| Group | Default | Contents |
|---|---|---|
| **Read** | enabled | All `list-*`, `get-*`, `site-inventory` |
| **Write** | enabled | All `create-*`, `update-*`, `upload-media-from-url`, `set-featured-image`, `set-post-meta` |
| **Destructive** | disabled | All `delete-*` |

The whitelist is **subtractive only** — disabling an ability here removes it from the MCP tool list. It does not bypass capability checks: a user without `delete_post` cannot call `delete-post` even if it's enabled in settings.

Use the **Reset to defaults** button at the bottom of the settings page to restore the original selection.

---

## Connecting an MCP client

### Claude Desktop (via stdio bridge)

Claude Desktop speaks stdio MCP, while this plugin exposes Streamable-HTTP. Bridge the two with [`@automattic/mcp-wordpress-remote`](https://www.npmjs.com/package/@automattic/mcp-wordpress-remote), which is a thin Node proxy that handles the HTTP handshake and forwards JSON-RPC over stdio.

Add a server to `claude_desktop_config.json`:

```json
{
  "mcpServers": {
    "my-wordpress": {
      "command": "npx",
      "args": ["-y", "@automattic/mcp-wordpress-remote"],
      "env": {
        "WP_API_URL": "https://your-site.example/",
        "WP_API_USERNAME": "your-wp-username",
        "WP_API_PASSWORD": "xxxx xxxx xxxx xxxx xxxx xxxx",
        "OAUTH_ENABLED": "false"
      }
    }
  }
}
```

`WP_API_PASSWORD` is the **Application Password** (with spaces), not the regular login password. Restart Claude Desktop after editing the config.

For a local dev site over plain HTTP (e.g. Local by Flywheel), set `NODE_TLS_REJECT_UNAUTHORIZED=0` if you also have a self-signed cert in the chain. Don't ship that into production.

### Direct HTTP MCP clients

Clients that natively speak Streamable-HTTP MCP can connect directly to `/wp-json/mcp-abilities/v1`. Authenticate using HTTP Basic with the Application Password — the WordPress REST stack handles authentication before the request reaches the adapter.

A minimal handshake from `curl` looks like this:

```bash
ENDPOINT="https://your-site.example/wp-json/mcp-abilities/v1"
AUTH="user:xxxx xxxx xxxx xxxx xxxx xxxx"

# 1. Initialize and capture the session id
SESSION=$(curl -sS -D - -o /dev/null -u "$AUTH" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json, text/event-stream" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"curl","version":"0"}}}' \
  "$ENDPOINT" | awk -F': ' '/^Mcp-Session-Id/{print $2}' | tr -d '\r')

# 2. List tools
curl -sS -u "$AUTH" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json, text/event-stream" \
  -H "Mcp-Session-Id: $SESSION" \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/list"}' \
  "$ENDPOINT"
```

For interactive exploration, the [MCP Inspector](https://github.com/modelcontextprotocol/inspector) is the most ergonomic way to poke at the endpoint without writing curl by hand.

---

## Workflow examples

The MCP server doesn't enforce any particular sequence — clients are free to combine abilities however they want. The flows below are realistic patterns that fall naturally out of the surface area.

### Idea → draft → publish

A content-creation loop where an LLM drafts a post and a human reviews before publishing.

1. `list-categories`, `list-tags` — see what already exists; reuse rather than spawning new taxonomy terms.
2. `create-post` with `status=draft`, `title`, Gutenberg `content`, `categories`, `tags`. Returns the new `post_id` and `edit_link`.
3. Author reviews in the WordPress editor.
4. `update-post` with `post_id`, `status=publish` to ship — or any field tweaks the reviewer asked for.

### Hero image for a post

Drop an image into the media library and pin it as the post's featured image.

1. `upload-media-from-url` with `url`, `alt_text`, `parent_id` set to the post. Returns `attachment_id`.
2. `set-featured-image` with that `post_id` and `attachment_id`.

### Site inventory

A quick orientation pass an LLM can run at session start.

1. `site-inventory` — counts, theme, plugins, WP/PHP versions.
2. `get-current-user` — confirm authenticated identity and capability map.
3. `list-post-types` — discover custom post types (built-in `post`/`page` plus anything the theme/plugins add).

---

## Security model

A few principles that the plugin holds to:

- **WordPress capabilities are the source of truth.** Every ability has a `permission_callback` that calls `current_user_can( … )`. The admin whitelist can only further restrict — it cannot grant access a user doesn't already have.
- **Application Passwords are the recommended auth method.** They scope cleanly per integration and can be revoked individually. Don't reuse the regular login password.
- **Protected post meta is blocked by default.** Keys starting with `_` are off-limits to `set-post-meta` unless you explicitly allowlist them.
- **Destructive abilities are off by default.** All `delete-*` operations require an explicit opt-in on the settings page.
- **Gutenberg content is preserved as-is.** `wp_kses` is intentionally not applied to post/page content because it would destroy block-grammar comments (`<!-- wp:… -->`). The protection is the capability check (`edit_posts` etc., which already implies a trusted role).

What this plugin does **not** do:

- It does not add rate limiting. If you need it, put one in front (Cloudflare, fail2ban, a WAF plugin).
- It does not log every MCP call. The MCP Adapter exposes observability hooks; wire your own logger if you need an audit trail.

---

## Filters

### `mcp_abilities_enabled_tools`

Override the admin selection programmatically. Useful when you want a mu-plugin to enforce a hard policy regardless of what's saved in settings.

```php
add_filter( 'mcp_abilities_enabled_tools', function( array $enabled, array $all ): array {
    // Never expose delete-page on this site, even if an admin ticks it.
    return array_values( array_diff( $enabled, array( 'mcp-abilities/delete-page' ) ) );
}, 10, 2 );
```

The result is intersected with the registered abilities, so you cannot accidentally expose tools that don't exist.

### `mcp_abilities_meta_allowlist`

Permit specific protected post meta keys (those starting with `_`) to be read or written via the meta abilities. Without this filter, protected keys are blocked.

```php
add_filter( 'mcp_abilities_meta_allowlist', function( array $keys ): array {
    $keys[] = '_my_plugin_seo_score';
    return $keys;
} );
```

---

## Project layout

```
mcp-abilities.php          # Plugin header, dependency check, bootstrap hooks
includes/
  class-registry.php       # Registers ability category, all abilities, MCP server
  class-handlers.php       # Execution logic and shared helpers
  class-settings.php       # Settings → MCP Abilities admin page and option storage
uninstall.php              # Cleanup on uninstall
readme.txt                 # WordPress.org-style readme
LICENSE                    # GPL-2.0-or-later
```

The split is deliberate:

- `Registry` owns *what* the API surface looks like (names, schemas, capabilities, annotations).
- `Handlers` owns *what each ability actually does* (database calls, taxonomy logic, file downloads).
- `Settings` owns *which subset is exposed* (admin UI, option storage, group definitions, defaults).

Add a new ability by adding its name to `Registry::ability_names()`, a `register_*()` private method, a handler in `Handlers`, and (if it should be exposed by default) an entry in the appropriate group inside `Settings::tool_groups()`.

---

## Development

```bash
git clone https://github.com/jbaconsult/wordpress-mcp-abilities.git
cd wordpress-mcp-abilities
# symlink or copy into wp-content/plugins/mcp-abilities of a dev WordPress
```

Useful debugging knobs while iterating:

- Enable `WP_DEBUG` and `WP_DEBUG_LOG` in `wp-config.php` — the plugin writes a log line if `create_server()` ever throws.
- The MCP Inspector is much faster than restarting Claude Desktop on every change.
- Adding a new ability and wondering why it doesn't appear? Settings only intersects what exists in `ability_names()` — but the *server registration* uses `enabled_tools()`, so a new ability is invisible until either (a) it lands in the default-enabled set in `Settings::tool_groups()` or (b) the admin ticks it on. New abilities also require an MCP-client reconnect to be seen on the client side.

PRs welcome — issues, feature requests, and bug reports go to the [GitHub issue tracker](https://github.com/jbaconsult/wordpress-mcp-abilities/issues).

---

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
