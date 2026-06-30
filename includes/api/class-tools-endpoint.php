<?php
/**
 * Tools endpoint class file.
 *
 * @package GoldtWebMCP
 */

namespace GoldtWebMCP\API;

use GoldtWebMCP\Core\Rate_Limiter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API endpoint for tool execution and listing.
 *
 * @package GoldtWebMCP
 */
class Tools_Endpoint {

	/**
	 * Rate limiter instance.
	 *
	 * @var Rate_Limiter
	 */
	private $rate_limiter;

	/**
	 * Registered modules.
	 *
	 * @var array
	 */
	private $modules = array();

	/**
	 * Bearer auth instance.
	 *
	 * @var \GoldtWebMCP\OAuth\Bearer_Auth
	 */
	private $bearer_auth;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->rate_limiter = new Rate_Limiter();
		$this->bearer_auth  = new \GoldtWebMCP\OAuth\Bearer_Auth();
	}

	/**
	 * Register REST API routes for tool execution and listing.
	 *
	 * @return void
	 */
	public function register_routes() {
		\register_rest_route(
			'goldt-webmcp-bridge/v1',
			'/tools/(?P<tool>[a-zA-Z0-9._-]+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'execute_tool' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		\register_rest_route(
			'goldt-webmcp-bridge/v1',
			'/tools',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_tools' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		\register_rest_route(
			'goldt-webmcp-bridge/v1',
			'/generate-prompt',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'generate_prompt' ),
				'permission_callback' => array( $this, 'check_wp_session' ),
			)
		);

		\register_rest_route(
			'goldt-webmcp-bridge/v1',
			'/my-tokens',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_my_tokens' ),
				'permission_callback' => array( $this, 'check_wp_session' ),
			)
		);

		\register_rest_route(
			'goldt-webmcp-bridge/v1',
			'/my-tokens/(?P<id>\d+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'revoke_my_token' ),
				'permission_callback' => array( $this, 'check_wp_session' ),
			)
		);

		\register_rest_route(
			'goldt-webmcp-bridge/v1',
			'/my-tokens',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'revoke_all_my_tokens' ),
				'permission_callback' => array( $this, 'check_wp_session' ),
			)
		);

		// OAuth endpoints removed - now handled by dedicated OAuth classes.
		// Direct username+password authentication DISABLED for security.
		// Use OAuth 2.0 flow instead: /?goldtwmcp_oauth_authorize.
	}

	/**
	 * Register a module with the endpoint.
	 *
	 * Multiple module instances may share the same module_name (e.g. core +
	 * Pro Posts/Pages/Media all under "WordPress"). Each module instance is
	 * appended to a list so its tools remain reachable.
	 *
	 * @param \GoldtWebMCP\Modules\Module_Base $module Module instance.
	 * @return void
	 */
	public function register_module( $module ) {
		$module_name = $module->get_module_name();
		if ( ! isset( $this->modules[ $module_name ] ) ) {
			$this->modules[ $module_name ] = array();
		}
		$this->modules[ $module_name ][] = $module;
	}

	/**
	 * Find the module instance that owns a given tool method.
	 *
	 * @param string $module_name Module namespace (e.g. "WordPress").
	 * @param string $tool_method Tool method name (e.g. "createPost").
	 * @return \GoldtWebMCP\Modules\Module_Base|null
	 */
	private function find_module_for_tool( $module_name, $tool_method ) {
		if ( ! isset( $this->modules[ $module_name ] ) ) {
			return null;
		}
		foreach ( $this->modules[ $module_name ] as $module ) {
			$tools = $module->get_tools();
			if ( isset( $tools[ $tool_method ] ) ) {
				return $module;
			}
		}
		return null;
	}

	/**
	 * Execute a tool via REST API.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function execute_tool( $request ) {
		$tool_name   = $request->get_param( 'tool' );
		$json_params = $request->get_json_params();
		$params      = $json_params ? $json_params : array();

		list($module_name, $tool_method) = $this->parse_tool_name( $tool_name );

		if ( ! isset( $this->modules[ $module_name ] ) ) {
			return new \WP_Error( 'module_not_found', sprintf( 'Module %s not found', $module_name ), array( 'status' => 404 ) );
		}

		$module = $this->find_module_for_tool( $module_name, $tool_method );
		if ( ! $module ) {
			return new \WP_Error( 'tool_not_found', sprintf( 'Tool %s not found', $tool_method ), array( 'status' => 404 ) );
		}

		$tools = $module->get_tools();

		$tool           = $tools[ $tool_method ];
		$required_scope = $tool['required_scope'] ?? 'read';

		if ( ! $this->check_scope( $required_scope ) ) {
			return new \WP_Error(
				'insufficient_scope',
				sprintf( 'This tool requires the "%s" scope. Please re-authorize with the required permissions.', $required_scope ),
				array( 'status' => 403 )
			);
		}

		$result = $module->execute_tool( $tool_method, $params );

		if ( \is_wp_error( $result ) ) {
			return $result;
		}

		return \rest_ensure_response( $result );
	}

	/**
	 * List all registered tools via REST API.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function list_tools( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by WP REST API callback signature.
		$tools = array();

		foreach ( $this->modules as $module_list ) {
			foreach ( $module_list as $module ) {
				$module_tools = $module->get_tools();
				foreach ( $module_tools as $tool ) {
					$tools[] = array(
						'name'         => $tool['name'],
						'description'  => $tool['description'],
						'input_schema' => $tool['input_schema'],
					);
				}
			}
		}

		return \rest_ensure_response( array( 'tools' => $tools ) );
	}

	/**
	 * Check whether the current request has permission to use the API.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return true|\WP_Error
	 */
	public function check_permission( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by WP REST API callback signature.
		if ( ! \is_user_logged_in() ) {
			return new \WP_Error( 'no_token', 'No authentication token provided. Use OAuth 2.0 flow.', array( 'status' => 401 ) );
		}

		$user_id = \get_current_user_id();

		$blacklisted_users = \get_option( 'goldtwmcp_blacklisted_users', array() );
		if ( in_array( $user_id, $blacklisted_users, true ) ) {
			return new \WP_Error( 'access_denied', 'Your access to AI Connect has been revoked', array( 'status' => 403 ) );
		}

		$identifier = 'user_' . $user_id;

		$rate_check = $this->rate_limiter->is_rate_limited( $identifier );

		if ( $rate_check['limited'] ) {
			return new \WP_Error(
				'rate_limit_exceeded',
				sprintf( 'Rate limit exceeded: %s', $rate_check['reason'] ),
				array(
					'status'      => 429,
					'retry_after' => $rate_check['retry_after'],
					'limit'       => $rate_check['limit'],
					'current'     => $rate_check['current'],
				)
			);
		}

		$this->rate_limiter->record_request( $identifier );

		return true;
	}

	/**
	 * Check whether the current request is authenticated via WordPress session cookie.
	 *
	 * @return true|\WP_Error
	 */
	public function check_wp_session() {
		if ( ! \is_user_logged_in() ) {
			return new \WP_Error( 'not_logged_in', 'You must be logged into WordPress.', array( 'status' => 401 ) );
		}
		return true;
	}

	/**
	 * List the current user's AI tokens (registry rows + agent labels).
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function list_my_tokens( $request ) {
		$user_id = (int) \get_current_user_id();
		$status  = (string) ( $request->get_param( 'status' ) ?? 'all' );
		$allowed = array( 'active', 'unused', 'inactive', 'renewable', 'expired', 'revoked', 'all' );
		if ( ! in_array( $status, $allowed, true ) ) {
			$status = 'all';
		}

		$rows = \GoldtWebMCP\OAuth\Token_Registry::list(
			array(
				'user_id' => $user_id,
				'status'  => $status,
				'limit'   => 200,
			)
		);

		$agents = self::get_agent_clients();
		$now    = time();
		$tokens = array();
		foreach ( $rows as $row ) {
			$client       = (string) ( $row['client_id'] ?? '' );
			$expires_at   = (int) ( $row['expires_at'] ?? 0 );
			$last_used_at = isset( $row['last_used_at'] ) ? (int) $row['last_used_at'] : null;
			$revoked_at   = isset( $row['revoked_at'] ) ? (int) $row['revoked_at'] : null;
			$issued_at    = (int) ( $row['issued_at'] ?? 0 );
			$is_revoked   = ! empty( $revoked_at );
			$is_expired   = ! $is_revoked && $expires_at > 0 && $expires_at <= $now;
			$state        = $is_revoked ? 'revoked' : ( $is_expired ? 'expired' : 'active' );

			$tokens[] = array(
				'id'           => (int) $row['id'],
				'token_prefix' => (string) ( $row['token_prefix'] ?? '' ),
				'client_id'    => $client,
				'client_label' => $agents[ $client ]['label'] ?? $client,
				'scope'        => (string) ( $row['scope'] ?? '' ),
				'source'       => (string) ( $row['source'] ?? '' ),
				'issued_at'    => $issued_at,
				'expires_at'   => $expires_at,
				'last_used_at' => $last_used_at,
				'last_used_ip' => $row['last_used_ip'] ?? null,
				'revoked_at'   => $revoked_at,
				'state'        => $state,
			);
		}

		return \rest_ensure_response(
			array(
				'tokens'  => $tokens,
				'status'  => $status,
				'user_id' => $user_id,
			)
		);
	}

	/**
	 * Revoke one of the current user's tokens by registry ID.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function revoke_my_token( $request ) {
		$user_id = (int) \get_current_user_id();
		$id      = (int) $request->get_param( 'id' );
		if ( $id <= 0 ) {
			return new \WP_Error( 'invalid_id', 'Invalid token ID.', array( 'status' => 400 ) );
		}

		// Verify ownership before revoking — prevents user A from revoking user B's token.
		global $wpdb;
		$table = \GoldtWebMCP\OAuth\Token_Registry::table_name();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Ownership check; table name from $wpdb->prefix; $id is bound via prepare().
		$owner = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT user_id FROM `{$table}` WHERE id = %d", $id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix.
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $owner !== $user_id ) {
			return new \WP_Error( 'forbidden', 'You can only revoke your own tokens.', array( 'status' => 403 ) );
		}

		$ok = \GoldtWebMCP\OAuth\Token_Registry::revoke_by_id( $id, $user_id );
		return \rest_ensure_response(
			array(
				'success' => (bool) $ok,
				'id'      => $id,
			)
		);
	}

	/**
	 * Revoke ALL of the current user's active tokens.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function revoke_all_my_tokens( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by WP REST callback.
		$user_id = (int) \get_current_user_id();
		$count   = \GoldtWebMCP\OAuth\Token_Registry::revoke_all_for_user( $user_id, $user_id );

		return \rest_ensure_response(
			array(
				'success' => true,
				'revoked' => (int) $count,
			)
		);
	}

	/**
	 * Supported AI agent clients — keyed by client_id (OAuth client identifier).
	 *
	 * Used only for nicer labels when listing existing tokens (`/my-tokens`).
	 * The prompt itself is universal (MCP + REST) so the user is not asked to
	 * pick an agent at generation time.
	 *
	 * @return array<string,array{label:string}>
	 */
	public static function get_agent_clients() {
		return array(
			'claude-ai'     => array( 'label' => 'Claude (Anthropic)' ),
			'chatgpt'       => array( 'label' => 'ChatGPT (OpenAI)' ),
			'gemini'        => array( 'label' => 'Gemini (Google)' ),
			'copilot'       => array( 'label' => 'Microsoft Copilot' ),
			'grok'          => array( 'label' => 'Grok (xAI)' ),
			'deepseek'      => array( 'label' => 'DeepSeek AI' ),
			'perplexity'    => array( 'label' => 'Perplexity AI' ),
			'meta-ai'       => array( 'label' => 'Meta AI' ),
			'webmcp-master' => array( 'label' => 'Goldnat (webmcp)' ),
		);
	}

	/**
	 * Derive OAuth scopes from the current WordPress user's capabilities.
	 *
	 * Mirrors the XenForo addon model: the prompt grants access at the
	 * intersection of the user's existing permissions, not at a level the
	 * user chooses in a UI. A subscriber gets read-only; an editor adds
	 * write; an admin adds delete + manage_users.
	 *
	 * @return array<int,string>
	 */
	private function derive_scopes_from_caps() {
		$scopes = array( 'read' );

		if ( \current_user_can( 'edit_posts' ) || \current_user_can( 'edit_pages' ) ) {
			$scopes[] = 'write';
		}
		if ( \current_user_can( 'delete_posts' ) || \current_user_can( 'delete_pages' ) ) {
			$scopes[] = 'delete';
		}
		if ( \current_user_can( 'list_users' ) || \current_user_can( 'edit_users' ) ) {
			$scopes[] = 'manage_users';
		}

		return $scopes;
	}

	/**
	 * Generate a personalized MCP connection prompt for the current WP user.
	 *
	 * Scopes are derived from the user's WordPress capabilities — there is no
	 * UI knob to elevate beyond what the user can already do. The prompt is
	 * universal (MCP + REST in one) so the user can paste it into Claude,
	 * ChatGPT, Gemini, or any agent.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function generate_prompt( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by WP REST callback.
		$user_id = \get_current_user_id();
		$scopes  = $this->derive_scopes_from_caps();

		// Token is issued under the canonical 'claude-ai' OAuth client.
		// The Bearer is agent-agnostic — any AI agent that supports MCP or REST
		// can use it. The client_id only matters for OAuth bookkeeping.
		$client_id = 'claude-ai';

		$oauth      = new \GoldtWebMCP\OAuth\OAuth_Server();
		$token_data = $oauth->create_access_token( $client_id, $user_id, $scopes, 'user-ui' );

		if ( \is_wp_error( $token_data ) ) {
			return $token_data;
		}

		$prompt = $this->build_mcp_prompt(
			$token_data['access_token'],
			$token_data['refresh_token'],
			$client_id,
			$scopes
		);

		return \rest_ensure_response(
			array(
				'prompt'        => $prompt,
				'scopes'        => $scopes,
				'access_token'  => $token_data['access_token'],
				'refresh_token' => $token_data['refresh_token'],
				'expires_in'    => $token_data['expires_in'],
			)
		);
	}

	/**
	 * Collect site-level metadata used by every prompt template.
	 *
	 * @return array
	 */
	private function collect_prompt_context() {
		$base_url      = \home_url();
		$site_name     = \get_bloginfo( 'name' );
		$parsed        = \wp_parse_url( $base_url );
		$host          = $parsed['host'] ?? 'WordPress';
		$path_part     = rtrim( $parsed['path'] ?? '', '/' );
		$site_name_mcp = '' !== $path_part ? $host . $path_part : $host;
		$site_key      = preg_replace( '/[^a-zA-Z0-9_-]/', '_', $site_name_mcp );

		$all_tools      = array();
		$tools_by_scope = array(
			'read'   => array(),
			'write'  => array(),
			'delete' => array(),
		);
		foreach ( $this->modules as $module_list ) {
			foreach ( $module_list as $module ) {
				foreach ( $module->get_tools() as $tool ) {
					$scope                      = $tool['required_scope'] ?? 'read';
					$all_tools[]                = $tool;
					$tools_by_scope[ $scope ][] = $tool;
				}
			}
		}

		return array(
			'base_url'       => $base_url,
			'site_name'      => $site_name,
			'site_name_mcp'  => $site_name_mcp,
			'site_key'       => $site_key,
			'manifest_url'   => \rest_url( 'goldt-webmcp-bridge/v1/manifest' ),
			'tool_root'      => \rest_url( 'goldt-webmcp-bridge/v1/tools' ),
			'token_url'      => \rest_url( 'goldt-webmcp-bridge/v1/oauth/token' ),
			'all_tools'      => $all_tools,
			'tools_by_scope' => $tools_by_scope,
		);
	}

	/**
	 * Filter tools down to those that match the granted scopes.
	 *
	 * @param array $all_tools List of tool definitions.
	 * @param array $scopes    List of granted scope strings.
	 * @return array
	 */
	private function filter_tools_by_scopes( array $all_tools, array $scopes ) {
		return array_values(
			array_filter(
				$all_tools,
				static function ( $tool ) use ( $scopes ) {
					$req = $tool['required_scope'] ?? 'read';
					return in_array( $req, $scopes, true );
				}
			)
		);
	}

	/**
	 * Build the universal connection prompt — XenForo addon style.
	 *
	 * Single prompt that contains BOTH the MCP block (Claude Desktop / WebMCP)
	 * AND the HTTP REST fallback. The user pastes it into whatever agent they
	 * use; the agent picks the path it understands. Scopes shown are exactly
	 * the user's WP capabilities — no over-grant.
	 *
	 * @param string $access_token  Bearer access token.
	 * @param string $refresh_token Refresh token.
	 * @param string $client_id     OAuth client_id used to issue the token.
	 * @param array  $scopes        Granted scope list (derived from user caps).
	 * @return string
	 */
	private function build_mcp_prompt( $access_token, $refresh_token, $client_id = 'claude-ai', array $scopes = array( 'read' ) ) {
		$ctx           = $this->collect_prompt_context();
		$granted_tools = $this->filter_tools_by_scopes( $ctx['all_tools'], $scopes );
		$read_tools    = array();
		foreach ( $granted_tools as $tool ) {
			if ( ( $tool['required_scope'] ?? 'read' ) === 'read' ) {
				$read_tools[] = $tool['name'];
			}
		}
		$scope_summary = implode( ' + ', $scopes );

		$lines   = array();
		$lines[] = 'You have access to ' . $ctx['site_name'] . ' via AI Connect.';
		$lines[] = 'Granted scope: ' . $scope_summary . ' (matches your WordPress permissions)';
		$lines[] = '';
		$lines[] = '## MCP (Recommended — Claude Desktop / WebMCP-compatible)';
		$lines[] = 'Call webmcp_addSite with these parameters:';
		$lines[] = '  name:          "' . $ctx['site_name_mcp'] . '"';
		$lines[] = '  manifest_url:  "' . $ctx['manifest_url'] . '"';
		$lines[] = '  token:         "Bearer ' . $access_token . '"';
		$lines[] = '  refresh_token: "' . $refresh_token . '"';
		$lines[] = '';
		$lines[] = 'IMPORTANT: Paste BOTH token and refresh_token — otherwise the connection will stop working after 1 hour.';
		$lines[] = '';
		if ( ! empty( $granted_tools ) ) {
			$lines[] = 'After adding the site, call these tools by EXACT name (do not search — use directly):';
			foreach ( $granted_tools as $tool ) {
				$mcp_name = $ctx['site_key'] . '_' . str_replace( '.', '_', $tool['name'] );
				$hint     = substr( $tool['description'] ?? '', 0, 70 );
				$lines[]  = '  ' . str_pad( $mcp_name, 50 ) . '<- ' . $hint;
			}
		} else {
			$lines[] = '(No tools available for your current permissions.)';
		}
		$lines[] = '';
		if ( ! empty( $read_tools ) ) {
			$lines[] = '## Direct URL Access (Fallback — read-only tools)';
			$lines[] = 'If MCP is not available, read-only tools can be called via POST:';
			$lines[] = '  Authorization: Bearer ' . $access_token;
			$lines[] = '  Content-Type: application/json';
			$lines[] = '';
			foreach ( $read_tools as $tool_name ) {
				$lines[] = '  POST ' . $ctx['tool_root'] . '/' . $tool_name . '  body: {}';
			}
			$lines[] = '';
		}
		$lines[] = '## Token Refresh (valid 30 days)';
		$lines[] = 'When access_token expires (after 1 hour), refresh it:';
		$lines[] = '  POST ' . $ctx['token_url'];
		$lines[] = '  Content-Type: application/json';
		$lines[] = '  {"grant_type":"refresh_token","refresh_token":"' . $refresh_token . '","client_id":"' . $client_id . '"}';
		$lines[] = 'Response contains a new access_token + new refresh_token (old pair is revoked).';
		$lines[] = '';
		$lines[] = 'IMPORTANT: Do NOT use webmcp tool search — it may return tools from other sites.';
		$lines[] = 'Call the tools listed above by their EXACT full name. Start with getCurrentUser.';
		$lines[] = 'Security note: This token acts on behalf of the user who generated it. Handle it with care.';
		$lines[] = 'Documentation: https://ai-connect.gold-t.co.il/wordpress';

		return implode( "\n", $lines );
	}

	/**
	 * Parse tool name into module and method parts.
	 *
	 * @param string $tool_name Full tool name (e.g. 'WordPress.searchPosts').
	 * @return array Two-element array: [module_name, tool_method].
	 */
	private function parse_tool_name( $tool_name ) {
		if ( str_contains( $tool_name, '.' ) ) {
			return explode( '.', $tool_name, 2 );
		}

		foreach ( array_keys( $this->modules ) as $module_name ) {
			$prefix = $module_name . '_';
			if ( str_starts_with( $tool_name, $prefix ) ) {
				return array( $module_name, substr( $tool_name, strlen( $prefix ) ) );
			}
		}

		return array( 'wordpress', $tool_name );
	}

	/**
	 * Check whether the current token has the required scope.
	 *
	 * @param string $required_scope Required scope name.
	 * @return bool
	 */
	private function check_scope( $required_scope ) {
		return $this->bearer_auth->check_scope( $required_scope );
	}
}
