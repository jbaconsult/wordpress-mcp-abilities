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

- `list-posts`, `get-post`, `create-post`, `update-post`
- `list-pages`, `get-page`, `create-page`, `update-page`, `delete-page`
- `list-categories`, `list-tags`
- `site-inventory`

## Installation

1. Install and activate the **MCP Adapter** plugin.
2. Clone or copy this repository into `wp-content/plugins/mcp-abilities`.
3. Activate **MCP Abilities** in the WordPress Plugins screen.

If the MCP Adapter is missing, MCP Abilities will refuse to activate and surface an admin notice.

## Structure

- [mcp-abilities.php](mcp-abilities.php) — main plugin file with the WordPress plugin header, dependency check and bootstrap hooks.
- [includes/class-registry.php](includes/class-registry.php) — registers the ability category, all abilities and the MCP server.
- [includes/class-handlers.php](includes/class-handlers.php) — execution logic (read/write handlers and shared helpers).
- [uninstall.php](uninstall.php) — cleanup on uninstall.
- [readme.txt](readme.txt) — WordPress.org-style readme.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
