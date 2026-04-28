<?php
/**
 * Registry — central registration logic for abilities, category and MCP server.
 *
 * @package MCPAbilities
 */

namespace MCPAbilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Registry {

	public const CATEGORY     = 'mcp-abilities-content';
	public const NS           = 'mcp-abilities/';
	public const SERVER_ID    = 'mcp-abilities-server';
	public const SERVER_NS    = 'mcp-abilities';
	public const SERVER_ROUTE = 'v1';

	/**
	 * Subset of ability_names() that should be exposed via the MCP server,
	 * based on admin settings. Intersected with all known abilities so a
	 * stale option cannot expose anything we no longer register. The
	 * `mcp_abilities_enabled_tools` filter sits on top so mu-plugins can
	 * override the admin selection.
	 *
	 * @return string[]
	 */
	public static function enabled_tools(): array {
		$all     = self::ability_names();
		$enabled = array_values( array_intersect( $all, Settings::get_enabled_tools() ) );

		/**
		 * Filter the list of MCP-Abilities tool names exposed via the MCP server.
		 *
		 * @param string[] $enabled Currently enabled tool names.
		 * @param string[] $all     All registered tool names.
		 */
		$enabled = (array) apply_filters( 'mcp_abilities_enabled_tools', $enabled, $all );

		return array_values( array_intersect( $all, $enabled ) );
	}

	/**
	 * Central list of all ability names (dashes, no underscores).
	 *
	 * @return string[]
	 */
	public static function ability_names(): array {
		return array(
			// Posts
			self::NS . 'list-posts',
			self::NS . 'get-post',
			self::NS . 'create-post',
			self::NS . 'update-post',
			self::NS . 'delete-post',
			// Pages
			self::NS . 'list-pages',
			self::NS . 'get-page',
			self::NS . 'create-page',
			self::NS . 'update-page',
			self::NS . 'delete-page',
			// Taxonomies
			self::NS . 'list-categories',
			self::NS . 'create-category',
			self::NS . 'update-category',
			self::NS . 'delete-category',
			self::NS . 'list-tags',
			self::NS . 'create-tag',
			self::NS . 'update-tag',
			self::NS . 'delete-tag',
			// Media
			self::NS . 'list-media',
			self::NS . 'get-media',
			self::NS . 'upload-media-from-url',
			self::NS . 'set-featured-image',
			self::NS . 'delete-media',
			// Post meta
			self::NS . 'get-post-meta',
			self::NS . 'set-post-meta',
			// Discovery
			self::NS . 'list-post-types',
			self::NS . 'get-current-user',
			// Inventory
			self::NS . 'site-inventory',
		);
	}

