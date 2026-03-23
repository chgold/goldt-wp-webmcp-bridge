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

		// OAuth endpoints removed - now handled by dedicated OAuth classes.
		// Direct username+password authentication DISABLED for security.
		// Use OAuth 2.0 flow instead: /?goldtwmcp_oauth_authorize.
	}

	/**
	 * Register a module with the endpoint.
	 *
	 * @param \GoldtWebMCP\Modules\Module_Base $module Module instance.
	 * @return void
	 */
	public function register_module( $module ) {
		$module_name                   = $module->get_module_name();
		$this->modules[ $module_name ] = $module;
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

		$module = $this->modules[ $module_name ];
		$tools  = $module->get_tools();

		if ( ! isset( $tools[ $tool_method ] ) ) {
			return new \WP_Error( 'tool_not_found', sprintf( 'Tool %s not found', $tool_method ), array( 'status' => 404 ) );
		}

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

		foreach ( $this->modules as $module_name => $module ) {
			$module_tools = $module->get_tools();
			foreach ( $module_tools as $tool ) {
				$tools[] = array(
					'name'         => $tool['name'],
					'description'  => $tool['description'],
					'input_schema' => $tool['input_schema'],
				);
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
	 * Parse tool name into module and method parts.
	 *
	 * @param string $tool_name Full tool name (e.g. 'WordPress.searchPosts').
	 * @return array Two-element array: [module_name, tool_method].
	 */
	private function parse_tool_name( $tool_name ) {
		$parts = explode( '.', $tool_name, 2 );

		if ( count( $parts ) === 2 ) {
			return $parts;
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
