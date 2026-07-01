<?php
/**
 * Core module providing WordPress content tools.
 *
 * @package GoldtWebMCP
 */

namespace GoldtWebMCP\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core module for WordPress posts, pages, and user tools.
 *
 * @package GoldtWebMCP
 */
class Core_Module extends Module_Base {

	/**
	 * Module name identifier.
	 *
	 * @var string
	 */
	protected $module_name = 'wordpress';

	/**
	 * Register all core WordPress tools.
	 *
	 * @return void
	 */
	protected function register_tools() {
		$render_prop = array(
			'type'        => 'string',
			'enum'        => array( 'raw', 'full', 'excerpt' ),
			'default'     => 'raw',
			'description' => 'How to return the post content. "raw" (default, safe) returns the stored post_content verbatim so dynamic blocks (WooCommerce, page-builder widgets, shortcodes) are NOT executed and cannot leak product/user/order data. "full" runs the_content filter and executes blocks/shortcodes — use only when you explicitly need the rendered HTML. "excerpt" returns just the excerpt.',
		);

		$this->register_tool(
			'searchPosts',
			array(
				'description'    => 'Search WordPress posts with filters. Returns raw post_content by default; use render=full only when you specifically need blocks and shortcodes executed.',
				'required_scope' => 'read',
				'input_schema'   => array(
					'type'       => 'object',
					'properties' => array(
						'search'   => array(
							'type'        => 'string',
							'description' => 'Search query',
						),
						'category' => array(
							'type'        => 'string',
							'description' => 'Category slug to filter by',
						),
						'tag'      => array(
							'type'        => 'string',
							'description' => 'Tag slug to filter by',
						),
						'author'   => array(
							'type'        => 'integer',
							'description' => 'Author ID to filter by',
						),
						'limit'    => array(
							'type'        => 'integer',
							'description' => 'Maximum number of posts to return',
							'default'     => 10,
						),
						'offset'   => array(
							'type'        => 'integer',
							'description' => 'Number of posts to skip',
							'default'     => 0,
						),
						'render'   => $render_prop,
					),
				),
			)
		);

		$this->register_tool(
			'getPost',
			array(
				'description'    => 'Get a single WordPress post by ID or slug. Returns raw post_content by default; use render=full only when you specifically need blocks and shortcodes executed.',
				'required_scope' => 'read',
				'input_schema'   => array(
					'type'       => 'object',
					'required'   => array( 'identifier' ),
					'properties' => array(
						'identifier' => array(
							'type'        => array( 'integer', 'string' ),
							'description' => 'Post ID or slug',
						),
						'render'     => $render_prop,
					),
				),
			)
		);

		$this->register_tool(
			'searchPages',
			array(
				'description'    => 'Search WordPress pages. Returns raw post_content by default; use render=full only when you specifically need blocks and shortcodes executed.',
				'required_scope' => 'read',
				'input_schema'   => array(
					'type'       => 'object',
					'properties' => array(
						'search' => array(
							'type'        => 'string',
							'description' => 'Search query',
						),
						'parent' => array(
							'type'        => 'integer',
							'description' => 'Parent page ID',
						),
						'limit'  => array(
							'type'        => 'integer',
							'description' => 'Maximum number of pages',
							'default'     => 10,
						),
						'render' => $render_prop,
					),
				),
			)
		);

		$this->register_tool(
			'getPage',
			array(
				'description'    => 'Get a single WordPress page by ID or slug. Returns raw post_content by default; use render=full only when you specifically need blocks and shortcodes executed.',
				'required_scope' => 'read',
				'input_schema'   => array(
					'type'       => 'object',
					'required'   => array( 'identifier' ),
					'properties' => array(
						'identifier' => array(
							'type'        => array( 'integer', 'string' ),
							'description' => 'Page ID or slug',
						),
						'render'     => $render_prop,
					),
				),
			)
		);

		$this->register_tool(
			'getCurrentUser',
			array(
				'description'    => 'Get information about the current authenticated user',
				'required_scope' => 'read',
				'input_schema'   => array(
					'type'       => 'object',
					'properties' => new \stdClass(),
				),
			)
		);
	}

	/**
	 * Execute the searchPosts tool.
	 *
	 * @param array $params Tool parameters.
	 * @return array|\WP_Error
	 */
	public function execute_searchPosts( $params ) {
		// Validate and sanitize limit parameter.
		$limit = isset( $params['limit'] ) ? absint( $params['limit'] ) : 10;
		if ( $limit < 1 ) {
			$limit = 10;
		}
		if ( $limit > 100 ) {
			$limit = 100; // Cap at 100 to prevent resource exhaustion.
		}

		// Validate and sanitize offset parameter.
		$offset = isset( $params['offset'] ) ? absint( $params['offset'] ) : 0;

		$args = array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'offset'         => $offset,
		);

