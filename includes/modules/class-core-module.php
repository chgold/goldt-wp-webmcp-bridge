<?php
namespace GoldtWebMCP\Modules;

if (!defined('ABSPATH')) {
    exit;
}

class Core_Module extends Module_Base {
    
    protected $module_name = 'wordpress';
    
    protected function register_tools() {
        $this->register_tool('searchPosts', [
            'description' => 'Search WordPress posts with filters',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'search' => [
                        'type' => 'string',
                        'description' => 'Search query',
                    ],
                    'category' => [
                        'type' => 'string',
                        'description' => 'Category slug to filter by',
                    ],
                    'tag' => [
                        'type' => 'string',
                        'description' => 'Tag slug to filter by',
                    ],
                    'author' => [
                        'type' => 'integer',
                        'description' => 'Author ID to filter by',
                    ],
                    'status' => [
                        'type' => 'string',
                        'description' => 'Post status (publish, draft, etc)',
                        'default' => 'publish',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Maximum number of posts to return',
                        'default' => 10,
                    ],
                    'offset' => [
                        'type' => 'integer',
                        'description' => 'Number of posts to skip',
                        'default' => 0,
                    ],
                ],
            ],
        ]);
        
        $this->register_tool('getPost', [
            'description' => 'Get a single WordPress post by ID or slug',
            'input_schema' => [
                'type' => 'object',
                'required' => ['identifier'],
                'properties' => [
                    'identifier' => [
                        'type' => ['integer', 'string'],
                        'description' => 'Post ID or slug',
                    ],
                ],
            ],
        ]);
        
        $this->register_tool('searchPages', [
            'description' => 'Search WordPress pages',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'search' => [
                        'type' => 'string',
                        'description' => 'Search query',
                    ],
                    'parent' => [
                        'type' => 'integer',
                        'description' => 'Parent page ID',
                    ],
                    'status' => [
                        'type' => 'string',
                        'description' => 'Page status',
                        'default' => 'publish',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Maximum number of pages',
                        'default' => 10,
                    ],
                ],
            ],
        ]);
        
        $this->register_tool('getPage', [
            'description' => 'Get a single WordPress page by ID or slug',
            'input_schema' => [
                'type' => 'object',
                'required' => ['identifier'],
                'properties' => [
                    'identifier' => [
                        'type' => ['integer', 'string'],
                        'description' => 'Page ID or slug',
                    ],
                ],
            ],
        ]);
        
        $this->register_tool('getCurrentUser', [
            'description' => 'Get information about the current authenticated user',
            'input_schema' => [
                'type' => 'object',
                'properties' => [],
            ],
        ]);
    }
    
    public function execute_searchPosts($params) {
        // Validate and sanitize limit parameter
        $limit = isset($params['limit']) ? absint($params['limit']) : 10;
        if ($limit < 1) $limit = 10;
        if ($limit > 100) $limit = 100; // Cap at 100 to prevent resource exhaustion
        
        // Validate and sanitize offset parameter
        $offset = isset($params['offset']) ? absint($params['offset']) : 0;
        
        $args = [
            'post_type' => 'post',
            'post_status' => $params['status'] ?? 'publish',
            'posts_per_page' => $limit,
            'offset' => $offset,
        ];
        
        if (isset($params['search']) && !empty($params['search'])) {
            $args['s'] = sanitize_text_field($params['search']);
        }
        
        if (isset($params['category'])) {
            $args['category_name'] = sanitize_text_field($params['category']);
        }
        
        if (isset($params['tag'])) {
            $args['tag'] = sanitize_text_field($params['tag']);
        }
        
        if (isset($params['author'])) {
            $args['author'] = absint($params['author']);
        }
        
        $query = new \WP_Query($args);
        
        if (!$query->have_posts()) {
            return $this->success_response([], 'No posts found');
        }
        
        $posts = [];
        while ($query->have_posts()) {
            $query->the_post();
            $posts[] = $this->format_post(\get_post());
        }
        \wp_reset_postdata();
        
        return $this->success_response($posts, sprintf('Found %d posts', count($posts)));
    }
    
    public function execute_getPost($params) {
        // Validate required parameter
        if (!isset($params['identifier'])) {
            return $this->error_response('Missing required parameter: identifier', 'missing_parameter');
        }
        
        $identifier = $params['identifier'];
        
        // Reject empty identifiers
        if ($identifier === '' || $identifier === null) {
            return $this->error_response('Parameter "identifier" cannot be empty', 'invalid_parameter');
        }
        
        if (is_numeric($identifier)) {
            $post = \get_post(absint($identifier));
        } else {
            $post = \get_page_by_path(sanitize_title($identifier), OBJECT, 'post');
        }
        
        if (!$post) {
            return $this->error_response('Post not found', 'post_not_found');
        }
        
        return $this->success_response($this->format_post($post));
    }
    
    public function execute_searchPages($params) {
        // Validate and sanitize limit parameter
        $limit = isset($params['limit']) ? absint($params['limit']) : 10;
        if ($limit < 1) $limit = 10;
        if ($limit > 100) $limit = 100; // Cap at 100 to prevent resource exhaustion
        
        $args = [
            'post_type' => 'page',
            'post_status' => $params['status'] ?? 'publish',
            'posts_per_page' => $limit,
        ];
        
        if (isset($params['search']) && !empty($params['search'])) {
            $args['s'] = sanitize_text_field($params['search']);
        }
        
        if (isset($params['parent'])) {
            $args['post_parent'] = absint($params['parent']);
        }
        
        $query = new \WP_Query($args);
        
        if (!$query->have_posts()) {
            return $this->success_response([], 'No pages found');
        }
        
        $pages = [];
        while ($query->have_posts()) {
            $query->the_post();
            $pages[] = $this->format_post(\get_post());
        }
        \wp_reset_postdata();
        
        return $this->success_response($pages, sprintf('Found %d pages', count($pages)));
    }
    
    public function execute_getPage($params) {
        // Validate required parameter
        if (!isset($params['identifier'])) {
            return $this->error_response('Missing required parameter: identifier', 'missing_parameter');
        }
        
        $identifier = $params['identifier'];
        
        // Reject empty identifiers
        if ($identifier === '' || $identifier === null) {
            return $this->error_response('Parameter "identifier" cannot be empty', 'invalid_parameter');
        }
        
        if (is_numeric($identifier)) {
            $page = \get_post(absint($identifier));
        } else {
            $page = \get_page_by_path(sanitize_title($identifier), OBJECT, 'page');
        }
        
        if (!$page || $page->post_type !== 'page') {
            return $this->error_response('Page not found', 'page_not_found');
        }
        
        return $this->success_response($this->format_post($page));
    }
    
    public function execute_getCurrentUser($params) {
        $current_user = \wp_get_current_user();
        
        if (!$current_user || $current_user->ID === 0) {
            return $this->error_response('No authenticated user', 'no_user');
        }
        
        return $this->success_response([
            'id' => $current_user->ID,
            'username' => $current_user->user_login,
            'email' => $current_user->user_email,
            'display_name' => $current_user->display_name,
            'roles' => $current_user->roles,
            'capabilities' => array_keys($current_user->allcaps),
        ]);
    }
    
    private function format_post($post) {
        \setup_postdata($post);
        $content = \get_the_content(null, false, $post);
        $content = \apply_filters('the_content', $content); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
        \wp_reset_postdata();
        
        return [
            'id' => $post->ID,
            'title' => \get_the_title($post),
            'content' => $content,
            'excerpt' => \get_the_excerpt($post),
            'slug' => $post->post_name,
            'status' => $post->post_status,
            'type' => $post->post_type,
            'author' => [
                'id' => $post->post_author,
                'name' => \get_the_author_meta('display_name', $post->post_author),
            ],
            'date' => $post->post_date,
            'modified' => $post->post_modified,
            'permalink' => \get_permalink($post),
            'featured_image' => \get_the_post_thumbnail_url($post, 'large'),
            'categories' => \wp_get_post_categories($post->ID, ['fields' => 'names']),
            'tags' => \wp_get_post_tags($post->ID, ['fields' => 'names']),
        ];
    }
}