	public static function register_category(): void {
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'MCP Abilities — Content Ops', 'mcp-abilities' ),
				'description' => __( 'Abilities for blog/page content workflow and site inventory.', 'mcp-abilities' ),
			)
		);
	}

	public static function register_abilities(): void {
		// Posts
		self::register_list_posts();
		self::register_get_post();
		self::register_create_post();
		self::register_update_post();
		self::register_delete_post();
		// Pages
		self::register_list_pages();
		self::register_get_page();
		self::register_create_page();
		self::register_update_page();
		self::register_delete_page();
		// Taxonomies
		self::register_list_categories();
		self::register_create_category();
		self::register_update_category();
		self::register_delete_category();
		self::register_list_tags();
		self::register_create_tag();
		self::register_update_tag();
		self::register_delete_tag();
		// Media
		self::register_list_media();
		self::register_get_media();
		self::register_upload_media_from_url();
		self::register_set_featured_image();
		self::register_delete_media();
		// Post meta
		self::register_get_post_meta();
		self::register_set_post_meta();
		// Discovery
		self::register_list_post_types();
		self::register_get_current_user();
		// Inventory
		self::register_site_inventory();
	}

	public static function register_mcp_server( $adapter ): void {
		$transport = '\\WP\\MCP\\Transport\\HttpTransport';
		$errors    = '\\WP\\MCP\\Infrastructure\\ErrorHandling\\ErrorLogMcpErrorHandler';
		$obs       = '\\WP\\MCP\\Infrastructure\\Observability\\NullMcpObservabilityHandler';

		if ( ! class_exists( $transport ) || ! class_exists( $errors ) ) {
			return;
		}

		$args = array(
			self::SERVER_ID,
			self::SERVER_NS,
			self::SERVER_ROUTE,
			__( 'MCP Abilities Server', 'mcp-abilities' ),
			__( 'Content workflow and site inventory abilities.', 'mcp-abilities' ),
			'1.0.0',
			array( $transport ),
			$errors,
		);
		if ( class_exists( $obs ) ) {
			$args[] = $obs;
		}
		$args[] = self::enabled_tools(); // Tools (filtered by admin settings)
		$args[] = array();                // Resources
		$args[] = array();                // Prompts

		try {
			call_user_func_array( array( $adapter, 'create_server' ), $args );
		} catch ( \Throwable $e ) {
			error_log( '[mcp-abilities] create_server failed: ' . $e->getMessage() );
		}
	}

	/* ---------------------------------------------------------------------
	 * mcp-abilities/list-posts
	 * ------------------------------------------------------------------ */
	private static function register_list_posts(): void {
		wp_register_ability(
			self::NS . 'list-posts',
			array(
				'label'               => __( 'List posts', 'mcp-abilities' ),
				'description'         => __( 'Lists blog posts with optional filters (status, search, category, pagination). Defaults: publish, 20 per page, newest first.', 'mcp-abilities' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'status'   => array(
							'type'    => 'string',
							'enum'    => array( 'publish', 'draft', 'pending', 'private', 'future', 'any' ),
							'default' => 'publish',
						),
						'per_page' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20 ),
						'page'     => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ),
						'search'   => array( 'type' => 'string' ),
						'category' => array( 'type' => 'string' ),
						'orderby'  => array( 'type' => 'string', 'enum' => array( 'date', 'modified', 'title', 'menu_order' ), 'default' => 'date' ),
						'order'    => array( 'type' => 'string', 'enum' => array( 'ASC', 'DESC' ), 'default' => 'DESC' ),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'total' => array( 'type' => 'integer' ),
						'posts' => array( 'type' => 'array', 'items' => self::post_summary_schema() ),
					),
				),
				'permission_callback' => static fn( $input = null ): bool => current_user_can( 'edit_posts' ),
				'execute_callback'    => array( Handlers::class, 'list_posts' ),
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true ),
					'annotations'  => array( 'readOnly' => true ),
				),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * mcp-abilities/get-post
	 * ------------------------------------------------------------------ */
	private static function register_get_post(): void {
		wp_register_ability(
			self::NS . 'get-post',
			array(
				'label'               => __( 'Get a single post', 'mcp-abilities' ),
				'description'         => __( 'Returns a single post including Gutenberg content, categories and tags.', 'mcp-abilities' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array( 'type' => 'integer', 'minimum' => 1 ),
					),
					'required'   => array( 'post_id' ),
				),
				'output_schema'       => self::post_full_schema(),
				'permission_callback' => static function ( $input = null ): bool {
					$post_id = is_array( $input ) && isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
					return current_user_can( 'edit_post', $post_id ) || current_user_can( 'read_post', $post_id );
				},
				'execute_callback'    => array( Handlers::class, 'get_post' ),
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true ),
					'annotations'  => array( 'readOnly' => true ),
				),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * mcp-abilities/create-post
	 * ------------------------------------------------------------------ */
	private static function register_create_post(): void {
		wp_register_ability(
			self::NS . 'create-post',
			array(
				'label'               => __( 'Create a post', 'mcp-abilities' ),
				'description'         => __( 'Creates a new blog post. Content is expected as Gutenberg block markup. Default status is "draft".', 'mcp-abilities' ),
				'category'            => self::CATEGORY,
				'input_schema'        => self::post_write_schema( true ),
				'output_schema'       => self::post_write_result_schema(),
				'permission_callback' => static function ( $input = null ): bool {
					$status = is_array( $input ) && isset( $input['status'] ) ? (string) $input['status'] : 'draft';
					if ( 'publish' === $status || 'future' === $status ) {
						return current_user_can( 'publish_posts' );
					}
					return current_user_can( 'edit_posts' );
				},
				'execute_callback'    => array( Handlers::class, 'create_post' ),
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true ),
					'annotations'  => array( 'destructiveHint' => false, 'idempotentHint' => false ),
				),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * mcp-abilities/update-post
	 * ------------------------------------------------------------------ */
	private static function register_update_post(): void {
		wp_register_ability(
			self::NS . 'update-post',
			array(
				'label'               => __( 'Update a post', 'mcp-abilities' ),
				'description'         => __( 'Updates an existing post. Only fields that are set will be overwritten.', 'mcp-abilities' ),
				'category'            => self::CATEGORY,
				'input_schema'        => self::post_write_schema( false ),
				'output_schema'       => self::post_write_result_schema(),
				'permission_callback' => static function ( $input = null ): bool {
					$post_id = is_array( $input ) && isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
					if ( $post_id <= 0 || ! current_user_can( 'edit_post', $post_id ) ) {
						return false;
					}
					$status = is_array( $input ) && isset( $input['status'] ) ? (string) $input['status'] : null;
					if ( ( 'publish' === $status || 'future' === $status ) && ! current_user_can( 'publish_posts' ) ) {
						return false;
					}
					return true;
				},
				'execute_callback'    => array( Handlers::class, 'update_post' ),
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true ),
					'annotations'  => array( 'destructiveHint' => false, 'idempotentHint' => true ),
				),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * mcp-abilities/delete-post
	 * ------------------------------------------------------------------ */
	private static function register_delete_post(): void {
		wp_register_ability(
			self::NS . 'delete-post',
			array(
				'label'               => __( 'Delete a post', 'mcp-abilities' ),
				'description'         => __( 'Deletes a post. By default moves to trash. Pass force=true to delete permanently.', 'mcp-abilities' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array( 'type' => 'integer', 'minimum' => 1 ),
						'force'   => array( 'type' => 'boolean', 'default' => false, 'description' => 'true = delete permanently, false = move to trash.' ),
					),
					'required'   => array( 'post_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array( 'type' => 'integer' ),
						'deleted' => array( 'type' => 'boolean' ),
						'force'   => array( 'type' => 'boolean' ),
					),
				),
				'permission_callback' => static function ( $input = null ): bool {
					$post_id = is_array( $input ) && isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
					return $post_id > 0 && current_user_can( 'delete_post', $post_id );
				},
				'execute_callback'    => array( Handlers::class, 'delete_post' ),
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true ),
					'annotations'  => array( 'destructiveHint' => true, 'idempotentHint' => true ),
				),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * mcp-abilities/list-categories
	 * ------------------------------------------------------------------ */
	private static function register_list_categories(): void {
		wp_register_ability(
			self::NS . 'list-categories',
			array(
				'label'               => __( 'List categories', 'mcp-abilities' ),
				'description'         => __( 'Lists all blog categories with post counts. Useful for category suggestions on new posts.', 'mcp-abilities' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array( 'type' => 'object', 'properties' => new \stdClass() ),
				'output_schema'       => array( 'type' => 'array', 'items' => self::term_schema() ),
				'permission_callback' => static fn( $input = null ): bool => current_user_can( 'edit_posts' ),
				'execute_callback'    => array( Handlers::class, 'list_categories' ),
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true ),
					'annotations'  => array( 'readOnly' => true ),
				),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * mcp-abilities/list-tags
	 * ------------------------------------------------------------------ */
	private static function register_list_tags(): void {
		wp_register_ability(
			self::NS . 'list-tags',
			array(
				'label'               => __( 'List tags', 'mcp-abilities' ),
				'description'         => __( 'Lists tags with post counts. Useful for tag suggestions and reuse.', 'mcp-abilities' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'search' => array( 'type' => 'string' ),
						'limit'  => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'default' => 100 ),
					),
				),
				'output_schema'       => array( 'type' => 'array', 'items' => self::term_schema() ),
				'permission_callback' => static fn( $input = null ): bool => current_user_can( 'edit_posts' ),
				'execute_callback'    => array( Handlers::class, 'list_tags' ),
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true ),
					'annotations'  => array( 'readOnly' => true ),
				),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * mcp-abilities/list-pages
	 * ------------------------------------------------------------------ */
	private static function register_list_pages(): void {
		wp_register_ability(
			self::NS . 'list-pages',
			array(
				'label'               => __( 'List pages', 'mcp-abilities' ),
				'description'         => __( 'Lists all static pages (home, services, contact, imprint, …). Useful for site inventory.', 'mcp-abilities' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'status' => array( 'type' => 'string', 'enum' => array( 'publish', 'draft', 'any' ), 'default' => 'any' ),
					),
				),
				'output_schema'       => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'id'        => array( 'type' => 'integer' ),
							'title'     => array( 'type' => 'string' ),
							'slug'      => array( 'type' => 'string' ),
							'status'    => array( 'type' => 'string' ),
							'parent_id' => array( 'type' => 'integer' ),
							'template'  => array( 'type' => 'string' ),
							'link'      => array( 'type' => 'string' ),
							'modified'  => array( 'type' => 'string' ),
						),
					),
				),
				'permission_callback' => static fn( $input = null ): bool => current_user_can( 'edit_pages' ),
				'execute_callback'    => array( Handlers::class, 'list_pages' ),
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true ),
					'annotations'  => array( 'readOnly' => true ),
				),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * mcp-abilities/get-page
	 * ------------------------------------------------------------------ */
	private static function register_get_page(): void {
		wp_register_ability(
			self::NS . 'get-page',
			array(
				'label'               => __( 'Get a single page', 'mcp-abilities' ),
				'description'         => __( 'Returns a single page including Gutenberg content, parent, template and menu order.', 'mcp-abilities' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'page_id' => array( 'type' => 'integer', 'minimum' => 1 ),
					),
					'required'   => array( 'page_id' ),
				),
				'output_schema'       => self::page_full_schema(),
				'permission_callback' => static function ( $input = null ): bool {
					$page_id = is_array( $input ) && isset( $input['page_id'] ) ? (int) $input['page_id'] : 0;
					return current_user_can( 'edit_page', $page_id ) || current_user_can( 'read_post', $page_id );
				},
				'execute_callback'    => array( Handlers::class, 'get_page' ),
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true ),
					'annotations'  => array( 'readOnly' => true ),
				),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * mcp-abilities/create-page
	 * ------------------------------------------------------------------ */
	private static function register_create_page(): void {
		wp_register_ability(
			self::NS . 'create-page',
			array(
				'label'               => __( 'Create a page', 'mcp-abilities' ),
				'description'         => __( 'Creates a new static page. Content is expected as Gutenberg block markup. Default status is "draft".', 'mcp-abilities' ),
				'category'            => self::CATEGORY,
				'input_schema'        => self::page_write_schema( true ),
				'output_schema'       => self::page_write_result_schema(),
				'permission_callback' => static function ( $input = null ): bool {
					$status = is_array( $input ) && isset( $input['status'] ) ? (string) $input['status'] : 'draft';
					if ( 'publish' === $status || 'future' === $status ) {
						return current_user_can( 'publish_pages' );
					}
					return current_user_can( 'edit_pages' );
				},
				'execute_callback'    => array( Handlers::class, 'create_page' ),
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true ),
					'annotations'  => array( 'destructiveHint' => false, 'idempotentHint' => false ),
				),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * mcp-abilities/update-page
	 * ------------------------------------------------------------------ */
	private static function register_update_page(): void {
		wp_register_ability(
			self::NS . 'update-page',
			array(
				'label'               => __( 'Update a page', 'mcp-abilities' ),
				'description'         => __( 'Updates an existing page. Only fields that are set will be overwritten. Set status=draft to unpublish.', 'mcp-abilities' ),
				'category'            => self::CATEGORY,
				'input_schema'        => self::page_write_schema( false ),
				'output_schema'       => self::page_write_result_schema(),
				'permission_callback' => static function ( $input = null ): bool {
					$page_id = is_array( $input ) && isset( $input['page_id'] ) ? (int) $input['page_id'] : 0;
					if ( $page_id <= 0 || ! current_user_can( 'edit_page', $page_id ) ) {
						return false;
					}
					$status = is_array( $input ) && isset( $input['status'] ) ? (string) $input['status'] : null;
					if ( ( 'publish' === $status || 'future' === $status ) && ! current_user_can( 'publish_pages' ) ) {
						return false;
					}
					return true;
				},
				'execute_callback'    => array( Handlers::class, 'update_page' ),
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true ),
					'annotations'  => array( 'destructiveHint' => false, 'idempotentHint' => true ),
				),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * mcp-abilities/delete-page
	 * ------------------------------------------------------------------ */
	private static function register_delete_page(): void {
		wp_register_ability(
			self::NS . 'delete-page',
			array(
				'label'               => __( 'Delete a page', 'mcp-abilities' ),
				'description'         => __( 'Deletes a page. By default moves to trash. Pass force=true to delete permanently.', 'mcp-abilities' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'page_id' => array( 'type' => 'integer', 'minimum' => 1 ),
						'force'   => array( 'type' => 'boolean', 'default' => false, 'description' => 'true = delete permanently, false = move to trash.' ),
					),
					'required'   => array( 'page_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'page_id' => array( 'type' => 'integer' ),
						'deleted' => array( 'type' => 'boolean' ),
						'force'   => array( 'type' => 'boolean' ),
					),
				),
				'permission_callback' => static function ( $input = null ): bool {
					$page_id = is_array( $input ) && isset( $input['page_id'] ) ? (int) $input['page_id'] : 0;
					return $page_id > 0 && current_user_can( 'delete_page', $page_id );
				},
				'execute_callback'    => array( Handlers::class, 'delete_page' ),
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true ),
					'annotations'  => array( 'destructiveHint' => true, 'idempotentHint' => true ),
				),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * mcp-abilities/site-inventory
	 * ------------------------------------------------------------------ */
	private static function register_site_inventory(): void {
		wp_register_ability(
			self::NS . 'site-inventory',
			array(
				'label'               => __( 'Site inventory', 'mcp-abilities' ),
				'description'         => __( 'Compact overview: post/page/media counts, active plugins, theme, WP and PHP versions.', 'mcp-abilities' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array( 'type' => 'object', 'properties' => new \stdClass() ),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'site'    => array( 'type' => 'object' ),
						'content' => array( 'type' => 'object' ),
						'theme'   => array( 'type' => 'object' ),
						'plugins' => array( 'type' => 'array' ),
					),
				),
				'permission_callback' => static fn( $input = null ): bool => current_user_can( 'manage_options' ),
				'execute_callback'    => array( Handlers::class, 'site_inventory' ),
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true ),
					'annotations'  => array( 'readOnly' => true ),
				),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Shared schema fragments
	 * ------------------------------------------------------------------ */
	private static function post_summary_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'id'         => array( 'type' => 'integer' ),
				'title'      => array( 'type' => 'string' ),
				'slug'       => array( 'type' => 'string' ),
				'status'     => array( 'type' => 'string' ),
				'date'       => array( 'type' => 'string' ),
				'modified'   => array( 'type' => 'string' ),
				'excerpt'    => array( 'type' => 'string' ),
				'link'       => array( 'type' => 'string' ),
				'edit_link'  => array( 'type' => 'string' ),
				'categories' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
				'tags'       => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
			),
		);
	}

	private static function post_full_schema(): array {
		$schema                          = self::post_summary_schema();
		$schema['properties']['content'] = array( 'type' => 'string' );
		return $schema;
	}

	private static function post_write_schema( bool $is_create ): array {
		$properties = array(
			'title'      => array( 'type' => 'string', 'minLength' => 1 ),
			'content'    => array( 'type' => 'string' ),
			'excerpt'    => array( 'type' => 'string' ),
			'slug'       => array( 'type' => 'string' ),
			'status'     => array(
				'type'    => 'string',
				'enum'    => array( 'draft', 'pending', 'publish', 'private', 'future' ),
				'default' => 'draft',
			),
			'categories' => array(
				'type'  => 'array',
				'items' => array( 'type' => array( 'string', 'integer' ) ),
			),
			'tags'       => array(
				'type'  => 'array',
				'items' => array( 'type' => 'string' ),
			),
			'date'       => array( 'type' => 'string' ),
		);

		if ( ! $is_create ) {
			$properties = array_merge(
				array( 'post_id' => array( 'type' => 'integer', 'minimum' => 1 ) ),
				$properties
			);
			return array(
				'type'       => 'object',
				'properties' => $properties,
				'required'   => array( 'post_id' ),
			);
		}

		return array(
			'type'       => 'object',
			'properties' => $properties,
			'required'   => array( 'title', 'content' ),
		);
	}

	private static function post_write_result_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'post_id'   => array( 'type' => 'integer' ),
				'status'    => array( 'type' => 'string' ),
				'link'      => array( 'type' => 'string' ),
				'edit_link' => array( 'type' => 'string' ),
				'slug'      => array( 'type' => 'string' ),
			),
		);
	}

	private static function term_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'id'          => array( 'type' => 'integer' ),
				'name'        => array( 'type' => 'string' ),
				'slug'        => array( 'type' => 'string' ),
				'description' => array( 'type' => 'string' ),
				'count'       => array( 'type' => 'integer' ),
			),
		);
	}

	private static function page_summary_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'id'         => array( 'type' => 'integer' ),
				'title'      => array( 'type' => 'string' ),
				'slug'       => array( 'type' => 'string' ),
				'status'     => array( 'type' => 'string' ),
				'date'       => array( 'type' => 'string' ),
				'modified'   => array( 'type' => 'string' ),
				'parent_id'  => array( 'type' => 'integer' ),
				'menu_order' => array( 'type' => 'integer' ),
				'template'   => array( 'type' => 'string' ),
				'link'       => array( 'type' => 'string' ),
				'edit_link'  => array( 'type' => 'string' ),
			),
		);
	}

	private static function page_full_schema(): array {
		$schema                          = self::page_summary_schema();
		$schema['properties']['content'] = array( 'type' => 'string' );
		$schema['properties']['excerpt'] = array( 'type' => 'string' );
		return $schema;
	}

	private static function page_write_schema( bool $is_create ): array {
		$properties = array(
			'title'      => array( 'type' => 'string', 'minLength' => 1 ),
			'content'    => array( 'type' => 'string' ),
			'excerpt'    => array( 'type' => 'string' ),
			'slug'       => array( 'type' => 'string' ),
			'status'     => array(
				'type'    => 'string',
				'enum'    => array( 'draft', 'pending', 'publish', 'private', 'future' ),
				'default' => 'draft',
			),
			'parent_id'  => array( 'type' => 'integer', 'minimum' => 0, 'description' => 'ID of the parent page (0 = none).' ),
			'menu_order' => array( 'type' => 'integer', 'description' => 'Order in menus/lists.' ),
			'template'   => array( 'type' => 'string', 'description' => 'Page template slug (empty = default).' ),
			'date'       => array( 'type' => 'string' ),
		);

		if ( ! $is_create ) {
			$properties = array_merge(
				array( 'page_id' => array( 'type' => 'integer', 'minimum' => 1 ) ),
				$properties
			);
			return array(
				'type'       => 'object',
				'properties' => $properties,
				'required'   => array( 'page_id' ),
			);
		}

		return array(
			'type'       => 'object',
			'properties' => $properties,
			'required'   => array( 'title', 'content' ),
		);
	}

	private static function page_write_result_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'page_id'   => array( 'type' => 'integer' ),
				'status'    => array( 'type' => 'string' ),
				'link'      => array( 'type' => 'string' ),
				'edit_link' => array( 'type' => 'string' ),
				'slug'      => array( 'type' => 'string' ),
			),
		);
	}

	/* =====================================================================
	 * Taxonomies — categories and tags CRUD
	 * ================================================================== */

	private static function register_create_category(): void {
		wp_register_ability(
			self::NS . 'create-category',
			array(
				'label'               => __( 'Create a category', 'mcp-abilities' ),
				'description'         => __( 'Creates a new blog category. Returns the created term.', 'mcp-abilities' ),
				'category'            => self::CATEGORY,
				'input_schema'        => self::term_create_schema(),
				'output_schema'       => self::term_schema(),
				'permission_callback' => static fn( $input = null ): bool => current_user_can( 'manage_categories' ),
				'execute_callback'    => array( Handlers::class, 'create_category' ),
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true ),
					'annotations'  => array( 'destructiveHint' => false, 'idempotentHint' => false ),
				),
			)
		);
	}

	private static function register_update_category(): void {
		wp_register_ability(
			self::NS . 'update-category',
			array(
				'label'               => __( 'Update a category', 'mcp-abilities' ),
				'description'         => __( 'Updates an existing category. Only fields that are set will be overwritten.', 'mcp-abilities' ),
				'category'            => self::CATEGORY,
				'input_schema'        => self::term_update_schema(),
				'output_schema'       => self::term_schema(),
				'permission_callback' => static fn( $input = null ): bool => current_user_can( 'manage_categories' ),
				'execute_callback'    => array( Handlers::class, 'update_category' ),
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true ),
					'annotations'  => array( 'destructiveHint' => false, 'idempotentHint' => true ),
				),
			)
		);
	}

	private static function register_delete_category(): void {
		wp_register_ability(
			self::NS . 'delete-category',
			array(
				'label'               => __( 'Delete a category', 'mcp-abilities' ),
				'description'         => __( 'Deletes a category. Posts in this category are not deleted but lose the assignment (and may fall back to the default category).', 'mcp-abilities' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'term_id' => array( 'type' => 'integer', 'minimum' => 1 ),
					),
					'required'   => array( 'term_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'term_id' => array( 'type' => 'integer' ),
						'deleted' => array( 'type' => 'boolean' ),
					),
				),
				'permission_callback' => static fn( $input = null ): bool => current_user_can( 'manage_categories' ),
				'execute_callback'    => array( Handlers::class, 'delete_category' ),
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true ),
					'annotations'  => array( 'destructiveHint' => true, 'idempotentHint' => true ),
				),
			)
		);
	}

	private static function register_create_tag(): void {
		wp_register_ability(
			self::NS . 'create-tag',
			array(
				'label'               => __( 'Create a tag', 'mcp-abilities' ),
				'description'         => __( 'Creates a new post tag. Returns the created term.', 'mcp-abilities' ),
				'category'            => self::CATEGORY,
				'input_schema'        => self::term_create_schema( false ),
				'output_schema'       => self::term_schema(),
				'permission_callback' => static fn( $input = null ): bool => current_user_can( 'manage_categories' ),
				'execute_callback'    => array( Handlers::class, 'create_tag' ),
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true ),
					'annotations'  => array( 'destructiveHint' => false, 'idempotentHint' => false ),
				),
			)
		);
	}

	private static function register_update_tag(): void {
		wp_register_ability(
			self::NS . 'update-tag',
			array(
				'label'               => __( 'Update a tag', 'mcp-abilities' ),
				'description'         => __( 'Updates an existing tag. Only fields that are set will be overwritten.', 'mcp-abilities' ),
				'category'            => self::CATEGORY,
				'input_schema'        => self::term_update_schema( false ),
				'output_schema'       => self::term_schema(),
				'permission_callback' => static fn( $input = null ): bool => current_user_can( 'manage_categories' ),
				'execute_callback'    => array( Handlers::class, 'update_tag' ),
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true ),
					'annotations'  => array( 'destructiveHint' => false, 'idempotentHint' => true ),
				),
			)
		);
	}

	private static function register_delete_tag(): void {
		wp_register_ability(
			self::NS . 'delete-tag',
			array(
				'label'               => __( 'Delete a tag', 'mcp-abilities' ),
				'description'         => __( 'Deletes a tag. Posts using this tag are not deleted but lose the tag.', 'mcp-abilities' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'term_id' => array( 'type' => 'integer', 'minimum' => 1 ),
					),
					'required'   => array( 'term_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'term_id' => array( 'type' => 'integer' ),
						'deleted' => array( 'type' => 'boolean' ),
					),
				),
				'permission_callback' => static fn( $input = null ): bool => current_user_can( 'manage_categories' ),
				'execute_callback'    => array( Handlers::class, 'delete_tag' ),
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true ),
					'annotations'  => array( 'destructiveHint' => true, 'idempotentHint' => true ),
				),
			)
		);
	}

	/* =====================================================================
	 * Media
	 * ================================================================== */

	private static function register_list_media(): void {
		wp_register_ability(
			self::NS . 'list-media',
			array(
				'label'               => __( 'List media', 'mcp-abilities' ),
				'description'         => __( 'Lists media library attachments with optional filters (mime type, search, pagination). Newest first.', 'mcp-abilities' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'mime_type' => array( 'type' => 'string', 'description' => 'e.g. "image", "image/jpeg", "application/pdf".' ),
						'search'    => array( 'type' => 'string' ),
						'per_page'  => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20 ),
						'page'      => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'total' => array( 'type' => 'integer' ),
						'items' => array( 'type' => 'array', 'items' => self::attachment_summary_schema() ),
					),
				),
				'permission_callback' => static fn( $input = null ): bool => current_user_can( 'upload_files' ),
				'execute_callback'    => array( Handlers::class, 'list_media' ),
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true ),
					'annotations'  => array( 'readOnly' => true ),
				),
			)
		);
	}

	private static function register_get_media(): void {
		wp_register_ability(
			self::NS . 'get-media',
			array(
				'label'               => __( 'Get a media attachment', 'mcp-abilities' ),
				'description'         => __( 'Returns a single attachment with metadata (dimensions, alt text, mime type, file size).', 'mcp-abilities' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'attachment_id' => array( 'type' => 'integer', 'minimum' => 1 ),
					),
					'required'   => array( 'attachment_id' ),
				),
				'output_schema'       => self::attachment_summary_schema(),
				'permission_callback' => static fn( $input = null ): bool => current_user_can( 'upload_files' ),
				'execute_callback'    => array( Handlers::class, 'get_media' ),
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true ),
					'annotations'  => array( 'readOnly' => true ),
				),
			)
		);
	}

	private static function register_upload_media_from_url(): void {
		wp_register_ability(
			self::NS . 'upload-media-from-url',
			array(
				'label'               => __( 'Upload media from URL', 'mcp-abilities' ),
				'description'         => __( 'Downloads a remote file (typically an image) and adds it to the media library. Optionally attaches it to a post.', 'mcp-abilities' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'url'       => array( 'type' => 'string', 'format' => 'uri' ),
						'title'     => array( 'type' => 'string' ),
						'alt_text'  => array( 'type' => 'string', 'description' => 'Alternative text (for images).' ),
						'caption'   => array( 'type' => 'string' ),
						'parent_id' => array( 'type' => 'integer', 'minimum' => 0, 'description' => 'Post ID to attach the media to (0 = unattached).' ),
					),
					'required'   => array( 'url' ),
				),
				'output_schema'       => self::attachment_summary_schema(),
				'permission_callback' => static fn( $input = null ): bool => current_user_can( 'upload_files' ),
				'execute_callback'    => array( Handlers::class, 'upload_media_from_url' ),
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true ),
					'annotations'  => array( 'destructiveHint' => false, 'idempotentHint' => false ),
				),
			)
		);
	}

	private static function register_set_featured_image(): void {
		wp_register_ability(
			self::NS . 'set-featured-image',
			array(
				'label'               => __( 'Set featured image', 'mcp-abilities' ),
				'description'         => __( 'Sets the featured image (post thumbnail) of a post or page. Pass attachment_id=0 to remove.', 'mcp-abilities' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'       => array( 'type' => 'integer', 'minimum' => 1 ),
						'attachment_id' => array( 'type' => 'integer', 'minimum' => 0, 'description' => '0 to remove the featured image.' ),
					),
					'required'   => array( 'post_id', 'attachment_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'       => array( 'type' => 'integer' ),
						'attachment_id' => array( 'type' => 'integer' ),
						'success'       => array( 'type' => 'boolean' ),
					),
				),
				'permission_callback' => static function ( $input = null ): bool {
					$post_id = is_array( $input ) && isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
					return $post_id > 0 && current_user_can( 'edit_post', $post_id );
				},
				'execute_callback'    => array( Handlers::class, 'set_featured_image' ),
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true ),
					'annotations'  => array( 'destructiveHint' => false, 'idempotentHint' => true ),
				),
			)
		);
	}

	private static function register_delete_media(): void {
		wp_register_ability(
			self::NS . 'delete-media',
			array(
				'label'               => __( 'Delete a media attachment', 'mcp-abilities' ),
				'description'         => __( 'Deletes a media attachment. By default permanently (force=true) since media has no native trash. Pass force=false to attempt trashing.', 'mcp-abilities' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'attachment_id' => array( 'type' => 'integer', 'minimum' => 1 ),
						'force'         => array( 'type' => 'boolean', 'default' => true ),
					),
					'required'   => array( 'attachment_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'attachment_id' => array( 'type' => 'integer' ),
						'deleted'       => array( 'type' => 'boolean' ),
					),
				),
				'permission_callback' => static function ( $input = null ): bool {
					$attachment_id = is_array( $input ) && isset( $input['attachment_id'] ) ? (int) $input['attachment_id'] : 0;
					return $attachment_id > 0 && current_user_can( 'delete_post', $attachment_id );
				},
				'execute_callback'    => array( Handlers::class, 'delete_media' ),
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true ),
					'annotations'  => array( 'destructiveHint' => true, 'idempotentHint' => true ),
				),
			)
		);
	}

	/* =====================================================================
	 * Post meta
	 * ================================================================== */

	private static function register_get_post_meta(): void {
		wp_register_ability(
			self::NS . 'get-post-meta',
			array(
				'label'               => __( 'Get post meta', 'mcp-abilities' ),
				'description'         => __( 'Returns post meta for a post. Without "key", returns all non-protected meta. With "key", returns just that key. Sites can restrict allowed keys via the `mcp_abilities_meta_allowlist` filter.', 'mcp-abilities' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array( 'type' => 'integer', 'minimum' => 1 ),
						'key'     => array( 'type' => 'string' ),
					),
					'required'   => array( 'post_id' ),
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => 'Map of meta key → value.',
				),
				'permission_callback' => static function ( $input = null ): bool {
					$post_id = is_array( $input ) && isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
					return $post_id > 0 && current_user_can( 'edit_post', $post_id );
				},
				'execute_callback'    => array( Handlers::class, 'get_post_meta' ),
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true ),
					'annotations'  => array( 'readOnly' => true ),
				),
			)
		);
	}

	private static function register_set_post_meta(): void {
		wp_register_ability(
			self::NS . 'set-post-meta',
			array(
				'label'               => __( 'Set post meta', 'mcp-abilities' ),
				'description'         => __( 'Sets a single post meta key/value. Protected keys (starting with "_") are blocked unless explicitly allowlisted via the `mcp_abilities_meta_allowlist` filter.', 'mcp-abilities' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array( 'type' => 'integer', 'minimum' => 1 ),
						'key'     => array( 'type' => 'string', 'minLength' => 1 ),
						'value'   => array( 'description' => 'String, number, boolean, array or object.' ),
					),
					'required'   => array( 'post_id', 'key', 'value' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array( 'type' => 'integer' ),
						'key'     => array( 'type' => 'string' ),
						'success' => array( 'type' => 'boolean' ),
					),
				),
				'permission_callback' => static function ( $input = null ): bool {
					$post_id = is_array( $input ) && isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
					return $post_id > 0 && current_user_can( 'edit_post', $post_id );
				},
				'execute_callback'    => array( Handlers::class, 'set_post_meta' ),
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true ),
					'annotations'  => array( 'destructiveHint' => false, 'idempotentHint' => true ),
				),
			)
		);
	}

	/* =====================================================================
	 * Discovery
	 * ================================================================== */

	private static function register_list_post_types(): void {
		wp_register_ability(
			self::NS . 'list-post-types',
			array(
				'label'               => __( 'List post types', 'mcp-abilities' ),
				'description'         => __( 'Lists registered public post types (built-in and custom).', 'mcp-abilities' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array( 'type' => 'object', 'properties' => new \stdClass() ),
				'output_schema'       => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'name'        => array( 'type' => 'string' ),
							'label'       => array( 'type' => 'string' ),
							'description' => array( 'type' => 'string' ),
							'public'      => array( 'type' => 'boolean' ),
							'hierarchical'=> array( 'type' => 'boolean' ),
							'rest_base'   => array( 'type' => 'string' ),
							'taxonomies'  => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
						),
					),
				),
				'permission_callback' => static fn( $input = null ): bool => current_user_can( 'edit_posts' ),
				'execute_callback'    => array( Handlers::class, 'list_post_types' ),
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true ),
					'annotations'  => array( 'readOnly' => true ),
				),
			)
		);
	}

	private static function register_get_current_user(): void {
		wp_register_ability(
			self::NS . 'get-current-user',
			array(
				'label'               => __( 'Get current user', 'mcp-abilities' ),
				'description'         => __( 'Returns the user that the MCP session is authenticated as: id, login, display name, email, roles, and capabilities relevant to this plugin.', 'mcp-abilities' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array( 'type' => 'object', 'properties' => new \stdClass() ),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'           => array( 'type' => 'integer' ),
						'login'        => array( 'type' => 'string' ),
						'display_name' => array( 'type' => 'string' ),
						'email'        => array( 'type' => 'string' ),
						'roles'        => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
						'capabilities' => array( 'type' => 'object', 'description' => 'Capabilities relevant to MCP Abilities (edit_posts, publish_posts, …).' ),
					),
				),
				'permission_callback' => static fn( $input = null ): bool => is_user_logged_in(),
				'execute_callback'    => array( Handlers::class, 'get_current_user' ),
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true ),
					'annotations'  => array( 'readOnly' => true ),
				),
			)
		);
	}

	/* =====================================================================
	 * Shared schema fragments — taxonomy and media
	 * ================================================================== */

	private static function term_create_schema( bool $hierarchical = true ): array {
		$properties = array(
			'name'        => array( 'type' => 'string', 'minLength' => 1 ),
			'slug'        => array( 'type' => 'string' ),
			'description' => array( 'type' => 'string' ),
		);
		if ( $hierarchical ) {
			$properties['parent'] = array( 'type' => 'integer', 'minimum' => 0, 'description' => 'Parent term ID (0 = top-level).' );
		}
		return array(
			'type'       => 'object',
			'properties' => $properties,
			'required'   => array( 'name' ),
		);
	}

	private static function term_update_schema( bool $hierarchical = true ): array {
		$properties = array(
			'term_id'     => array( 'type' => 'integer', 'minimum' => 1 ),
			'name'        => array( 'type' => 'string', 'minLength' => 1 ),
			'slug'        => array( 'type' => 'string' ),
			'description' => array( 'type' => 'string' ),
		);
		if ( $hierarchical ) {
			$properties['parent'] = array( 'type' => 'integer', 'minimum' => 0 );
		}
		return array(
			'type'       => 'object',
			'properties' => $properties,
			'required'   => array( 'term_id' ),
		);
	}

	private static function attachment_summary_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'id'         => array( 'type' => 'integer' ),
				'title'      => array( 'type' => 'string' ),
				'slug'       => array( 'type' => 'string' ),
				'mime_type'  => array( 'type' => 'string' ),
				'url'        => array( 'type' => 'string' ),
				'alt_text'   => array( 'type' => 'string' ),
				'caption'    => array( 'type' => 'string' ),
				'parent_id'  => array( 'type' => 'integer' ),
				'width'      => array( 'type' => 'integer' ),
				'height'     => array( 'type' => 'integer' ),
				'filesize'   => array( 'type' => 'integer', 'description' => 'Size in bytes.' ),
				'date'       => array( 'type' => 'string' ),
				'edit_link'  => array( 'type' => 'string' ),
			),
		);
	}
}
