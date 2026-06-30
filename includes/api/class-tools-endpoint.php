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
			'/prompt-options',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_prompt_options' ),
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
	 * Available scope presets — keyed by preset slug.
	 *
	 * @return array<string,array{label:string,scopes:array<string>,description:string}>
	 */
	public static function get_scope_presets() {
		return array(
			'read_only'  => array(
				'label'       => 'Read only',
				'scopes'      => array( 'read' ),
				'description' => 'Safest. AI can read posts, pages, users — but cannot change anything.',
			),
			'read_write' => array(
				'label'       => 'Read + Write',
				'scopes'      => array( 'read', 'write' ),
				'description' => 'AI can create/update content. Cannot delete. Recommended default.',
			),
			'full'       => array(
				'label'       => 'Full access (read + write + delete)',
				'scopes'      => array( 'read', 'write', 'delete' ),
				'description' => 'AI can read, create, update, and delete. Use only for trusted automations.',
			),
		);
	}

	/**
	 * Supported AI agent clients — keyed by client_id (OAuth client identifier).
	 *
	 * @return array<string,array{label:string,template:string}>
	 */
	public static function get_agent_clients() {
		return array(
			'claude-ai'     => array(
				'label'    => 'Claude (Anthropic)',
				'template' => 'mcp',
			),
			'chatgpt'       => array(
				'label'    => 'ChatGPT (OpenAI)',
				'template' => 'mcp',
			),
			'gemini'        => array(
				'label'    => 'Gemini (Google)',
				'template' => 'rest',
			),
			'copilot'       => array(
				'label'    => 'Microsoft Copilot',
				'template' => 'rest',
			),
			'grok'          => array(
				'label'    => 'Grok (xAI)',
				'template' => 'rest',
			),
			'deepseek'      => array(
				'label'    => 'DeepSeek AI',
				'template' => 'rest',
			),
			'perplexity'    => array(
				'label'    => 'Perplexity AI',
				'template' => 'rest',
			),
			'meta-ai'       => array(
				'label'    => 'Meta AI',
				'template' => 'rest',
			),
			'webmcp-master' => array(
				'label'    => 'Goldnat (webmcp)',
				'template' => 'mcp',
			),
		);
	}

	/**
	 * Generate a personalized MCP connection prompt for the current WP user.
	 *
	 * Accepted POST body parameters:
	 *  - client_id    : agent OAuth client (default: claude-ai). See get_agent_clients().
	 *  - scope_preset : 'read_only' | 'read_write' | 'full' (default: read_write).
	 *  - template     : 'mcp' | 'rest' (default: per-agent default).
	 *  - scope        : LEGACY — space-separated raw scopes. Ignored if scope_preset provided.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function generate_prompt( $request ) {
		$user_id = \get_current_user_id();

		// Resolve client_id (defaults to claude-ai for backwards compatibility).
		$agents     = self::get_agent_clients();
		$client_raw = (string) ( $request->get_param( 'client_id' ) ?? '' );
		$client_id  = isset( $agents[ $client_raw ] ) ? $client_raw : 'claude-ai';

		// Resolve scopes via preset (preferred) or legacy 'scope' param.
		$presets    = self::get_scope_presets();
		$preset_raw = (string) ( $request->get_param( 'scope_preset' ) ?? '' );
		$preset_key = isset( $presets[ $preset_raw ] ) ? $preset_raw : '';
		if ( '' !== $preset_key ) {
			$scopes = $presets[ $preset_key ]['scopes'];
		} else {
			$scope_param = (string) ( $request->get_param( 'scope' ) ?? '' );
			$scope_str   = sanitize_text_field( '' !== $scope_param ? $scope_param : 'read write' );
			$scopes      = array_values( array_filter( explode( ' ', $scope_str ) ) );
			$preset_key  = 'read_write';
		}

		// Resolve template ('mcp' = Claude Desktop / WebMCP style, 'rest' = direct HTTP).
		$tpl_raw      = (string) ( $request->get_param( 'template' ) ?? '' );
		$tpl_default  = $agents[ $client_id ]['template'];
		$template_key = in_array( $tpl_raw, array( 'mcp', 'rest' ), true ) ? $tpl_raw : $tpl_default;

		$oauth      = new \GoldtWebMCP\OAuth\OAuth_Server();
		$token_data = $oauth->create_access_token( $client_id, $user_id, $scopes, 'user-ui' );

		if ( \is_wp_error( $token_data ) ) {
			return $token_data;
		}

		$prompt = $this->build_mcp_prompt(
			$token_data['access_token'],
			$token_data['refresh_token'],
			$client_id,
			$scopes,
			$template_key
		);

		return \rest_ensure_response(
			array(
				'prompt'        => $prompt,
				'client_id'     => $client_id,
				'scope_preset'  => $preset_key,
				'template'      => $template_key,
				'access_token'  => $token_data['access_token'],
				'refresh_token' => $token_data['refresh_token'],
				'expires_in'    => $token_data['expires_in'],
			)
		);
	}

	/**
	 * Return metadata for the prompt generator UI (clients + scope presets).
	 *
	 * @return \WP_REST_Response
	 */
	public function get_prompt_options() {
		$clients = array();
		foreach ( self::get_agent_clients() as $id => $info ) {
			$clients[] = array(
				'id'               => $id,
				'label'            => $info['label'],
				'default_template' => $info['template'],
			);
		}
		$presets = array();
		foreach ( self::get_scope_presets() as $key => $info ) {
			$presets[] = array(
				'key'         => $key,
				'label'       => $info['label'],
				'description' => $info['description'],
			);
		}
		return \rest_ensure_response(
			array(
				'clients' => $clients,
				'presets' => $presets,
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
	 * Build a personalized connection prompt.
	 *
	 * @param string $access_token  Bearer access token.
	 * @param string $refresh_token Refresh token.
	 * @param string $client_id     OAuth client identifier.
	 * @param array  $scopes        Granted scope list.
	 * @param string $template      'mcp' or 'rest'.
	 * @return string
	 */
	private function build_mcp_prompt( $access_token, $refresh_token, $client_id = 'claude-ai', array $scopes = array( 'read', 'write' ), $template = 'mcp' ) {
		$ctx           = $this->collect_prompt_context();
		$granted_tools = $this->filter_tools_by_scopes( $ctx['all_tools'], $scopes );
		$agents        = self::get_agent_clients();
		$agent_label   = $agents[ $client_id ]['label'] ?? $client_id;
		$scope_summary = implode( ' + ', $scopes );

		if ( 'rest' === $template ) {
			return $this->build_rest_prompt( $access_token, $refresh_token, $client_id, $agent_label, $scope_summary, $granted_tools, $ctx );
		}

		return $this->build_mcp_template_prompt( $access_token, $refresh_token, $client_id, $agent_label, $scope_summary, $granted_tools, $ctx );
	}

	/**
	 * MCP-style template (Claude Desktop, WebMCP, ChatGPT MCP).
	 *
	 * @param string $access_token  Bearer access token.
	 * @param string $refresh_token Refresh token.
	 * @param string $client_id     OAuth client identifier.
	 * @param string $agent_label   Human-readable agent label.
	 * @param string $scope_summary Pretty scope summary (e.g. "read + write").
	 * @param array  $granted_tools Tools matching the granted scopes.
	 * @param array  $ctx           Prompt context from collect_prompt_context().
	 * @return string
	 */
	private function build_mcp_template_prompt( $access_token, $refresh_token, $client_id, $agent_label, $scope_summary, array $granted_tools, array $ctx ) {
		$lines   = array();
		$lines[] = 'You have access to ' . $ctx['site_name'] . ' via AI Connect.';
		$lines[] = 'Agent: ' . $agent_label . ' — Scope: ' . $scope_summary;
		$lines[] = '';
		$lines[] = '## MCP Setup (Claude Desktop / WebMCP-compatible)';
		$lines[] = 'Call webmcp_addSite with these parameters:';
		$lines[] = '  name:          "' . $ctx['site_name_mcp'] . '"';
		$lines[] = '  manifest_url:  "' . $ctx['manifest_url'] . '"';
		$lines[] = '  token:         "Bearer ' . $access_token . '"';
		$lines[] = '  refresh_token: "' . $refresh_token . '"';
		$lines[] = '';
		$lines[] = 'IMPORTANT: Paste BOTH token and refresh_token — otherwise the connection will stop working after 1 hour.';
		$lines[] = '';
		if ( empty( $granted_tools ) ) {
			$lines[] = '(No tools available for the granted scope.)';
		} else {
			$lines[] = 'Available tools (call by EXACT name — do not search):';
			foreach ( $granted_tools as $tool ) {
				$mcp_name = $ctx['site_key'] . '_' . str_replace( '.', '_', $tool['name'] );
				$hint     = substr( $tool['description'] ?? '', 0, 70 );
				$lines[]  = '  ' . str_pad( $mcp_name, 50 ) . '<- ' . $hint;
			}
		}
		$lines[] = '';
		$lines[] = '## Token Refresh (valid 30 days)';
		$lines[] = 'When access_token expires (after 1 hour), refresh it:';
		$lines[] = '  POST ' . $ctx['token_url'];
		$lines[] = '  Content-Type: application/json';
		$lines[] = '  {"grant_type":"refresh_token","refresh_token":"' . $refresh_token . '","client_id":"' . $client_id . '"}';
		$lines[] = 'Response contains a new access_token + new refresh_token (old pair is revoked).';
		$lines[] = '';
		$lines[] = 'IMPORTANT: Do NOT use webmcp tool search — it may return tools from other sites.';
		$lines[] = 'Call the tools listed above by their EXACT full name.';
		$lines[] = 'Security note: This token acts on behalf of the user who generated it. Handle it with care.';
		$lines[] = 'Documentation: https://ai-connect.gold-t.co.il/wordpress';

		return implode( "\n", $lines );
	}

	/**
	 * REST/HTTP-style template (Gemini, Copilot, Perplexity, anything without MCP).
	 *
	 * @param string $access_token  Bearer access token.
	 * @param string $refresh_token Refresh token.
	 * @param string $client_id     OAuth client identifier.
	 * @param string $agent_label   Human-readable agent label.
	 * @param string $scope_summary Pretty scope summary (e.g. "read + write").
	 * @param array  $granted_tools Tools matching the granted scopes.
	 * @param array  $ctx           Prompt context from collect_prompt_context().
	 * @return string
	 */
	private function build_rest_prompt( $access_token, $refresh_token, $client_id, $agent_label, $scope_summary, array $granted_tools, array $ctx ) {
		$lines   = array();
		$lines[] = 'You have access to ' . $ctx['site_name'] . ' via AI Connect.';
		$lines[] = 'Agent: ' . $agent_label . ' — Scope: ' . $scope_summary;
		$lines[] = '';
		$lines[] = '## HTTP API';
		$lines[] = 'Manifest: ' . $ctx['manifest_url'];
		$lines[] = 'Auth: Bearer ' . $access_token;
		$lines[] = '';
		$lines[] = '## Available Tools';
		if ( empty( $granted_tools ) ) {
			$lines[] = '(No tools available for the granted scope.)';
		} else {
			$lines[] = 'Each tool is invoked via POST with a JSON body.';
			$lines[] = '';
			foreach ( $granted_tools as $tool ) {
				$lines[] = '### ' . $tool['name'];
				if ( ! empty( $tool['description'] ) ) {
					$lines[] = $tool['description'];
				}
				$lines[] = 'Endpoint: POST ' . $ctx['tool_root'] . '/' . $tool['name'];
				$lines[] = 'Headers:  Authorization: Bearer ' . $access_token;
				$lines[] = '          Content-Type: application/json';
				$lines[] = '';
			}
		}
		$lines[] = '## Example';
		if ( ! empty( $granted_tools ) ) {
			$first   = $granted_tools[0];
			$lines[] = 'curl -X POST "' . $ctx['tool_root'] . '/' . $first['name'] . '" \\';
			$lines[] = '  -H "Authorization: Bearer ' . $access_token . '" \\';
			$lines[] = '  -H "Content-Type: application/json" \\';
			$lines[] = '  -d "{}"';
			$lines[] = '';
		}
		$lines[] = '## Token Refresh (valid 30 days)';
		$lines[] = 'When access_token expires (after 1 hour):';
		$lines[] = '  POST ' . $ctx['token_url'];
		$lines[] = '  Content-Type: application/json';
		$lines[] = '  {"grant_type":"refresh_token","refresh_token":"' . $refresh_token . '","client_id":"' . $client_id . '"}';
		$lines[] = '';
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
