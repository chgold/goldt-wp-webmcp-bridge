<?php
/**
 * OAuth token endpoint class file.
 *
 * @package GoldtWebMCP
 */

namespace GoldtWebMCP\OAuth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the OAuth 2.0 token endpoint.
 *
 * @package GoldtWebMCP
 */
class Token_Endpoint {

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
	 * Register REST API routes for token exchange.
	 *
	 * @return void
	 */
	public function register_routes() {
		// Public endpoint - OAuth 2.0 token exchange, authentication handled via code_verifier.
		register_rest_route(
			'goldt-webmcp-bridge/v1/oauth',
			'/token',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_token_request' ),
				'permission_callback' => '__return_true', // Intentionally public - OAuth token endpoint per RFC 6749.
			)
		);
	}

	/**
	 * Handle token request (authorization_code or refresh_token grant).
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_token_request( $request ) {
		$grant_type = $request->get_param( 'grant_type' );

		if ( 'authorization_code' === $grant_type ) {
			return $this->handle_authorization_code_grant( $request );
		}

		if ( 'refresh_token' === $grant_type ) {
			return $this->handle_refresh_token_grant( $request );
		}

		return new \WP_Error(
			'unsupported_grant_type',
			'Grant type not supported',
			array( 'status' => 400 )
		);
	}

	/**
	 * Handle authorization_code grant type.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function handle_authorization_code_grant( $request ) {
		$code          = $request->get_param( 'code' );
		$client_id     = $request->get_param( 'client_id' );
		$code_verifier = $request->get_param( 'code_verifier' );
		$redirect_uri  = $request->get_param( 'redirect_uri' );

		if ( empty( $code ) || empty( $client_id ) || empty( $code_verifier ) ) {
			return new \WP_Error(
				'invalid_request',
				'Missing required parameters',
				array( 'status' => 400 )
			);
		}

		$token = $this->oauth_server->exchange_code_for_token(
			$code,
			$client_id,
			$code_verifier,
			$redirect_uri
		);

		if ( is_wp_error( $token ) ) {
			return new \WP_Error(
				$token->get_error_code(),
				$token->get_error_message(),
				array( 'status' => 400 )
			);
		}

		return rest_ensure_response( $token );
	}

	/**
	 * Handle refresh_token grant type.
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function handle_refresh_token_grant( $request ) {
		$refresh_token = $request->get_param( 'refresh_token' );
		$client_id     = $request->get_param( 'client_id' );

		if ( empty( $refresh_token ) || empty( $client_id ) ) {
			return new \WP_Error(
				'invalid_request',
				'Missing required parameters',
				array( 'status' => 400 )
			);
		}

		$token = $this->oauth_server->exchange_refresh_token(
			$refresh_token,
			$client_id
		);

		if ( is_wp_error( $token ) ) {
			return new \WP_Error(
				$token->get_error_code(),
				$token->get_error_message(),
				array( 'status' => 400 )
			);
		}

		return rest_ensure_response( $token );
	}
}
