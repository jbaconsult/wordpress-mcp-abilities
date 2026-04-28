=== MCP Abilities ===
Contributors: jbaconsult
Tags: mcp, abilities, ai, model-context-protocol, gutenberg
Requires at least: 6.9
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Registers WordPress abilities for posts, pages, taxonomies and site inventory, and exposes them through a dedicated MCP server.

== Description ==

MCP Abilities is a companion plugin for the [MCP Adapter](https://github.com/WordPress/mcp-adapter/). It registers a set of abilities (list/get/create/update posts and pages, list categories/tags, delete pages, site inventory) under the `mcp-abilities/` namespace and creates a dedicated MCP server at `/wp-json/mcp-abilities/v1`, so MCP-compatible clients can run a content workflow against the site.

**This plugin requires the MCP Adapter plugin to be installed and activated.**

Endpoint: `/wp-json/mcp-abilities/v1`

== Installation ==

1. Install and activate the [MCP Adapter](https://github.com/WordPress/mcp-adapter/) plugin.
2. Upload the `mcp-abilities` folder to `/wp-content/plugins/` (or install via the Plugins screen).
3. Activate "MCP Abilities" through the Plugins screen in WordPress.

== Changelog ==

= 0.1.0 =
* Initial release: posts, pages, taxonomies and site inventory abilities, plus a dedicated MCP server endpoint.
