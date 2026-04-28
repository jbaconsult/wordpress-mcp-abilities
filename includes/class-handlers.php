<?php
/**
 * Handlers — execution logic for the registered abilities.
 *
 * Method names use underscores (PHP methods, not ability IDs).
 *
 * @package MCPAbilities
 */

namespace MCPAbilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Handlers {

	/* ---------------------------------------------------------------------
	 * READ
	 * ------------------------------------------------------------------ */
	public static function list_posts( $input = null ): array|\WP_Error {
		$input  = is_array( $input ) ? $input : array();
		$status = $input['status'] ?? 'publish';
		$q_args = array(
			'post_type'      => 'post',
			'post_status'    => 'any' === $status ? array( 'publish', 'draft', 'pending', 'private', 'future' ) : $status,
			'posts_per_page' => (int) ( $input['per_page'] ?? 20 ),
			'paged'          => (int) ( $input['page'] ?? 1 ),
			'orderby'        => $input['orderby'] ?? 'date',
			'order'          => $input['order'] ?? 'DESC',
			'no_found_rows'  => false,
		);
		if ( ! empty( $input['search'] ) ) {
			$q_args['s'] = (string) $input['search'];
		}
		if ( ! empty( $input['category'] ) ) {
			$q_args['category_name'] = (string) $input['category'];
		}

		$query = new \WP_Query( $q_args );
		$items = array();
		foreach ( $query->posts as $post ) {
			$items[] = self::post_to_summary( $post );
		}

		return array(
			'total' => (int) $query->found_posts,
			'posts' => $items,
		);
	}

