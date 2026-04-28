<?php
/**
 * Settings — admin UI and option storage for ability exposure.
 *
 * @package MCPAbilities
 */

namespace MCPAbilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Settings {

	public const OPTION_NAME    = 'mcp_abilities_settings';
	public const PAGE_SLUG      = 'mcp-abilities';
	public const SETTINGS_GROUP = 'mcp_abilities_group';
	public const RESET_ACTION   = 'mcp_abilities_reset_defaults';

	/**
	 * Tool grouping used both for defaults and the settings UI.
	 *
	 * @return array<string, array{label:string,description:string,tools:string[],default_enabled:bool}>
	 */
	public static function tool_groups(): array {
		return array(
			'read'        => array(
				'label'           => __( 'Read', 'mcp-abilities' ),
				'description'     => __( 'Read-only abilities. Safe to enable.', 'mcp-abilities' ),
				'tools'           => array(
					'mcp-abilities/list-posts',
					'mcp-abilities/get-post',
					'mcp-abilities/list-pages',
					'mcp-abilities/get-page',
					'mcp-abilities/list-categories',
					'mcp-abilities/list-tags',
					'mcp-abilities/list-media',
					'mcp-abilities/get-media',
					'mcp-abilities/get-post-meta',
					'mcp-abilities/list-post-types',
					'mcp-abilities/get-current-user',
					'mcp-abilities/site-inventory',
				),
				'default_enabled' => true,
			),
			'write'       => array(
				'label'           => __( 'Write', 'mcp-abilities' ),
				'description'     => __( 'Abilities that create or modify content. Reversible.', 'mcp-abilities' ),
				'tools'           => array(
					'mcp-abilities/create-post',
					'mcp-abilities/update-post',
					'mcp-abilities/create-page',
					'mcp-abilities/update-page',
					'mcp-abilities/create-category',
					'mcp-abilities/update-category',
					'mcp-abilities/create-tag',
					'mcp-abilities/update-tag',
					'mcp-abilities/upload-media-from-url',
					'mcp-abilities/set-featured-image',
					'mcp-abilities/set-post-meta',
				),
				'default_enabled' => true,
			),
			'destructive' => array(
				'label'           => __( 'Destructive', 'mcp-abilities' ),
				'description'     => __( 'Abilities that delete content or are hard to reverse. Off by default.', 'mcp-abilities' ),
				'tools'           => array(
					'mcp-abilities/delete-post',
					'mcp-abilities/delete-page',
					'mcp-abilities/delete-category',
					'mcp-abilities/delete-tag',
					'mcp-abilities/delete-media',
				),
				'default_enabled' => false,
			),
		);
	}

	/**
	 * @return string[]
	 */
	public static function default_enabled_tools(): array {
		$enabled = array();
		foreach ( self::tool_groups() as $group ) {
			if ( ! empty( $group['default_enabled'] ) ) {
				$enabled = array_merge( $enabled, $group['tools'] );
			}
		}
		return $enabled;
	}

