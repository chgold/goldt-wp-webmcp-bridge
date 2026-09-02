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

		$this->register_tool(
			'listCategories',
			array(
				'description'    => 'List post categories. Use search (matches name OR slug, case-insensitive substring) to resolve a category id from its name/slug — the common pre-step for createPost.',
				'required_scope' => 'read',
				'input_schema'   => array(
					'type'       => 'object',
					'properties' => array(
						'search'  => array(
							'type'        => 'string',
							'description' => 'Optional — filter categories whose name OR slug contains this string (case-insensitive). Omit for all.',
						),
						'parent'  => array(
							'type'        => 'integer',
							'description' => 'Optional — only return direct children of this parent category id.',
						),
						'orderby' => array(
							'type'        => 'string',
							'enum'        => array( 'name', 'slug', 'count', 'id' ),
							'default'     => 'name',
							'description' => 'Sort field.',
						),
						'limit'   => array(
							'type'        => 'integer',
							'default'     => 100,
							'description' => 'Max categories to return (1-500).',
						),
					),
				),
			)
		);

		$this->register_tool(
			'listTags',
			array(
				'description'    => 'List post tags. Use search (matches name OR slug, case-insensitive substring) to resolve a tag id from its name/slug — the common pre-step for createPost.',
				'required_scope' => 'read',
				'input_schema'   => array(
					'type'       => 'object',
					'properties' => array(
						'search'  => array(
							'type'        => 'string',
							'description' => 'Optional — filter tags whose name OR slug contains this string (case-insensitive). Omit for all.',
						),
						'orderby' => array(
							'type'        => 'string',
							'enum'        => array( 'name', 'slug', 'count', 'id' ),
							'default'     => 'name',
							'description' => 'Sort field.',
						),
						'limit'   => array(
							'type'        => 'integer',
							'default'     => 100,
							'description' => 'Max tags to return (1-500).',
						),
					),
				),
			)
		);

		// Cheap read-only stats tool. Powers the declarative brief metric
		// `content.published.daily` (see register_brief_metrics below). Safe
		// to call on a schedule — one indexed COUNT(*) over wp_posts.
		$this->register_tool(
			'getContentStats',
			array(
				'description'    => 'Count published posts within a calendar day range. Accepts explicit local dates + IANA timezone so the collector can pin the window unambiguously (no local-time guessing). Counts post_type=post AND post_status=publish, with post_date_gmt inside the UTC bounds derived from the given local dates. Read-only.',
				'required_scope' => 'read',
				'input_schema'   => array(
					'type'       => 'object',
					'properties' => array(
						'from'     => array(
							'type'        => 'string',
							'description' => 'Local start date YYYY-MM-DD (inclusive). Default: today in site timezone.',
						),
						'to'       => array(
							'type'        => 'string',
							'description' => 'Local end date YYYY-MM-DD (inclusive). Default: same as from.',
						),
						'timezone' => array(
							'type'        => 'string',
							'description' => 'IANA timezone name (e.g. Asia/Jerusalem). Default: site timezone.',
						),
					),
				),
			)
		);

		// v1.2.6 additions — 15 planned tools per Deep Research v1 approve flow.

		$this->register_tool( 'listPosts', array(
			'description'    => 'List WordPress posts with filters (status, category, date, author, per_page). Cursor-agnostic — use offset for pagination.',
			'required_scope' => 'read',
			'input_schema'   => array(
				'type'       => 'object',
				'properties' => array(
					'status'     => array( 'type' => 'string', 'description' => 'publish | draft | pending | private | any' ),
					'category'   => array( 'type' => 'string', 'description' => 'Category slug' ),
					'author'     => array( 'type' => 'integer' ),
					'after'      => array( 'type' => 'string', 'description' => 'ISO date; posts after' ),
					'before'     => array( 'type' => 'string' ),
					'per_page'   => array( 'type' => 'integer', 'default' => 10 ),
					'offset'     => array( 'type' => 'integer', 'default' => 0 ),
					'render'     => $render_prop,
				),
			),
		) );

		$this->register_tool( 'listPages', array(
			'description'    => 'List WordPress pages with filters (status, parent hierarchy, date, per_page).',
			'required_scope' => 'read',
			'input_schema'   => array(
				'type'       => 'object',
				'properties' => array(
					'status'   => array( 'type' => 'string' ),
					'parent'   => array( 'type' => 'integer', 'description' => 'Parent page ID (0 = top-level)' ),
					'per_page' => array( 'type' => 'integer', 'default' => 10 ),
					'offset'   => array( 'type' => 'integer', 'default' => 0 ),
					'render'   => $render_prop,
				),
			),
		) );

		$this->register_tool( 'getCategories', array(
			'description'    => 'Fetch all category taxonomies with names, descriptions, post counts and hierarchy. Cheaper than listCategories when you need the full tree.',
			'required_scope' => 'read',
			'input_schema'   => array(
				'type'       => 'object',
				'properties' => array(
					'hide_empty' => array( 'type' => 'boolean', 'default' => false ),
					'parent'     => array( 'type' => 'integer' ),
				),
			),
		) );

		$this->register_tool( 'getTags', array(
			'description'    => 'Fetch all tag taxonomies with names, descriptions and post counts.',
			'required_scope' => 'read',
			'input_schema'   => array(
				'type'       => 'object',
				'properties' => array(
					'hide_empty' => array( 'type' => 'boolean', 'default' => false ),
				),
			),
		) );

		$this->register_tool( 'getMedia', array(
			'description'    => 'Get details of a media item (image, video, PDF) by ID: URL, title, description, alt text, size, MIME type, image sizes.',
			'required_scope' => 'read',
			'input_schema'   => array(
				'type'       => 'object',
				'required'   => array( 'id' ),
				'properties' => array( 'id' => array( 'type' => 'integer' ) ),
			),
		) );

		$this->register_tool( 'listMedia', array(
			'description'    => 'List media library items with filters (mime_type, upload date, author).',
			'required_scope' => 'read',
			'input_schema'   => array(
				'type'       => 'object',
				'properties' => array(
					'mime_type' => array( 'type' => 'string', 'description' => 'e.g. image/jpeg, application/pdf' ),
					'author'    => array( 'type' => 'integer' ),
					'per_page'  => array( 'type' => 'integer', 'default' => 20 ),
					'offset'    => array( 'type' => 'integer', 'default' => 0 ),
				),
			),
		) );

		$this->register_tool( 'searchMedia', array(
			'description'    => 'Search media items by name, title, description or MIME type. Returns files with URLs + sizes.',
			'required_scope' => 'read',
			'input_schema'   => array(
				'type'       => 'object',
				'required'   => array( 'search' ),
				'properties' => array(
					'search'    => array( 'type' => 'string' ),
					'mime_type' => array( 'type' => 'string' ),
					'per_page'  => array( 'type' => 'integer', 'default' => 20 ),
				),
			),
		) );

		$this->register_tool( 'getComments', array(
			'description'    => 'Fetch comments filtered by post, status, or author. Returns content, date, author, approval state.',
			'required_scope' => 'read',
			'input_schema'   => array(
				'type'       => 'object',
				'properties' => array(
					'post_id'  => array( 'type' => 'integer' ),
					'status'   => array( 'type' => 'string', 'description' => 'approve | hold | spam | trash | all' ),
					'author'   => array( 'type' => 'integer' ),
					'per_page' => array( 'type' => 'integer', 'default' => 20 ),
				),
			),
		) );

		$this->register_tool( 'listComments', array(
			'description'    => 'Alias for getComments — enumerate all comments across the site with filters.',
			'required_scope' => 'read',
			'input_schema'   => array(
				'type'       => 'object',
				'properties' => array(
					'post_id'  => array( 'type' => 'integer' ),
					'status'   => array( 'type' => 'string' ),
					'per_page' => array( 'type' => 'integer', 'default' => 20 ),
					'offset'   => array( 'type' => 'integer', 'default' => 0 ),
				),
			),
		) );

		$this->register_tool( 'getUsers', array(
			'description'    => 'List users by role or name search. Returns id/name/role/post_count only (no email — that requires admin scope).',
			'required_scope' => 'read',
			'input_schema'   => array(
				'type'       => 'object',
				'properties' => array(
					'role'     => array( 'type' => 'string', 'description' => 'administrator | editor | author | contributor | subscriber' ),
					'search'   => array( 'type' => 'string' ),
					'per_page' => array( 'type' => 'integer', 'default' => 20 ),
				),
			),
		) );

		$this->register_tool( 'getSiteInfo', array(
			'description'    => 'Get general site info: name, tagline, URL, admin URL, language, timezone, WordPress version, PHP version. Helps AI understand the site context.',
			'required_scope' => 'read',
			'input_schema'   => array( 'type' => 'object', 'properties' => new \stdClass() ),
		) );

		$this->register_tool( 'getSiteSettings', array(
			'description'    => 'Get safe subset of site settings: date format, time format, default category, comments enabled, comment moderation, permalink structure. Excludes sensitive settings (email, admin_email).',
			'required_scope' => 'read',
			'input_schema'   => array( 'type' => 'object', 'properties' => new \stdClass() ),
		) );

		$this->register_tool( 'getMenus', array(
			'description'    => 'Fetch all site nav menus with items (labels, URLs, parent hierarchy) and their theme locations.',
			'required_scope' => 'read',
			'input_schema'   => array( 'type' => 'object', 'properties' => new \stdClass() ),
		) );

		$this->register_tool( 'getPostTypes', array(
			'description'    => 'List all registered post types (built-in + custom): name, label, hierarchical, supports_thumbnail, taxonomies.',
			'required_scope' => 'read',
			'input_schema'   => array(
				'type'       => 'object',
				'properties' => array(
					'public_only' => array( 'type' => 'boolean', 'default' => true ),
				),
			),
		) );

		$this->register_tool( 'getTaxonomies', array(
			'description'    => 'List all registered taxonomies (built-in + custom): name, label, hierarchical, associated post types.',
			'required_scope' => 'read',
			'input_schema'   => array(
				'type'       => 'object',
				'properties' => array(
					'public_only' => array( 'type' => 'boolean', 'default' => true ),
				),
			),
		) );

		// Register declarative brief metrics for goldnat.ai. Constants only:
		// no local-time math (which would guess on the wrong clock — see the
		// UTC-vs-user-timezone trap in manifest-format.md). {{periodStart}},
		// {{periodEnd}} and {{timezone}} are substituted by the collector.
		$this->register_brief_metrics();
	}

	/**
	 * Register the declarative brief metrics exposed by this module.
	 *
	 * Kept separate so subclasses / Pro modules can override without touching
	 * the tool-registration flow above.
	 *
	 * @return void
	 */
	protected function register_brief_metrics() {
		if ( ! $this->manifest || ! method_exists( $this->manifest, 'register_brief_metric' ) ) {
			return;
		}

		$this->manifest->register_brief_metric(
			array(
				'key'         => 'content.published.daily',
				'tool'        => $this->module_name . '.getContentStats',
				'args'        => array(
					'from'     => '{{periodStart}}',
					'to'       => '{{periodEnd}}',
					'timezone' => '{{timezone}}',
				),
				'valuePath'   => 'data.published_count',
				'granularity' => 'day',
			)
		);
	}

	/**
	 * Execute the listCategories tool.
	 *
	 * @param array $params Tool parameters.
	 * @return array
	 */
	public function execute_listCategories( $params ) {
		return $this->list_taxonomy_terms( 'category', $params, true );
	}

	/**
	 * Execute the listTags tool.
	 *
	 * @param array $params Tool parameters.
	 * @return array
	 */
	public function execute_listTags( $params ) {
		return $this->list_taxonomy_terms( 'post_tag', $params, false );
	}

	/**
	 * Shared implementation for taxonomy-term list tools.
	 *
	 * @param string $taxonomy      WP taxonomy name (category | post_tag).
	 * @param array  $params        Tool parameters.
	 * @param bool   $supports_parent Whether to expose the parent filter.
	 * @return array
	 */
	private function list_taxonomy_terms( $taxonomy, $params, $supports_parent ) {
		$search = isset( $params['search'] ) ? sanitize_text_field( (string) $params['search'] ) : '';
		$limit  = isset( $params['limit'] ) ? absint( $params['limit'] ) : 100;
		if ( $limit < 1 ) {
			$limit = 100;
		}
		if ( $limit > 500 ) {
			$limit = 500;
		}
		$orderby       = isset( $params['orderby'] ) ? sanitize_key( (string) $params['orderby'] ) : 'name';
		$valid_orderby = array( 'name', 'slug', 'count', 'id' );
		if ( ! in_array( $orderby, $valid_orderby, true ) ) {
			$orderby = 'name';
		}

		$args = array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'orderby'    => $orderby,
			'order'      => 'ASC',
			'number'     => $limit,
		);
		if ( $supports_parent && isset( $params['parent'] ) ) {
			$args['parent'] = absint( $params['parent'] );
		}

		$terms = \get_terms( $args );
		if ( \is_wp_error( $terms ) ) {
			return array(
				'error'   => 'query_failed',
				'message' => $terms->get_error_message(),
			);
		}

		if ( '' !== $search ) {
			$needle = mb_strtolower( $search );
			$terms  = array_values(
				array_filter(
					$terms,
					function ( $term ) use ( $needle ) {
						return false !== mb_stripos( $term->name, $needle )
							|| false !== mb_stripos( $term->slug, $needle );
					}
				)
			);
		}

		$out = array();
		foreach ( $terms as $t ) {
			$row = array(
				'id'          => (int) $t->term_id,
				'name'        => $t->name,
				'slug'        => $t->slug,
				'count'       => (int) $t->count,
				'description' => $t->description,
			);
			if ( $supports_parent ) {
				$row['parent'] = (int) $t->parent;
			}
			$out[] = $row;
		}

		return array(
			'taxonomy' => $taxonomy,
			'count'    => count( $out ),
			'terms'    => $out,
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
	 * Execute the getContentStats tool.
	 *
	 * Returns the count of published posts whose `post_date_gmt` falls inside the
	 * UTC window derived from the caller-supplied LOCAL dates + IANA timezone.
	 * Everything else about "which day" is decided by the caller — this handler
	 * never invents "today" or "yesterday" of its own.
	 *
	 * Definition (documented in the returned payload for auditability):
	 *   - post_type  = 'post'    (custom post types are not counted)
	 *   - post_status = 'publish' (drafts / scheduled / private do NOT count)
	 *
	 * @param array $params Tool parameters.
	 * @return array|\WP_Error
	 */
	public function execute_getContentStats( $params ) {
		$default_tz = \wp_timezone_string();
		$tz_name    = isset( $params['timezone'] ) && '' !== $params['timezone']
			? (string) $params['timezone']
			: $default_tz;

		try {
			$tz = new \DateTimeZone( $tz_name );
		} catch ( \Exception $e ) {
			return $this->error_response( sprintf( 'Invalid timezone "%s"', $tz_name ), 'invalid_timezone' );
		}

		$today_local = ( new \DateTimeImmutable( 'now', $tz ) )->format( 'Y-m-d' );
		$from_local  = isset( $params['from'] ) && '' !== $params['from']
			? (string) $params['from']
			: $today_local;
		$to_local    = isset( $params['to'] ) && '' !== $params['to']
			? (string) $params['to']
			: $from_local;

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from_local )
			|| ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to_local ) ) {
			return $this->error_response( 'from/to must be YYYY-MM-DD', 'invalid_date' );
		}

		try {
			$start_local = new \DateTimeImmutable( $from_local . ' 00:00:00', $tz );
			$end_local   = new \DateTimeImmutable( $to_local . ' 23:59:59', $tz );
		} catch ( \Exception $e ) {
			return $this->error_response( 'Failed to parse date range', 'invalid_date' );
		}

		if ( $end_local < $start_local ) {
			return $this->error_response( '"to" must be >= "from"', 'invalid_range' );
		}

		$utc         = new \DateTimeZone( 'UTC' );
		$start_utc   = $start_local->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
		$end_utc     = $end_local->setTimezone( $utc )->format( 'Y-m-d H:i:s' );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Single indexed COUNT, cache would be stale-by-design for a scheduled metric.
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts}
				WHERE post_type = 'post'
				  AND post_status = 'publish'
				  AND post_date_gmt BETWEEN %s AND %s",
				$start_utc,
				$end_utc
			)
		);

		return $this->success_response(
			array(
				'from'             => $from_local,
				'to'               => $to_local,
				'timezone'         => $tz_name,
				'window_utc_start' => $start_utc,
				'window_utc_end'   => $end_utc,
				'published_count'  => $count,
				'included_status'  => 'publish',
				'included_types'   => array( 'post' ),
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

	// ─────────────────────────────────────────────────────────────────────
	// v1.2.6 tool executors — 15 planned tools from Deep Research v1
	// ─────────────────────────────────────────────────────────────────────

	public function execute_listPosts( $params ) {
		$args = array(
			'post_type'      => 'post',
			'post_status'    => $this->sanitize_status( $params['status'] ?? 'publish' ),
			'posts_per_page' => absint( $params['per_page'] ?? 10 ),
			'offset'         => absint( $params['offset'] ?? 0 ),
		);
		if ( isset( $params['category'] ) ) {
			$args['category_name'] = sanitize_title( (string) $params['category'] );
		}
		if ( isset( $params['author'] ) ) {
			$args['author'] = absint( $params['author'] );
		}
		if ( isset( $params['after'] ) || isset( $params['before'] ) ) {
			$args['date_query'] = array_filter( array(
				'after'  => isset( $params['after'] )  ? sanitize_text_field( (string) $params['after'] )  : null,
				'before' => isset( $params['before'] ) ? sanitize_text_field( (string) $params['before'] ) : null,
			) );
		}
		$render = $this->resolve_render( $params );
		$posts  = \get_posts( $args );
		return array(
			'success' => true,
			'data'    => array(
				'posts' => array_map( fn( $p ) => $this->format_post( $p, $render ), $posts ),
				'count' => count( $posts ),
			),
		);
	}

	public function execute_listPages( $params ) {
		$args = array(
			'post_type'      => 'page',
			'post_status'    => $this->sanitize_status( $params['status'] ?? 'publish' ),
			'posts_per_page' => absint( $params['per_page'] ?? 10 ),
			'offset'         => absint( $params['offset'] ?? 0 ),
		);
		if ( isset( $params['parent'] ) ) {
			$args['post_parent'] = absint( $params['parent'] );
		}
		$render = $this->resolve_render( $params );
		$pages  = \get_posts( $args );
		return array(
			'success' => true,
			'data'    => array(
				'pages' => array_map( fn( $p ) => $this->format_post( $p, $render ), $pages ),
				'count' => count( $pages ),
			),
		);
	}

	public function execute_getCategories( $params ) {
		$args = array(
			'taxonomy'   => 'category',
			'hide_empty' => (bool) ( $params['hide_empty'] ?? false ),
			'orderby'    => 'name',
			'order'      => 'ASC',
		);
		if ( isset( $params['parent'] ) ) {
			$args['parent'] = absint( $params['parent'] );
		}
		$terms = \get_terms( $args );
		if ( \is_wp_error( $terms ) ) {
			return array( 'success' => false, 'error' => $terms->get_error_message() );
		}
		return array(
			'success' => true,
			'data'    => array_map( fn( $t ) => array(
				'id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug,
				'description' => $t->description, 'count' => $t->count, 'parent' => $t->parent,
			), $terms ),
		);
	}

	public function execute_getTags( $params ) {
		$terms = \get_terms( array(
			'taxonomy'   => 'post_tag',
			'hide_empty' => (bool) ( $params['hide_empty'] ?? false ),
			'orderby'    => 'name',
		) );
		if ( \is_wp_error( $terms ) ) {
			return array( 'success' => false, 'error' => $terms->get_error_message() );
		}
		return array(
			'success' => true,
			'data'    => array_map( fn( $t ) => array(
				'id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug,
				'description' => $t->description, 'count' => $t->count,
			), $terms ),
		);
	}

	public function execute_getMedia( $params ) {
		$id = absint( $params['id'] ?? 0 );
		if ( ! $id ) {
			return array( 'success' => false, 'error' => 'id required' );
		}
		$post = \get_post( $id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return array( 'success' => false, 'error' => 'media not found' );
		}
		return array( 'success' => true, 'data' => $this->format_media( $post ) );
	}

	public function execute_listMedia( $params ) {
		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => absint( $params['per_page'] ?? 20 ),
			'offset'         => absint( $params['offset'] ?? 0 ),
		);
		if ( isset( $params['mime_type'] ) ) {
			$args['post_mime_type'] = sanitize_text_field( (string) $params['mime_type'] );
		}
		if ( isset( $params['author'] ) ) {
			$args['author'] = absint( $params['author'] );
		}
		$items = \get_posts( $args );
		return array(
			'success' => true,
			'data'    => array(
				'media' => array_map( fn( $p ) => $this->format_media( $p ), $items ),
				'count' => count( $items ),
			),
		);
	}

	public function execute_searchMedia( $params ) {
		$search = sanitize_text_field( (string) ( $params['search'] ?? '' ) );
		if ( '' === $search ) {
			return array( 'success' => false, 'error' => 'search required' );
		}
		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			's'              => $search,
			'posts_per_page' => absint( $params['per_page'] ?? 20 ),
		);
		if ( isset( $params['mime_type'] ) ) {
			$args['post_mime_type'] = sanitize_text_field( (string) $params['mime_type'] );
		}
		$items = \get_posts( $args );
		return array(
			'success' => true,
			'data'    => array(
				'media' => array_map( fn( $p ) => $this->format_media( $p ), $items ),
				'count' => count( $items ),
			),
		);
	}

	public function execute_getComments( $params ) {
		return $this->do_list_comments( $params );
	}

	public function execute_listComments( $params ) {
		return $this->do_list_comments( $params );
	}

	private function do_list_comments( $params ) {
		$args = array(
			'number' => absint( $params['per_page'] ?? 20 ),
			'offset' => absint( $params['offset'] ?? 0 ),
		);
		if ( isset( $params['post_id'] ) ) {
			$args['post_id'] = absint( $params['post_id'] );
		}
		if ( isset( $params['status'] ) ) {
			$s = sanitize_key( (string) $params['status'] );
			if ( in_array( $s, array( 'approve', 'hold', 'spam', 'trash', 'all' ), true ) ) {
				$args['status'] = $s;
			}
		}
		if ( isset( $params['author'] ) ) {
			$args['user_id'] = absint( $params['author'] );
		}
		$comments = \get_comments( $args );
		return array(
			'success' => true,
			'data'    => array(
				'comments' => array_map( fn( $c ) => array(
					'id'         => (int) $c->comment_ID,
					'post_id'    => (int) $c->comment_post_ID,
					'author'     => $c->comment_author,
					'author_url' => $c->comment_author_url,
					'content'    => $c->comment_content,
					'date'       => $c->comment_date,
					'approved'   => $c->comment_approved,
					'parent'     => (int) $c->comment_parent,
				), $comments ),
				'count' => count( $comments ),
			),
		);
	}

	public function execute_getUsers( $params ) {
		$args = array(
			'number' => absint( $params['per_page'] ?? 20 ),
			'fields' => array( 'ID', 'display_name', 'user_nicename', 'user_registered' ),
		);
		if ( isset( $params['role'] ) ) {
			$args['role'] = sanitize_key( (string) $params['role'] );
		}
		if ( isset( $params['search'] ) ) {
			$args['search'] = '*' . sanitize_text_field( (string) $params['search'] ) . '*';
		}
		$users = \get_users( $args );
		return array(
			'success' => true,
			'data'    => array(
				'users' => array_map( fn( $u ) => array(
					'id'         => (int) $u->ID,
					'name'       => $u->display_name,
					'slug'       => $u->user_nicename,
					'registered' => $u->user_registered,
					'post_count' => (int) \count_user_posts( $u->ID, 'post', true ),
				), $users ),
				'count' => count( $users ),
			),
		);
	}

	public function execute_getSiteInfo( $params ) {
		return array(
			'success' => true,
			'data'    => array(
				'name'        => \get_bloginfo( 'name' ),
				'tagline'     => \get_bloginfo( 'description' ),
				'url'         => \home_url(),
				'admin_url'   => \admin_url(),
				'language'    => \get_bloginfo( 'language' ),
				'timezone'    => \wp_timezone_string(),
				'wp_version'  => \get_bloginfo( 'version' ),
				'php_version' => PHP_VERSION,
			),
		);
	}

	public function execute_getSiteSettings( $params ) {
		return array(
			'success' => true,
			'data'    => array(
				'date_format'         => \get_option( 'date_format' ),
				'time_format'         => \get_option( 'time_format' ),
				'start_of_week'       => (int) \get_option( 'start_of_week' ),
				'default_category'    => (int) \get_option( 'default_category' ),
				'default_post_format' => \get_option( 'default_post_format' ),
				'comments_open'       => (bool) \get_option( 'default_comment_status' ),
				'comment_moderation'  => (bool) \get_option( 'comment_moderation' ),
				'permalink_structure' => \get_option( 'permalink_structure' ),
				'posts_per_page'      => (int) \get_option( 'posts_per_page' ),
			),
		);
	}

	public function execute_getMenus( $params ) {
		$menus     = \wp_get_nav_menus();
		$locations = \get_nav_menu_locations();
		$out       = array();
		foreach ( $menus as $menu ) {
			$items       = \wp_get_nav_menu_items( $menu->term_id ) ?: array();
			$locs        = array_keys( array_filter( $locations, fn( $mid ) => (int) $mid === (int) $menu->term_id ) );
			$out[]       = array(
				'id'        => (int) $menu->term_id,
				'name'      => $menu->name,
				'slug'      => $menu->slug,
				'locations' => $locs,
				'items'     => array_map( fn( $i ) => array(
					'id'     => (int) $i->ID,
					'label'  => $i->title,
					'url'    => $i->url,
					'parent' => (int) $i->menu_item_parent,
					'order'  => (int) $i->menu_order,
				), $items ),
			);
		}
		return array( 'success' => true, 'data' => $out );
	}

	public function execute_getPostTypes( $params ) {
		$public_only = (bool) ( $params['public_only'] ?? true );
		$args        = $public_only ? array( 'public' => true ) : array();
		$types       = \get_post_types( $args, 'objects' );
		return array(
			'success' => true,
			'data'    => array_map( fn( $t ) => array(
				'name'           => $t->name,
				'label'          => $t->label,
				'public'         => (bool) $t->public,
				'hierarchical'   => (bool) $t->hierarchical,
				'has_archive'    => (bool) $t->has_archive,
				'supports'       => \get_all_post_type_supports( $t->name ),
				'taxonomies'     => \get_object_taxonomies( $t->name ),
			), array_values( $types ) ),
		);
	}

	public function execute_getTaxonomies( $params ) {
		$public_only = (bool) ( $params['public_only'] ?? true );
		$args        = $public_only ? array( 'public' => true ) : array();
		$taxes       = \get_taxonomies( $args, 'objects' );
		return array(
			'success' => true,
			'data'    => array_map( fn( $t ) => array(
				'name'         => $t->name,
				'label'        => $t->label,
				'public'       => (bool) $t->public,
				'hierarchical' => (bool) $t->hierarchical,
				'object_type'  => $t->object_type,
			), array_values( $taxes ) ),
		);
	}

	private function sanitize_status( $status ) {
		$s = sanitize_key( (string) $status );
		return in_array( $s, array( 'publish', 'draft', 'pending', 'private', 'future', 'any' ), true ) ? $s : 'publish';
	}

	private function format_media( $post ) {
		$meta = \wp_get_attachment_metadata( $post->ID ) ?: array();
		return array(
			'id'         => (int) $post->ID,
			'title'      => \get_the_title( $post ),
			'alt'        => \get_post_meta( $post->ID, '_wp_attachment_image_alt', true ),
			'caption'    => $post->post_excerpt,
			'description'=> $post->post_content,
			'url'        => \wp_get_attachment_url( $post->ID ),
			'mime_type'  => $post->post_mime_type,
			'date'       => $post->post_date,
			'author_id'  => (int) $post->post_author,
			'width'      => $meta['width']  ?? null,
			'height'     => $meta['height'] ?? null,
			'sizes'      => isset( $meta['sizes'] ) ? array_keys( $meta['sizes'] ) : array(),
		);
	}
}