	public static function get_post( $input = null ): array|\WP_Error {
		$post_id = is_array( $input ) ? (int) ( $input['post_id'] ?? 0 ) : 0;
		$post    = \get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error( 'mcp_abilities_not_found', __( 'Post not found.', 'mcp-abilities' ), array( 'post_id' => $post_id ) );
		}
		$summary            = self::post_to_summary( $post );
		$summary['content'] = (string) $post->post_content;
		return $summary;
	}

	public static function list_categories( $input = null ): array|\WP_Error {
		$terms = get_terms(
			array(
				'taxonomy'   => 'category',
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $terms ) ) {
			return $terms;
		}
		return array_map( array( self::class, 'term_to_array' ), $terms );
	}

	public static function list_tags( $input = null ): array|\WP_Error {
		$input = is_array( $input ) ? $input : array();
		$args  = array(
			'taxonomy'   => 'post_tag',
			'hide_empty' => false,
			'number'     => (int) ( $input['limit'] ?? 100 ),
		);
		if ( ! empty( $input['search'] ) ) {
			$args['search'] = (string) $input['search'];
		}
		$terms = get_terms( $args );
		if ( is_wp_error( $terms ) ) {
			return $terms;
		}
		return array_map( array( self::class, 'term_to_array' ), $terms );
	}

	public static function list_pages( $input = null ): array|\WP_Error {
		$input  = is_array( $input ) ? $input : array();
		$status = $input['status'] ?? 'any';
		$query  = new \WP_Query(
			array(
				'post_type'      => 'page',
				'post_status'    => 'any' === $status ? array( 'publish', 'draft' ) : $status,
				'posts_per_page' => 200,
				'orderby'        => 'menu_order title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);
		$items  = array();
		foreach ( $query->posts as $page ) {
			$items[] = array(
				'id'        => (int) $page->ID,
				'title'     => (string) $page->post_title,
				'slug'      => (string) $page->post_name,
				'status'    => (string) $page->post_status,
				'parent_id' => (int) $page->post_parent,
				'template'  => (string) ( get_page_template_slug( $page->ID ) ?: 'default' ),
				'link'      => (string) get_permalink( $page ),
				'modified'  => mysql2date( 'c', $page->post_modified, false ),
			);
		}
		return $items;
	}

	public static function site_inventory( $input = null ): array|\WP_Error {
		global $wp_version;

		$theme        = wp_get_theme();
		$plugins_data = array();
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach ( get_plugins() as $file => $data ) {
			$plugins_data[] = array(
				'name'    => (string) ( $data['Name'] ?? '' ),
				'slug'    => (string) $file,
				'version' => (string) ( $data['Version'] ?? '' ),
				'active'  => is_plugin_active( $file ),
			);
		}

		$counts_posts = wp_count_posts( 'post' );
		$counts_pages = wp_count_posts( 'page' );
		$media_count  = array_sum( (array) wp_count_attachments() );

		return array(
			'site'    => array(
				'name'        => (string) get_bloginfo( 'name' ),
				'url'         => (string) home_url(),
				'description' => (string) get_bloginfo( 'description' ),
				'language'    => (string) get_bloginfo( 'language' ),
				'wp_version'  => (string) $wp_version,
				'php_version' => PHP_VERSION,
			),
			'content' => array(
				'posts_published' => (int) ( $counts_posts->publish ?? 0 ),
				'posts_drafts'    => (int) ( $counts_posts->draft ?? 0 ),
				'pages'           => (int) ( ( $counts_pages->publish ?? 0 ) + ( $counts_pages->draft ?? 0 ) ),
				'media'           => (int) $media_count,
				'categories'      => (int) wp_count_terms( array( 'taxonomy' => 'category', 'hide_empty' => false ) ),
				'tags'            => (int) wp_count_terms( array( 'taxonomy' => 'post_tag', 'hide_empty' => false ) ),
			),
			'theme'   => array(
				'name'    => (string) $theme->get( 'Name' ),
				'version' => (string) $theme->get( 'Version' ),
				'parent'  => $theme->parent() ? (string) $theme->parent()->get( 'Name' ) : '',
			),
			'plugins' => $plugins_data,
		);
	}

	/* ---------------------------------------------------------------------
	 * WRITE
	 * ------------------------------------------------------------------ */
	public static function create_post( $input = null ): array|\WP_Error {
		$input     = is_array( $input ) ? $input : array();
		$post_data = self::prepare_post_data( $input, true );
		if ( is_wp_error( $post_data ) ) {
			return $post_data;
		}
		$post_id = wp_insert_post( $post_data, true );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		return self::apply_taxonomies_and_return( (int) $post_id, $input );
	}

	public static function update_post( $input = null ): array|\WP_Error {
		$input    = is_array( $input ) ? $input : array();
		$post_id  = (int) ( $input['post_id'] ?? 0 );
		$existing = \get_post( $post_id );
		if ( ! $existing instanceof \WP_Post ) {
			return new \WP_Error( 'mcp_abilities_not_found', __( 'Post not found.', 'mcp-abilities' ), array( 'post_id' => $post_id ) );
		}
		$post_data = self::prepare_post_data( $input, false );
		if ( is_wp_error( $post_data ) ) {
			return $post_data;
		}
		$post_data['ID'] = $post_id;
		$result          = wp_update_post( $post_data, true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::apply_taxonomies_and_return( $post_id, $input );
	}

	public static function delete_post( $input = null ): array|\WP_Error {
		$input   = is_array( $input ) ? $input : array();
		$post_id = (int) ( $input['post_id'] ?? 0 );
		$force   = (bool) ( $input['force'] ?? false );

		$existing = \get_post( $post_id );
		if ( ! $existing instanceof \WP_Post || 'post' !== $existing->post_type ) {
			return new \WP_Error( 'mcp_abilities_not_found', __( 'Post not found.', 'mcp-abilities' ), array( 'post_id' => $post_id ) );
		}

		$result = wp_delete_post( $post_id, $force );
		if ( false === $result || null === $result ) {
			return new \WP_Error( 'mcp_abilities_delete_failed', __( 'Post could not be deleted.', 'mcp-abilities' ), array( 'post_id' => $post_id ) );
		}

		return array(
			'post_id' => $post_id,
			'deleted' => true,
			'force'   => $force,
		);
	}

	public static function get_page( $input = null ): array|\WP_Error {
		$page_id = is_array( $input ) ? (int) ( $input['page_id'] ?? 0 ) : 0;
		$page    = \get_post( $page_id );
		if ( ! $page instanceof \WP_Post || 'page' !== $page->post_type ) {
			return new \WP_Error( 'mcp_abilities_not_found', __( 'Page not found.', 'mcp-abilities' ), array( 'page_id' => $page_id ) );
		}
		$summary            = self::page_to_summary( $page );
		$summary['content'] = (string) $page->post_content;
		$summary['excerpt'] = wp_strip_all_tags( get_the_excerpt( $page ) );
		return $summary;
	}

	public static function create_page( $input = null ): array|\WP_Error {
		$input     = is_array( $input ) ? $input : array();
		$page_data = self::prepare_page_data( $input, true );
		if ( is_wp_error( $page_data ) ) {
			return $page_data;
		}
		$page_id = wp_insert_post( $page_data, true );
		if ( is_wp_error( $page_id ) ) {
			return $page_id;
		}
		return self::apply_page_meta_and_return( (int) $page_id, $input );
	}

	public static function update_page( $input = null ): array|\WP_Error {
		$input    = is_array( $input ) ? $input : array();
		$page_id  = (int) ( $input['page_id'] ?? 0 );
		$existing = \get_post( $page_id );
		if ( ! $existing instanceof \WP_Post || 'page' !== $existing->post_type ) {
			return new \WP_Error( 'mcp_abilities_not_found', __( 'Page not found.', 'mcp-abilities' ), array( 'page_id' => $page_id ) );
		}
		$page_data = self::prepare_page_data( $input, false );
		if ( is_wp_error( $page_data ) ) {
			return $page_data;
		}
		$page_data['ID'] = $page_id;
		$result          = wp_update_post( $page_data, true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::apply_page_meta_and_return( $page_id, $input );
	}

	public static function delete_page( $input = null ): array|\WP_Error {
		$input   = is_array( $input ) ? $input : array();
		$page_id = (int) ( $input['page_id'] ?? 0 );
		$force   = (bool) ( $input['force'] ?? false );

		$existing = \get_post( $page_id );
		if ( ! $existing instanceof \WP_Post || 'page' !== $existing->post_type ) {
			return new \WP_Error( 'mcp_abilities_not_found', __( 'Page not found.', 'mcp-abilities' ), array( 'page_id' => $page_id ) );
		}

		$result = wp_delete_post( $page_id, $force );
		if ( false === $result || null === $result ) {
			return new \WP_Error( 'mcp_abilities_delete_failed', __( 'Page could not be deleted.', 'mcp-abilities' ), array( 'page_id' => $page_id ) );
		}

		return array(
			'page_id' => $page_id,
			'deleted' => true,
			'force'   => $force,
		);
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */
	private static function prepare_post_data( array $input, bool $is_create ): array|\WP_Error {
		$data = array( 'post_type' => 'post' );

		if ( $is_create || isset( $input['title'] ) ) {
			$data['post_title'] = wp_strip_all_tags( (string) ( $input['title'] ?? '' ) );
		}
		if ( $is_create || isset( $input['content'] ) ) {
			// Intentionally no wp_kses — would destroy Gutenberg block comments (<!-- wp:... -->).
			// Protection comes from the capability check (edit_posts = admin/editor).
			$data['post_content'] = (string) ( $input['content'] ?? '' );
		}
		if ( isset( $input['excerpt'] ) ) {
			$data['post_excerpt'] = wp_strip_all_tags( (string) $input['excerpt'] );
		}
		if ( isset( $input['slug'] ) && '' !== (string) $input['slug'] ) {
			$data['post_name'] = sanitize_title( (string) $input['slug'] );
		}
		if ( isset( $input['status'] ) ) {
			$data['post_status'] = (string) $input['status'];
		} elseif ( $is_create ) {
			$data['post_status'] = 'draft';
		}
		if ( isset( $input['date'] ) && '' !== (string) $input['date'] ) {
			$timestamp = strtotime( (string) $input['date'] );
			if ( false === $timestamp ) {
				return new \WP_Error( 'mcp_abilities_invalid_date', __( 'Invalid date format.', 'mcp-abilities' ) );
			}
			$data['post_date']     = gmdate( 'Y-m-d H:i:s', $timestamp );
			$data['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', $timestamp );
		}
		return $data;
	}

	private static function apply_taxonomies_and_return( int $post_id, array $input ): array|\WP_Error {
		if ( isset( $input['categories'] ) && is_array( $input['categories'] ) ) {
			$ids = self::resolve_category_ids( $input['categories'] );
			wp_set_post_categories( $post_id, $ids, false );
		}
		if ( isset( $input['tags'] ) && is_array( $input['tags'] ) ) {
			wp_set_post_tags( $post_id, array_map( 'strval', $input['tags'] ), false );
		}
		$post = \get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error( 'mcp_abilities_not_found', __( 'Post could not be read after writing.', 'mcp-abilities' ) );
		}
		return array(
			'post_id'   => (int) $post->ID,
			'status'    => (string) $post->post_status,
			'link'      => (string) get_permalink( $post ),
			'edit_link' => (string) get_edit_post_link( $post, 'url' ),
			'slug'      => (string) $post->post_name,
		);
	}

	private static function resolve_category_ids( array $categories ): array {
		$ids = array();
		foreach ( $categories as $entry ) {
			if ( is_int( $entry ) || ctype_digit( (string) $entry ) ) {
				$ids[] = (int) $entry;
				continue;
			}
			$term = get_term_by( 'slug', sanitize_title( (string) $entry ), 'category' );
			if ( $term instanceof \WP_Term ) {
				$ids[] = (int) $term->term_id;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	private static function prepare_page_data( array $input, bool $is_create ): array|\WP_Error {
		$data = array( 'post_type' => 'page' );

		if ( $is_create || isset( $input['title'] ) ) {
			$data['post_title'] = wp_strip_all_tags( (string) ( $input['title'] ?? '' ) );
		}
		if ( $is_create || isset( $input['content'] ) ) {
			// No wp_kses — Gutenberg block comments must be preserved.
			$data['post_content'] = (string) ( $input['content'] ?? '' );
		}
		if ( isset( $input['excerpt'] ) ) {
			$data['post_excerpt'] = wp_strip_all_tags( (string) $input['excerpt'] );
		}
		if ( isset( $input['slug'] ) && '' !== (string) $input['slug'] ) {
			$data['post_name'] = sanitize_title( (string) $input['slug'] );
		}
		if ( isset( $input['status'] ) ) {
			$data['post_status'] = (string) $input['status'];
		} elseif ( $is_create ) {
			$data['post_status'] = 'draft';
		}
		if ( isset( $input['parent_id'] ) ) {
			$data['post_parent'] = max( 0, (int) $input['parent_id'] );
		}
		if ( isset( $input['menu_order'] ) ) {
			$data['menu_order'] = (int) $input['menu_order'];
		}
		if ( isset( $input['date'] ) && '' !== (string) $input['date'] ) {
			$timestamp = strtotime( (string) $input['date'] );
			if ( false === $timestamp ) {
				return new \WP_Error( 'mcp_abilities_invalid_date', __( 'Invalid date format.', 'mcp-abilities' ) );
			}
			$data['post_date']     = gmdate( 'Y-m-d H:i:s', $timestamp );
			$data['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', $timestamp );
		}
		return $data;
	}

	private static function apply_page_meta_and_return( int $page_id, array $input ): array|\WP_Error {
		// Page template is stored as post meta (separate call from wp_update_post).
		if ( isset( $input['template'] ) ) {
			$template = (string) $input['template'];
			if ( '' === $template || 'default' === $template ) {
				delete_post_meta( $page_id, '_wp_page_template' );
			} else {
				update_post_meta( $page_id, '_wp_page_template', $template );
			}
		}

		$page = \get_post( $page_id );
		if ( ! $page instanceof \WP_Post ) {
			return new \WP_Error( 'mcp_abilities_not_found', __( 'Page could not be read after writing.', 'mcp-abilities' ) );
		}
		return array(
			'page_id'   => (int) $page->ID,
			'status'    => (string) $page->post_status,
			'link'      => (string) get_permalink( $page ),
			'edit_link' => (string) get_edit_post_link( $page, 'url' ),
			'slug'      => (string) $page->post_name,
		);
	}

	private static function post_to_summary( \WP_Post $post ): array {
		$cats = wp_get_post_categories( $post->ID, array( 'fields' => 'slugs' ) );
		$tags = wp_get_post_tags( $post->ID, array( 'fields' => 'slugs' ) );
		return array(
			'id'         => (int) $post->ID,
			'title'      => (string) get_the_title( $post ),
			'slug'       => (string) $post->post_name,
			'status'     => (string) $post->post_status,
			'date'       => mysql2date( 'c', $post->post_date, false ),
			'modified'   => mysql2date( 'c', $post->post_modified, false ),
			'excerpt'    => wp_strip_all_tags( get_the_excerpt( $post ) ),
			'link'       => (string) get_permalink( $post ),
			'edit_link'  => (string) get_edit_post_link( $post, 'url' ),
			'categories' => is_array( $cats ) ? array_map( 'strval', $cats ) : array(),
			'tags'       => is_array( $tags ) ? array_map( 'strval', $tags ) : array(),
		);
	}

	private static function term_to_array( \WP_Term $term ): array {
		return array(
			'id'          => (int) $term->term_id,
			'name'        => (string) $term->name,
			'slug'        => (string) $term->slug,
			'description' => (string) $term->description,
			'count'       => (int) $term->count,
		);
	}

	private static function page_to_summary( \WP_Post $page ): array {
		return array(
			'id'         => (int) $page->ID,
			'title'      => (string) get_the_title( $page ),
			'slug'       => (string) $page->post_name,
			'status'     => (string) $page->post_status,
			'date'       => mysql2date( 'c', $page->post_date, false ),
			'modified'   => mysql2date( 'c', $page->post_modified, false ),
			'parent_id'  => (int) $page->post_parent,
			'menu_order' => (int) $page->menu_order,
			'template'   => (string) ( get_page_template_slug( $page->ID ) ?: 'default' ),
			'link'       => (string) get_permalink( $page ),
			'edit_link'  => (string) get_edit_post_link( $page, 'url' ),
		);
	}

	/* =====================================================================
	 * Taxonomies
	 * ================================================================== */

	public static function create_category( $input = null ): array|\WP_Error {
		return self::create_term( is_array( $input ) ? $input : array(), 'category' );
	}

	public static function update_category( $input = null ): array|\WP_Error {
		return self::update_term( is_array( $input ) ? $input : array(), 'category' );
	}

	public static function delete_category( $input = null ): array|\WP_Error {
		return self::delete_term( is_array( $input ) ? $input : array(), 'category' );
	}

	public static function create_tag( $input = null ): array|\WP_Error {
		return self::create_term( is_array( $input ) ? $input : array(), 'post_tag' );
	}

	public static function update_tag( $input = null ): array|\WP_Error {
		return self::update_term( is_array( $input ) ? $input : array(), 'post_tag' );
	}

	public static function delete_tag( $input = null ): array|\WP_Error {
		return self::delete_term( is_array( $input ) ? $input : array(), 'post_tag' );
	}

	private static function create_term( array $input, string $taxonomy ): array|\WP_Error {
		$name = isset( $input['name'] ) ? trim( (string) $input['name'] ) : '';
		if ( '' === $name ) {
			return new \WP_Error( 'mcp_abilities_invalid_input', __( 'Term name is required.', 'mcp-abilities' ) );
		}
		$args = array();
		if ( isset( $input['slug'] ) && '' !== (string) $input['slug'] ) {
			$args['slug'] = sanitize_title( (string) $input['slug'] );
		}
		if ( isset( $input['description'] ) ) {
			$args['description'] = (string) $input['description'];
		}
		if ( 'category' === $taxonomy && isset( $input['parent'] ) ) {
			$args['parent'] = max( 0, (int) $input['parent'] );
		}
		$result = wp_insert_term( $name, $taxonomy, $args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$term = get_term( (int) $result['term_id'], $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error( 'mcp_abilities_not_found', __( 'Term could not be read after writing.', 'mcp-abilities' ) );
		}
		return self::term_to_array( $term );
	}

	private static function update_term( array $input, string $taxonomy ): array|\WP_Error {
		$term_id = (int) ( $input['term_id'] ?? 0 );
		if ( $term_id <= 0 ) {
			return new \WP_Error( 'mcp_abilities_invalid_input', __( 'term_id is required.', 'mcp-abilities' ) );
		}
		$existing = get_term( $term_id, $taxonomy );
		if ( ! $existing instanceof \WP_Term ) {
			return new \WP_Error( 'mcp_abilities_not_found', __( 'Term not found.', 'mcp-abilities' ), array( 'term_id' => $term_id ) );
		}
		$args = array();
		if ( isset( $input['name'] ) ) {
			$args['name'] = (string) $input['name'];
		}
		if ( isset( $input['slug'] ) && '' !== (string) $input['slug'] ) {
			$args['slug'] = sanitize_title( (string) $input['slug'] );
		}
		if ( isset( $input['description'] ) ) {
			$args['description'] = (string) $input['description'];
		}
		if ( 'category' === $taxonomy && isset( $input['parent'] ) ) {
			$args['parent'] = max( 0, (int) $input['parent'] );
		}
		$result = wp_update_term( $term_id, $taxonomy, $args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error( 'mcp_abilities_not_found', __( 'Term could not be read after writing.', 'mcp-abilities' ) );
		}
		return self::term_to_array( $term );
	}

	private static function delete_term( array $input, string $taxonomy ): array|\WP_Error {
		$term_id = (int) ( $input['term_id'] ?? 0 );
		if ( $term_id <= 0 ) {
			return new \WP_Error( 'mcp_abilities_invalid_input', __( 'term_id is required.', 'mcp-abilities' ) );
		}
		$result = wp_delete_term( $term_id, $taxonomy );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( false === $result || 0 === $result ) {
			return new \WP_Error( 'mcp_abilities_delete_failed', __( 'Term could not be deleted.', 'mcp-abilities' ), array( 'term_id' => $term_id ) );
		}
		return array(
			'term_id' => $term_id,
			'deleted' => true,
		);
	}

	/* =====================================================================
	 * Media
	 * ================================================================== */

	public static function list_media( $input = null ): array|\WP_Error {
		$input  = is_array( $input ) ? $input : array();
		$q_args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => (int) ( $input['per_page'] ?? 20 ),
			'paged'          => (int) ( $input['page'] ?? 1 ),
			'orderby'        => 'date',
			'order'          => 'DESC',
		);
		if ( ! empty( $input['mime_type'] ) ) {
			$q_args['post_mime_type'] = (string) $input['mime_type'];
		}
		if ( ! empty( $input['search'] ) ) {
			$q_args['s'] = (string) $input['search'];
		}
		$query = new \WP_Query( $q_args );
		$items = array();
		foreach ( $query->posts as $att ) {
			$items[] = self::attachment_to_summary( $att );
		}
		return array(
			'total' => (int) $query->found_posts,
			'items' => $items,
		);
	}

	public static function get_media( $input = null ): array|\WP_Error {
		$attachment_id = is_array( $input ) ? (int) ( $input['attachment_id'] ?? 0 ) : 0;
		$att           = \get_post( $attachment_id );
		if ( ! $att instanceof \WP_Post || 'attachment' !== $att->post_type ) {
			return new \WP_Error( 'mcp_abilities_not_found', __( 'Attachment not found.', 'mcp-abilities' ), array( 'attachment_id' => $attachment_id ) );
		}
		return self::attachment_to_summary( $att );
	}

	public static function upload_media_from_url( $input = null ): array|\WP_Error {
		$input = is_array( $input ) ? $input : array();
		$url   = isset( $input['url'] ) ? (string) $input['url'] : '';
		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			return new \WP_Error( 'mcp_abilities_invalid_input', __( 'A valid url is required.', 'mcp-abilities' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$tmp = download_url( $url );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}
		$file_array = array(
			'name'     => wp_basename( wp_parse_url( $url, PHP_URL_PATH ) ?: 'upload' ),
			'tmp_name' => $tmp,
		);
		$parent_id     = isset( $input['parent_id'] ) ? max( 0, (int) $input['parent_id'] ) : 0;
		$attachment_id = media_handle_sideload( $file_array, $parent_id, isset( $input['title'] ) ? (string) $input['title'] : null );
		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $tmp );
			return $attachment_id;
		}
		if ( isset( $input['alt_text'] ) ) {
			update_post_meta( (int) $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( (string) $input['alt_text'] ) );
		}
		if ( isset( $input['caption'] ) ) {
			wp_update_post( array(
				'ID'           => (int) $attachment_id,
				'post_excerpt' => (string) $input['caption'],
			) );
		}
		$att = \get_post( (int) $attachment_id );
		if ( ! $att instanceof \WP_Post ) {
			return new \WP_Error( 'mcp_abilities_not_found', __( 'Attachment could not be read after upload.', 'mcp-abilities' ) );
		}
		return self::attachment_to_summary( $att );
	}

	public static function set_featured_image( $input = null ): array|\WP_Error {
		$input         = is_array( $input ) ? $input : array();
		$post_id       = (int) ( $input['post_id'] ?? 0 );
		$attachment_id = (int) ( $input['attachment_id'] ?? 0 );
		if ( $post_id <= 0 ) {
			return new \WP_Error( 'mcp_abilities_invalid_input', __( 'post_id is required.', 'mcp-abilities' ) );
		}
		$post = \get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error( 'mcp_abilities_not_found', __( 'Post not found.', 'mcp-abilities' ), array( 'post_id' => $post_id ) );
		}
		if ( 0 === $attachment_id ) {
			$success = (bool) delete_post_thumbnail( $post_id );
		} else {
			$att = \get_post( $attachment_id );
			if ( ! $att instanceof \WP_Post || 'attachment' !== $att->post_type ) {
				return new \WP_Error( 'mcp_abilities_not_found', __( 'Attachment not found.', 'mcp-abilities' ), array( 'attachment_id' => $attachment_id ) );
			}
			$success = (bool) set_post_thumbnail( $post_id, $attachment_id );
		}
		return array(
			'post_id'       => $post_id,
			'attachment_id' => $attachment_id,
			'success'       => $success,
		);
	}

	public static function delete_media( $input = null ): array|\WP_Error {
		$input         = is_array( $input ) ? $input : array();
		$attachment_id = (int) ( $input['attachment_id'] ?? 0 );
		$force         = array_key_exists( 'force', $input ) ? (bool) $input['force'] : true;
		if ( $attachment_id <= 0 ) {
			return new \WP_Error( 'mcp_abilities_invalid_input', __( 'attachment_id is required.', 'mcp-abilities' ) );
		}
		$att = \get_post( $attachment_id );
		if ( ! $att instanceof \WP_Post || 'attachment' !== $att->post_type ) {
			return new \WP_Error( 'mcp_abilities_not_found', __( 'Attachment not found.', 'mcp-abilities' ), array( 'attachment_id' => $attachment_id ) );
		}
		$result = wp_delete_attachment( $attachment_id, $force );
		if ( false === $result || null === $result ) {
			return new \WP_Error( 'mcp_abilities_delete_failed', __( 'Attachment could not be deleted.', 'mcp-abilities' ) );
		}
		return array(
			'attachment_id' => $attachment_id,
			'deleted'       => true,
		);
	}

	private static function attachment_to_summary( \WP_Post $att ): array {
		$file     = get_attached_file( $att->ID ) ?: '';
		$filesize = ( '' !== $file && file_exists( $file ) ) ? (int) filesize( $file ) : 0;
		$meta     = wp_get_attachment_metadata( $att->ID );
		$width    = isset( $meta['width'] ) ? (int) $meta['width'] : 0;
		$height   = isset( $meta['height'] ) ? (int) $meta['height'] : 0;
		return array(
			'id'         => (int) $att->ID,
			'title'      => (string) $att->post_title,
			'slug'       => (string) $att->post_name,
			'mime_type'  => (string) $att->post_mime_type,
			'url'        => (string) wp_get_attachment_url( $att->ID ),
			'alt_text'   => (string) get_post_meta( $att->ID, '_wp_attachment_image_alt', true ),
			'caption'    => (string) $att->post_excerpt,
			'parent_id'  => (int) $att->post_parent,
			'width'      => $width,
			'height'     => $height,
			'filesize'   => $filesize,
			'date'       => mysql2date( 'c', $att->post_date, false ),
			'edit_link'  => (string) get_edit_post_link( $att, 'url' ),
		);
	}

	/* =====================================================================
	 * Post meta
	 * ================================================================== */

	public static function get_post_meta( $input = null ): array|\WP_Error {
		$input   = is_array( $input ) ? $input : array();
		$post_id = (int) ( $input['post_id'] ?? 0 );
		$key     = isset( $input['key'] ) ? (string) $input['key'] : '';
		if ( $post_id <= 0 ) {
			return new \WP_Error( 'mcp_abilities_invalid_input', __( 'post_id is required.', 'mcp-abilities' ) );
		}
		if ( ! \get_post( $post_id ) instanceof \WP_Post ) {
			return new \WP_Error( 'mcp_abilities_not_found', __( 'Post not found.', 'mcp-abilities' ), array( 'post_id' => $post_id ) );
		}
		$all = (array) get_post_meta( $post_id );
		$out = array();
		foreach ( $all as $meta_key => $values ) {
			if ( '' !== $key && $key !== $meta_key ) {
				continue;
			}
			if ( ! self::meta_key_allowed( $meta_key, 'post' ) ) {
				continue;
			}
			$decoded = array_map( 'maybe_unserialize', (array) $values );
			$out[ $meta_key ] = ( count( $decoded ) === 1 ) ? $decoded[0] : $decoded;
		}
		return $out;
	}

	public static function set_post_meta( $input = null ): array|\WP_Error {
		$input   = is_array( $input ) ? $input : array();
		$post_id = (int) ( $input['post_id'] ?? 0 );
		$key     = isset( $input['key'] ) ? (string) $input['key'] : '';
		if ( $post_id <= 0 || '' === $key ) {
			return new \WP_Error( 'mcp_abilities_invalid_input', __( 'post_id and key are required.', 'mcp-abilities' ) );
		}
		if ( ! \get_post( $post_id ) instanceof \WP_Post ) {
			return new \WP_Error( 'mcp_abilities_not_found', __( 'Post not found.', 'mcp-abilities' ), array( 'post_id' => $post_id ) );
		}
		if ( ! self::meta_key_allowed( $key, 'post' ) ) {
			return new \WP_Error( 'mcp_abilities_meta_blocked', __( 'Meta key is protected or not allowlisted.', 'mcp-abilities' ), array( 'key' => $key ) );
		}
		$value   = $input['value'] ?? null;
		$success = (bool) update_post_meta( $post_id, $key, $value );
		return array(
			'post_id' => $post_id,
			'key'     => $key,
			'success' => $success,
		);
	}

	private static function meta_key_allowed( string $key, string $object_type ): bool {
		// Always reject hard-protected keys regardless of allowlist.
		$blocked = array( '_edit_lock', '_edit_last' );
		if ( in_array( $key, $blocked, true ) ) {
			return false;
		}
		/**
		 * Allowlist of meta keys exposable via the get-/set-post-meta abilities.
		 *
		 * Return null (default) to use the safe default: any non-protected key
		 * (i.e. not starting with "_") is allowed. Return an array to restrict
		 * to exactly that set of keys (overrides the default).
		 *
		 * @param array|null $allowlist   Explicit list of allowed keys, or null for default policy.
		 * @param string     $object_type Currently only "post".
		 */
		$allowlist = apply_filters( 'mcp_abilities_meta_allowlist', null, $object_type );
		if ( is_array( $allowlist ) ) {
			return in_array( $key, $allowlist, true );
		}
		return ! is_protected_meta( $key, $object_type );
	}

	/* =====================================================================
	 * Discovery
	 * ================================================================== */

	public static function list_post_types( $input = null ): array|\WP_Error {
		$types = get_post_types( array( 'public' => true ), 'objects' );
		$items = array();
		foreach ( $types as $type ) {
			$items[] = array(
				'name'         => (string) $type->name,
				'label'        => (string) $type->label,
				'description'  => (string) $type->description,
				'public'       => (bool) $type->public,
				'hierarchical' => (bool) $type->hierarchical,
				'rest_base'    => (string) ( $type->rest_base ?: $type->name ),
				'taxonomies'   => array_values( get_object_taxonomies( $type->name ) ),
			);
		}
		return $items;
	}

	public static function get_current_user( $input = null ): array|\WP_Error {
		$user = wp_get_current_user();
		if ( ! $user instanceof \WP_User || 0 === $user->ID ) {
			return new \WP_Error( 'mcp_abilities_unauthenticated', __( 'No authenticated user.', 'mcp-abilities' ) );
		}
		$caps_to_report = array(
			'edit_posts',
			'publish_posts',
			'edit_pages',
			'publish_pages',
			'delete_pages',
			'manage_categories',
			'upload_files',
			'manage_options',
		);
		$caps = array();
		foreach ( $caps_to_report as $cap ) {
			$caps[ $cap ] = user_can( $user, $cap );
		}
		return array(
			'id'           => (int) $user->ID,
			'login'        => (string) $user->user_login,
			'display_name' => (string) $user->display_name,
			'email'        => (string) $user->user_email,
			'roles'        => array_values( (array) $user->roles ),
			'capabilities' => $caps,
		);
	}
}
