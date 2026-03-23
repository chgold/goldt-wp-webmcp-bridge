<?php
/**
 * OAuth revoke endpoint class file.
 *
 * @package GoldtWebMCP
 */

namespace GoldtWebMCP\OAuth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the OAuth 2.0 token revocation endpoint.
 *
 * @package GoldtWebMCP
 */
class Revoke_Endpoint {

	/**
	 * OAuth server instance.
	 *
	 * @var OAuth_Server
	 */
	private $oauth_server;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->oauth_server = new OAuth_Server();
	}

	/**
	 * Initialize WordPress hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST API routes for token revocation.
	 *
	 * @return void
	 */
	public function register_routes() {
		// Public endpoint - OAuth 2.0 token revocation, token validation handled in callback.
		register_rest_route(
			'goldt-webmcp-bridge/v1/oauth',
			'/revoke',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_revoke_request' ),
				'permission_callback' => '__return_true', // Intentionally public - token revocation per RFC 7009.
			)
		);
	}

	/**
	 * Handle token revocation request.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_revoke_request( $request ) {
		$token = $request->get_param( 'token' );

		if ( empty( $token ) ) {
			return new \WP_Error(
				'invalid_request',
				'Missing token parameter',
				array( 'status' => 400 )
			);
		}

		$revoked = $this->oauth_server->revoke_token( $token );

		if ( ! $revoked ) {
			return new \WP_Error(
				'invalid_token',
				'Token not found or already revoked',
				array( 'status' => 400 )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'message' => 'Token revoked successfully',
			)
		);
	}
}
