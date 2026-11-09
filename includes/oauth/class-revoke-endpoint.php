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
	 * Handle token revocation request (RFC 7009).
	 *
	 * Accepts form-urlencoded or JSON bodies. `token` (access OR refresh token)
	 * is required; `token_type_hint` only changes the lookup order. Unknown or
	 * already-revoked tokens still return 200 so callers cannot probe for valid
	 * tokens; only a missing `token` returns 400 invalid_request.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function handle_revoke_request( $request ) {
		$token = $request->get_param( 'token' );
		$token = is_string( $token ) ? trim( $token ) : '';

		if ( '' === $token ) {
			return new \WP_REST_Response(
				array(
					'error'             => 'invalid_request',
					'error_description' => 'Missing token parameter',
				),
				400
			);
		}

		$hint = $request->get_param( 'token_type_hint' );
		$hint = is_string( $hint ) ? sanitize_key( $hint ) : null;

		// The result is deliberately not echoed back: the response is identical
		// for valid, unknown and already-revoked tokens (RFC 7009 section 2.2).
		$this->oauth_server->revoke_token( $token, $hint );

		$response = new \WP_REST_Response(
			array( 'success' => true ),
			200
		);
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}
}