		if ( isset( $params['search'] ) && ! empty( $params['search'] ) ) {
			$args['s'] = sanitize_text_field( $params['search'] );
		}

		if ( isset( $params['category'] ) ) {
			$args['category_name'] = sanitize_text_field( $params['category'] );
		}

		if ( isset( $params['tag'] ) ) {
			$args['tag'] = sanitize_text_field( $params['tag'] );
		}

		if ( isset( $params['author'] ) ) {
			$args['author'] = absint( $params['author'] );
		}

		$query = new \WP_Query( $args );

		if ( ! $query->have_posts() ) {
			return $this->success_response( array(), 'No posts found' );
		}

		$render = $this->resolve_render( $params );

		$posts = array();
		while ( $query->have_posts() ) {
			$query->the_post();
			$posts[] = $this->format_post( \get_post(), $render );
		}
		\wp_reset_postdata();

		return $this->success_response( $posts, sprintf( 'Found %d posts', count( $posts ) ) );
	}

	/**
	 * Execute the getPost tool.
	 *
	 * @param array $params Tool parameters.
	 * @return array|\WP_Error
	 */
	public function execute_getPost( $params ) {
		// Validate required parameter.
		if ( ! isset( $params['identifier'] ) ) {
			return $this->error_response( 'Missing required parameter: identifier', 'missing_parameter' );
		}

		$identifier = $params['identifier'];

		// Reject empty identifiers.
		if ( '' === $identifier || null === $identifier ) {
			return $this->error_response( 'Parameter "identifier" cannot be empty', 'invalid_parameter' );
		}

		if ( is_numeric( $identifier ) ) {
			$post_id = (int) $identifier;
			if ( $post_id <= 0 ) {
				return new \WP_Error( 'post_not_found', 'Post not found', array( 'status' => 404 ) );
			}
			$post = \get_post( $post_id );
		} else {
			$post = \get_page_by_path( sanitize_title( $identifier ), OBJECT, 'post' );
		}

		if ( ! $post || 'post' !== $post->post_type ) {
			return new \WP_Error( 'post_not_found', 'Post not found', array( 'status' => 404 ) );
		}

		// Enforce per-post visibility: non-published posts require explicit read capability.
		if ( 'publish' !== $post->post_status && ! \current_user_can( 'read_post', $post->ID ) ) {
			return new \WP_Error( 'post_not_found', 'Post not found', array( 'status' => 404 ) );
		}

		return $this->success_response( $this->format_post( $post, $this->resolve_render( $params ) ) );
	}

	/**
	 * Execute the searchPages tool.
	 *
	 * @param array $params Tool parameters.
	 * @return array|\WP_Error
	 */
	public function execute_searchPages( $params ) {
		// Validate and sanitize limit parameter.
		$limit = isset( $params['limit'] ) ? absint( $params['limit'] ) : 10;
		if ( $limit < 1 ) {
			$limit = 10;
		}
		if ( $limit > 100 ) {
			$limit = 100; // Cap at 100 to prevent resource exhaustion.
		}

		$args = array(
			'post_type'      => 'page',
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
		);

		if ( isset( $params['search'] ) && ! empty( $params['search'] ) ) {
			$args['s'] = sanitize_text_field( $params['search'] );
		}

		if ( isset( $params['parent'] ) ) {
			$args['post_parent'] = absint( $params['parent'] );
		}

		$query = new \WP_Query( $args );

		if ( ! $query->have_posts() ) {
			return $this->success_response( array(), 'No pages found' );
		}

		$render = $this->resolve_render( $params );

		$pages = array();
		while ( $query->have_posts() ) {
			$query->the_post();
			$pages[] = $this->format_post( \get_post(), $render );
		}
		\wp_reset_postdata();

		return $this->success_response( $pages, sprintf( 'Found %d pages', count( $pages ) ) );
	}

	/**
	 * Execute the getPage tool.
	 *
	 * @param array $params Tool parameters.
	 * @return array|\WP_Error
	 */
	public function execute_getPage( $params ) {
		// Validate required parameter.
		if ( ! isset( $params['identifier'] ) ) {
			return $this->error_response( 'Missing required parameter: identifier', 'missing_parameter' );
		}

		$identifier = $params['identifier'];

		// Reject empty identifiers.
		if ( '' === $identifier || null === $identifier ) {
			return $this->error_response( 'Parameter "identifier" cannot be empty', 'invalid_parameter' );
		}

		if ( is_numeric( $identifier ) ) {
			$page_id = (int) $identifier;
			if ( $page_id <= 0 ) {
				return new \WP_Error( 'page_not_found', 'Page not found', array( 'status' => 404 ) );
			}
			$page = \get_post( $page_id );
		} else {
			$page = \get_page_by_path( sanitize_title( $identifier ), OBJECT, 'page' );
		}

		if ( ! $page || 'page' !== $page->post_type ) {
			return new \WP_Error( 'page_not_found', 'Page not found', array( 'status' => 404 ) );
		}

		// Enforce per-page visibility: non-published pages require explicit read capability.
		if ( 'publish' !== $page->post_status && ! \current_user_can( 'read_post', $page->ID ) ) {
			return new \WP_Error( 'page_not_found', 'Page not found', array( 'status' => 404 ) );
		}

		return $this->success_response( $this->format_post( $page, $this->resolve_render( $params ) ) );
	}

	/**
	 * Execute the getCurrentUser tool.
	 *
	 * @param array $params Tool parameters (unused).
	 * @return array|\WP_Error
	 */
	public function execute_getCurrentUser( $params ) {
		$current_user = \wp_get_current_user();

		if ( ! $current_user || 0 === $current_user->ID ) {
			return $this->error_response( 'No authenticated user', 'no_user' );
		}

		return $this->success_response(
			array(
				'id'           => $current_user->ID,
				'username'     => $current_user->user_login,
				'email'        => $current_user->user_email,
				'display_name' => $current_user->display_name,
				'roles'        => $current_user->roles,
				'capabilities' => array_keys( $current_user->allcaps ),
			)
		);
	}

	/**
	 * Format a post object into a structured array.
	 *
	 * The 'render' argument controls how the post content is returned:
	 *   - 'raw'  (default): the raw `post_content` string, WITHOUT running
	 *     the `the_content` filter. This means dynamic blocks (WooCommerce
	 *     cart, product grids, page-builder widgets, …) stay as their
	 *     block-comment markers instead of being executed. This is the
	 *     correct behaviour for an AI-agent tool: it exposes what the page
	 *     *is*, not what a shopper visiting the page would see, and prevents
	 *     leaking product/user/order data through page/post tools.
	 *   - 'full': the fully rendered HTML (block markup executed, shortcodes
	 *     expanded). Only for consumers that explicitly want the visible
	 *     output.
	 *   - 'excerpt': only the post excerpt (auto-generated if not set).
	 *
	 * @param \WP_Post $post   Post object.
	 * @param string   $render One of 'raw' | 'full' | 'excerpt'. Defaults to 'raw'.
	 * @return array
	 */
	private function format_post( $post, $render = 'raw' ) {
		if ( 'full' === $render ) {
			\setup_postdata( $post );
			$content = \get_the_content( null, false, $post );
			$content = \apply_filters( 'the_content', $content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			\wp_reset_postdata();
		} elseif ( 'excerpt' === $render ) {
			$content = \get_the_excerpt( $post );
		} else {
			// 'raw' (default): return post_content verbatim, no filters, no rendering.
			$content = $post->post_content;
		}

		return array(
			'id'             => $post->ID,
			'title'          => \get_the_title( $post ),
			'content'        => $content,
			'render'         => $render,
			'excerpt'        => \get_the_excerpt( $post ),
			'slug'           => $post->post_name,
			'status'         => $post->post_status,
			'type'           => $post->post_type,
			'author'         => array(
				'id'   => $post->post_author,
				'name' => \get_the_author_meta( 'display_name', $post->post_author ),
			),
			'date'           => $post->post_date,
			'modified'       => $post->post_modified,
			'permalink'      => \get_permalink( $post ),
			'featured_image' => \get_the_post_thumbnail_url( $post, 'large' ),
			'categories'     => \wp_get_post_categories( $post->ID, array( 'fields' => 'names' ) ),
			'tags'           => \wp_get_post_tags( $post->ID, array( 'fields' => 'names' ) ),
		);
	}

	/**
	 * Normalize the caller-supplied render argument to a supported value.
	 *
	 * @param array $params Tool parameters.
	 * @return string One of 'raw' | 'full' | 'excerpt'.
	 */
	private function resolve_render( $params ) {
		$render = isset( $params['render'] ) ? sanitize_key( (string) $params['render'] ) : 'raw';
		if ( ! in_array( $render, array( 'raw', 'full', 'excerpt' ), true ) ) {
			$render = 'raw';
		}
		return $render;
	}
}
