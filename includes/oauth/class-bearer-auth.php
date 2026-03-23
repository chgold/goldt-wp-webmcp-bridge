<?php
/**
 * Bearer auth class file.
 *
 * @package GoldtWebMCP
 */

namespace GoldtWebMCP\OAuth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles OAuth 2.0 Bearer token authentication for WordPress REST API.
 *
 * @package GoldtWebMCP
 */
class Bearer_Auth {

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
		add_filter( 'determine_current_user', array( $this, 'determine_user_from_bearer_token' ), 20 );
		add_filter( 'rest_authentication_errors', array( $this, 'rest_auth_check' ) );
	}

	/**
	 * Determine the current user from a Bearer token.
	 *
	 * @param int|false $user_id Existing user ID or false.
	 * @return int|false User ID or false.
	 */
	public function determine_user_from_bearer_token( $user_id ) {
		if ( $user_id ) {
			return $user_id;
		}

		$token = $this->get_bearer_token();

		if ( ! $token ) {
			return $user_id;
		}

		$token_data = $this->oauth_server->validate_token( $token );

		if ( is_wp_error( $token_data ) ) {
			return $user_id;
		}

		return $token_data['user_id'];
	}

	/**
	 * Check REST API authentication for GoldtWebMCP requests.
	 *
	 * @param mixed $result Existing auth result.
	 * @return mixed True, WP_Error, or existing result.
	 */
	public function rest_auth_check( $result ) {
		if ( ! empty( $result ) ) {
			return $result;
		}

		if ( ! $this->is_goldtwmcp_request() ) {
			return $result;
		}

		// Public endpoints that don't require authentication.
		if ( $this->is_public_endpoint() ) {
			return $result;
		}

		$token = $this->get_bearer_token();

		if ( ! $token ) {
			return new \WP_Error(
				'rest_not_logged_in',
				'You are not currently logged in.',
				array( 'status' => 401 )
			);
		}

		$token_data = $this->oauth_server->validate_token( $token );

		if ( is_wp_error( $token_data ) ) {
			return new \WP_Error(
				'rest_invalid_token',
				$token_data->get_error_message(),
				array( 'status' => 401 )
			);
		}

		return true;
	}

	/**
	 * Extract the Bearer token from the Authorization header.
	 *
	 * @return string|null Token string or null.
	 */
	private function get_bearer_token() {
		$auth_header = $this->get_authorization_header();

		if ( ! $auth_header ) {
			return null;
		}

		if ( strpos( $auth_header, 'Bearer ' ) === 0 ) {
			return substr( $auth_header, 7 );
		}

		return null;
	}

	/**
	 * Get the Authorization header value.
	 *
	 * @return string|null Header value or null.
	 */
	private function get_authorization_header() {
		if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) );
		}

		if ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) );
		}

		if ( function_exists( 'apache_request_headers' ) ) {
			$headers = apache_request_headers();
			if ( isset( $headers['Authorization'] ) ) {
				return sanitize_text_field( $headers['Authorization'] );
			}
		}

		return null;
	}

	/**
	 * Check whether this is a GoldtWebMCP REST API request.
	 *
	 * @return bool
	 */
	private function is_goldtwmcp_request() {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		return strpos( $request_uri, '/wp-json/goldt-webmcp-bridge/' ) !== false;
	}

	/**
	 * Check whether the current request is a public endpoint.
	 *
	 * @return bool
	 */
	private function is_public_endpoint() {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		$public_endpoints = array(
			'/wp-json/goldt-webmcp-bridge/v1/oauth/token',
			'/wp-json/goldt-webmcp-bridge/v1/oauth/revoke',
			'/wp-json/goldt-webmcp-bridge/v1/manifest',
		);

		foreach ( $public_endpoints as $endpoint ) {
			if ( strpos( $request_uri, $endpoint ) !== false ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get token data for the current request.
	 *
	 * @return array|\WP_Error|null Token data, error, or null.
	 */
	public function get_current_token_data() {
		$token = $this->get_bearer_token();

		if ( ! $token ) {
			return null;
		}

		return $this->oauth_server->validate_token( $token );
	}

	/**
	 * Check whether the current token has the required scope.
	 *
	 * @param string $required_scope Required scope name.
	 * @return bool
	 */
	public function check_scope( $required_scope ) {
		$token_data = $this->get_current_token_data();

		if ( ! $token_data || is_wp_error( $token_data ) ) {
			return false;
		}

		return in_array( $required_scope, $token_data['scopes'], true );
	}
}
