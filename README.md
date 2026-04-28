# MCP Abilities

A WordPress plugin that registers WordPress abilities and exposes them as tools through the [MCP Adapter](https://github.com/WordPress/mcp-adapter/), letting MCP-compatible clients interact with the site.

## Requirements

- WordPress 6.9+
- PHP 8.1+
- [MCP Adapter](https://github.com/WordPress/mcp-adapter/) plugin (must be installed and activated first)

## Endpoint

`/wp-json/mcp-abilities/v1`

## Abilities

All under the `mcp-abilities/` namespace:

**Posts**
- `list-posts`, `get-post`, `create-post`, `update-post`, `delete-post`

**Pages**
- `list-pages`, `get-page`, `create-page`, `update-page`, `delete-page`

**Taxonomies**
- `list-categories`, `create-category`, `update-category`, `delete-category`
- `list-tags`, `create-tag`, `update-tag`, `delete-tag`

**Media**
- `list-media`, `get-media`, `upload-media-from-url`, `set-featured-image`, `delete-media`

**Post meta**
- `get-post-meta`, `set-post-meta` (protected keys blocked by default; restrict via the `mcp_abilities_meta_allowlist` filter)

**Discovery**
- `list-post-types`, `get-current-user`

**Inventory**
- `site-inventory`

## Configuration

A settings page lives under **Settings → MCP Abilities**. Abilities are grouped into Read / Write / Destructive with sensible defaults (read on, write on, destructive off). Developers can override the admin selection with the `mcp_abilities_enabled_tools` filter:

```php
add_filter( 'mcp_abilities_enabled_tools', function( $enabled, $all ) {
    return array_diff( $enabled, array( 'mcp-abilities/delete-page' ) );
}, 10, 2 );
```

## Installation

1. Install and activate the **MCP Adapter** plugin.
2. Clone or copy this repository into `wp-content/plugins/mcp-abilities`.
3. Activate **MCP Abilities** in the WordPress Plugins screen.

If the MCP Adapter is missing, MCP Abilities will refuse to activate and surface an admin notice.

## Structure

- [mcp-abilities.php](mcp-abilities.php) — main plugin file with the WordPress plugin header, dependency check and bootstrap hooks.
- [includes/class-registry.php](includes/class-registry.php) — registers the ability category, all abilities and the MCP server.
- [includes/class-handlers.php](includes/class-handlers.php) — execution logic (read/write handlers and shared helpers).
- [includes/class-settings.php](includes/class-settings.php) — Settings → MCP Abilities admin page and option storage.
- [uninstall.php](uninstall.php) — cleanup on uninstall.
- [readme.txt](readme.txt) — WordPress.org-style readme.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