	/**
	 * @return string[]
	 */
	public static function get_enabled_tools(): array {
		$option = get_option( self::OPTION_NAME );
		if ( ! is_array( $option ) || ! array_key_exists( 'enabled_tools', $option ) ) {
			return self::default_enabled_tools();
		}
		$tools = is_array( $option['enabled_tools'] ) ? $option['enabled_tools'] : array();
		return array_values( array_filter( array_map( 'strval', $tools ) ) );
	}

	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu_page' ) );
		add_action( 'admin_init', array( self::class, 'register_settings' ) );
		add_action( 'admin_post_' . self::RESET_ACTION, array( self::class, 'handle_reset' ) );
	}

	public static function add_menu_page(): void {
		add_options_page(
			__( 'MCP Abilities', 'mcp-abilities' ),
			__( 'MCP Abilities', 'mcp-abilities' ),
			'manage_options',
			self::PAGE_SLUG,
			array( self::class, 'render_page' )
		);
	}

	public static function register_settings(): void {
		register_setting(
			self::SETTINGS_GROUP,
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'default'           => array( 'enabled_tools' => self::default_enabled_tools() ),
			)
		);
	}

	/**
	 * @param mixed $input
	 * @return array{enabled_tools: string[]}
	 */
	public static function sanitize( $input ): array {
		$all_tools = Registry::ability_names();
		$enabled   = array();
		if ( is_array( $input ) && isset( $input['enabled_tools'] ) && is_array( $input['enabled_tools'] ) ) {
			foreach ( $input['enabled_tools'] as $name ) {
				$name = (string) $name;
				if ( in_array( $name, $all_tools, true ) ) {
					$enabled[] = $name;
				}
			}
		}
		return array( 'enabled_tools' => array_values( array_unique( $enabled ) ) );
	}

	public static function handle_reset(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'mcp-abilities' ) );
		}
		check_admin_referer( self::RESET_ACTION );
		update_option(
			self::OPTION_NAME,
			array( 'enabled_tools' => self::default_enabled_tools() )
		);
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'reset' => '1' ), admin_url( 'options-general.php' ) ) );
		exit;
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$enabled         = self::get_enabled_tools();
		$adapter_active  = is_mcp_adapter_active();
		$reset_just_done = isset( $_GET['reset'] ) && '1' === $_GET['reset']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'MCP Abilities', 'mcp-abilities' ); ?></h1>

			<?php if ( $reset_just_done ) : ?>
				<div class="notice notice-success is-dismissible"><p>
					<?php esc_html_e( 'Settings reset to defaults.', 'mcp-abilities' ); ?>
				</p></div>
			<?php endif; ?>

			<?php if ( ! $adapter_active ) : ?>
				<div class="notice notice-warning"><p>
					<?php
					printf(
						/* translators: %s: link to the MCP Adapter repository. */
						esc_html__( 'The MCP Adapter plugin is not active. Settings can be configured but no MCP server will be exposed until the adapter is installed and activated. See: %s', 'mcp-abilities' ),
						'<a href="' . esc_url( MCP_ADAPTER_REPO ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( MCP_ADAPTER_REPO ) . '</a>'
					);
					?>
				</p></div>
			<?php endif; ?>

			<p><?php esc_html_e( 'Choose which abilities are exposed via the MCP server. WordPress capabilities still apply: disabling here removes the ability from the MCP tool list, but enabling here does not bypass capability checks.', 'mcp-abilities' ); ?></p>

			<form method="post" action="options.php">
				<?php settings_fields( self::SETTINGS_GROUP ); ?>

				<?php foreach ( self::tool_groups() as $group_key => $group ) : ?>
					<h2><?php echo esc_html( $group['label'] ); ?></h2>
					<p class="description"><?php echo esc_html( $group['description'] ); ?></p>
					<table class="form-table" role="presentation"><tbody>
						<?php foreach ( $group['tools'] as $tool ) :
							$is_checked = in_array( $tool, $enabled, true );
							$id         = 'mcp-abilities-tool-' . sanitize_title( $tool );
							?>
							<tr>
								<th scope="row" style="width:280px;">
									<label for="<?php echo esc_attr( $id ); ?>"><code><?php echo esc_html( $tool ); ?></code></label>
								</th>
								<td>
									<label for="<?php echo esc_attr( $id ); ?>">
										<input type="checkbox"
											id="<?php echo esc_attr( $id ); ?>"
											name="<?php echo esc_attr( self::OPTION_NAME ); ?>[enabled_tools][]"
											value="<?php echo esc_attr( $tool ); ?>"
											<?php checked( $is_checked ); ?> />
										<?php esc_html_e( 'Expose via MCP', 'mcp-abilities' ); ?>
									</label>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody></table>
				<?php endforeach; ?>

				<?php submit_button( __( 'Save Settings', 'mcp-abilities' ) ); ?>
			</form>

			<hr />

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::RESET_ACTION ); ?>" />
				<?php wp_nonce_field( self::RESET_ACTION ); ?>
				<?php submit_button( __( 'Reset to defaults', 'mcp-abilities' ), 'secondary', 'submit', false ); ?>
				<p class="description">
					<?php esc_html_e( 'Resets to: all read and write abilities enabled, destructive abilities disabled.', 'mcp-abilities' ); ?>
				</p>
			</form>
		</div>
		<?php
	}
}
