<?php
/**
 * Plugin Name:       MCP Abilities
 * Plugin URI:        https://github.com/jbaconsult/wordpress-mcp-abilities
 * Description:       Registers WordPress abilities (posts, pages, taxonomies, site inventory) and exposes them through a dedicated MCP server endpoint, so MCP-compatible clients can run a content workflow against the site. Endpoint: /wp-json/mcp-abilities/v1
 * Version:           0.1.0
 * Requires at least: 6.9
 * Requires PHP:      8.1
 * Author:            jbaconsult
 * Author URI:        https://jbaconsult.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       mcp-abilities
 * Domain Path:       /languages
 * Requires Plugins:  mcp-adapter
 *
 * @package MCPAbilities
 */

namespace MCPAbilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VERSION             = '0.1.0';
const MCP_ADAPTER_PLUGIN  = 'mcp-adapter/mcp-adapter.php';
const MCP_ADAPTER_REPO    = 'https://github.com/WordPress/mcp-adapter/';

define( __NAMESPACE__ . '\\PLUGIN_FILE', __FILE__ );
define( __NAMESPACE__ . '\\PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( __NAMESPACE__ . '\\PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once PLUGIN_PATH . 'includes/class-registry.php';
require_once PLUGIN_PATH . 'includes/class-handlers.php';
require_once PLUGIN_PATH . 'includes/class-settings.php';

register_activation_hook( __FILE__, __NAMESPACE__ . '\\on_activate' );

add_action( 'plugins_loaded', __NAMESPACE__ . '\\boot' );

function on_activate(): void {
	if ( ! is_mcp_adapter_active() ) {
		deactivate_plugins( plugin_basename( __FILE__ ) );
		wp_die(
			esc_html__( 'MCP Abilities requires the MCP Adapter plugin to be installed and activated first.', 'mcp-abilities' ),
			esc_html__( 'Plugin dependency missing', 'mcp-abilities' ),
			array( 'back_link' => true )
		);
	}
}

function boot(): void {
	load_plugin_textdomain( 'mcp-abilities', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	// Settings UI is always available so admins can configure regardless of adapter state.
	Settings::register();

	if ( ! is_mcp_adapter_active() ) {
		add_action( 'admin_notices', __NAMESPACE__ . '\\render_missing_dependency_notice' );
		return;
	}

	/* =====================================================================
	 * The MCP Adapter fires the abilities-API hooks internally during
	 * create_server(), in this order:
	 *   1. wp_abilities_api_categories_init   → category
	 *   2. wp_abilities_api_init              → abilities
	 *   3. mcp_adapter_init → create_server() → consumes abilities
	 *
	 * NOTE: ability names may only contain [a-z0-9\-\/]. Underscores are
	 * silently rejected by the abilities API.
	 * ================================================================== */

	add_action(
		'wp_abilities_api_categories_init',
		static function (): void {
			if ( function_exists( 'wp_register_ability_category' ) ) {
				Registry::register_category();
			}
		}
	);

	add_action(
		'wp_abilities_api_init',
		static function (): void {
			if ( function_exists( 'wp_register_ability' ) ) {
				Registry::register_abilities();
			}
		}
	);

	add_action(
		'mcp_adapter_init',
		static function ( $adapter = null ): void {
			if ( null === $adapter && class_exists( '\\WP\\MCP\\Core\\McpAdapter' ) ) {
				$adapter = \WP\MCP\Core\McpAdapter::instance();
			}
			if ( $adapter ) {
				Registry::register_mcp_server( $adapter );
			}
		}
	);
}

function is_mcp_adapter_active(): bool {
	return in_array( MCP_ADAPTER_PLUGIN, (array) get_option( 'active_plugins', array() ), true )
		|| ( is_multisite() && array_key_exists( MCP_ADAPTER_PLUGIN, (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
}

function render_missing_dependency_notice(): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		sprintf(
			/* translators: %s: link to the MCP Adapter repository. */
			esc_html__( 'MCP Abilities requires the MCP Adapter plugin. Please install and activate it from %s.', 'mcp-abilities' ),
			'<a href="' . esc_url( MCP_ADAPTER_REPO ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( MCP_ADAPTER_REPO ) . '</a>'
		)
	);
}
