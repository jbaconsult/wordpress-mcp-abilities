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
	 * Central list of all ability names (dashes, no underscores).
	 *
	 * @return string[]
	 */
	public static function ability_names(): array {
		return array(
			self::NS . 'list-posts',
			self::NS . 'get-post',
			self::NS . 'create-post',
			self::NS . 'update-post',
			self::NS . 'list-categories',
			self::NS . 'list-tags',
			self::NS . 'list-pages',
			self::NS . 'get-page',
			self::NS . 'create-page',
			self::NS . 'update-page',
			self::NS . 'delete-page',
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
		self::register_list_posts();
		self::register_get_post();
		self::register_create_post();
		self::register_update_post();
		self::register_list_categories();
		self::register_list_tags();
		self::register_list_pages();
		self::register_get_page();
		self::register_create_page();
		self::register_update_page();
		self::register_delete_page();
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
		$args[] = self::ability_names(); // Tools
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
}
