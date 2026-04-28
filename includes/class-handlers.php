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
}
