<?php
/**
 * OAuth authorize endpoint class file.
 *
 * @package GoldtWebMCP
 */

namespace GoldtWebMCP\OAuth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the OAuth 2.0 authorization endpoint.
 *
 * @package GoldtWebMCP
 */
class Authorize_Endpoint {

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
	 * Initialize hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'template_redirect', array( $this, 'handle_authorize_request' ) );
	}

	/**
	 * Handle the OAuth authorization request.
	 *
	 * @return void
	 */
	public function handle_authorize_request() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth flow uses state parameter for CSRF protection
		if ( ! isset( $_GET['goldtwmcp_oauth_authorize'] ) ) {
			return;
		}

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth parameters come from external client, validated via PKCE
		$client_id = isset( $_GET['client_id'] ) ? sanitize_text_field( wp_unslash( $_GET['client_id'] ) ) : '';

		// 4A: Default to 'claude' when client_id is omitted.
		if ( empty( $client_id ) ) {
			$client_id = 'claude';
		}

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$redirect_uri = isset( $_GET['redirect_uri'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_uri'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$response_type = isset( $_GET['response_type'] ) ? sanitize_text_field( wp_unslash( $_GET['response_type'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$scope = isset( $_GET['scope'] ) ? sanitize_text_field( wp_unslash( $_GET['scope'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$code_challenge = isset( $_GET['code_challenge'] ) ? sanitize_text_field( wp_unslash( $_GET['code_challenge'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$code_challenge_method = isset( $_GET['code_challenge_method'] ) ? sanitize_text_field( wp_unslash( $_GET['code_challenge_method'] ) ) : '';

		if ( 'code' !== $response_type ) {
			$this->send_error( 'unsupported_response_type', 'Only authorization code flow is supported' );
			return;
		}

		// 4B: Validate client with fuzzy matching; get canonical client_id back.
		$canonical_client_id = $this->oauth_server->validate_client( $client_id );
		if ( ! $canonical_client_id ) {
			$this->send_error( 'invalid_client', 'Invalid client_id' );
			return;
		}
		$client_id = $canonical_client_id;

		// 4C: Specific error for missing redirect_uri.
		if ( empty( $redirect_uri ) ) {
			$this->send_error( 'invalid_request', 'Missing redirect_uri parameter' );
			return;
		}

		if ( ! $this->oauth_server->validate_redirect_uri( $client_id, $redirect_uri ) ) {
			$this->send_error( 'invalid_request', 'Invalid redirect_uri' );
			return;
		}

		// 4C: Specific error for missing code_challenge.
		if ( empty( $code_challenge ) ) {
			$this->send_error( 'invalid_request', 'Missing code_challenge parameter (PKCE required)' );
			return;
		}

		if ( 'S256' !== $code_challenge_method ) {
			$this->send_error( 'invalid_request', 'PKCE required: code_challenge_method must be S256' );
			return;
		}

		$scopes = ! empty( $scope ) ? explode( ' ', $scope ) : array( 'read' );

		if ( ! $this->oauth_server->validate_scopes( $client_id, $scopes ) ) {
			$this->send_error( 'invalid_scope', 'Invalid scope requested' );
			return;
		}

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified inside handle_approval/handle_denial
		if ( isset( $_POST['goldtwmcp_oauth_approve'] ) ) {
			$this->handle_approval( $client_id, $redirect_uri, $code_challenge, $code_challenge_method, $scopes, $state );
			return;
		}

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified inside handle_denial
		if ( isset( $_POST['goldtwmcp_oauth_deny'] ) ) {
			$this->handle_denial( $redirect_uri, $state );
			return;
		}

		$this->show_consent_screen( $client_id, $redirect_uri, $response_type, $scope, $state, $code_challenge, $code_challenge_method, $scopes );
	}

	/**
	 * Handle user approval of the OAuth consent form.
	 *
	 * @param string $client_id Client identifier.
	 * @param string $redirect_uri Redirect URI.
	 * @param string $code_challenge PKCE code challenge.
	 * @param string $code_challenge_method PKCE challenge method.
	 * @param array  $scopes Approved scopes.
	 * @param string $state State parameter for CSRF protection.
	 * @return void
	 */
	private function handle_approval( $client_id, $redirect_uri, $code_challenge, $code_challenge_method, $scopes, $state ) {
		if ( ! is_user_logged_in() ) {
			$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
			wp_safe_redirect( wp_login_url( $request_uri ) );
			exit;
		}

		check_admin_referer( 'goldtwmcp_oauth_consent' );

		$user_id = get_current_user_id();

		$code = $this->oauth_server->create_authorization_code(
			$client_id,
			$user_id,
			$redirect_uri,
			$code_challenge,
			$code_challenge_method,
			$scopes
		);

		if ( is_wp_error( $code ) ) {
			$this->send_error( 'server_error', $code->get_error_message() );
			return;
		}

		if ( 'urn:ietf:wg:oauth:2.0:oob' === $redirect_uri ) {
			$this->show_oob_code( $code );
			return;
		}

		$redirect_url = add_query_arg(
			array(
				'code'  => $code,
				'state' => $state,
			),
			$redirect_uri
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Handle user denial of the OAuth consent form.
	 *
	 * @param string $redirect_uri Redirect URI.
	 * @param string $state State parameter for CSRF protection.
	 * @return void
	 */
	private function handle_denial( $redirect_uri, $state ) {
		check_admin_referer( 'goldtwmcp_oauth_consent' );

		if ( 'urn:ietf:wg:oauth:2.0:oob' === $redirect_uri ) {
			wp_die( 'Authorization denied', 'Access Denied', array( 'response' => 403 ) );
		}

		$redirect_url = add_query_arg(
			array(
				'error'             => 'access_denied',
				'error_description' => 'User denied authorization',
				'state'             => $state,
			),
			$redirect_uri
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Show the OAuth consent screen.
	 *
	 * @param string $client_id Client identifier.
	 * @param string $redirect_uri Redirect URI.
	 * @param string $response_type OAuth response type.
	 * @param string $scope Requested scope string.
	 * @param string $state State parameter.
	 * @param string $code_challenge PKCE code challenge.
	 * @param string $code_challenge_method PKCE challenge method.
	 * @param array  $scopes Parsed scopes array.
	 * @return void
	 */
	private function show_consent_screen( $client_id, $redirect_uri, $response_type, $scope, $state, $code_challenge, $code_challenge_method, $scopes ) {
		if ( ! is_user_logged_in() ) {
			$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
			wp_safe_redirect( wp_login_url( $request_uri ) );
			exit;
		}

		$this->enqueue_oauth_assets();

		global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- OAuth client data, not cached
		$client = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}goldtwmcp_oauth_clients WHERE client_id = %s",
				$client_id
			)
		);

		include GOLDTWMCP_PATH . 'includes/oauth/views/consent-screen.php';
		exit;
	}

	/**
	 * Show OOB authorization code page.
	 *
	 * @param string $code Authorization code to display.
	 * @return void
	 */
	private function show_oob_code( $code ) {
		$this->enqueue_oauth_assets();
		include GOLDTWMCP_PATH . 'includes/oauth/views/oob-code.php';
		exit;
	}

	/**
	 * Enqueue OAuth CSS and JavaScript assets.
	 *
	 * @return void
	 */
	private function enqueue_oauth_assets() {
		wp_enqueue_style(
			'goldtwmcp-oauth',
			GOLDTWMCP_URL . 'assets/css/oauth.css',
			array(),
			GOLDTWMCP_VERSION
		);

		wp_enqueue_script(
			'goldtwmcp-oauth',
			GOLDTWMCP_URL . 'assets/js/oauth.js',
			array(),
			GOLDTWMCP_VERSION,
			true
		);
	}

	/**
	 * Send an OAuth error response.
	 *
	 * @param string $error Error code.
	 * @param string $description Human-readable error description.
	 * @return void
	 */
	private function send_error( $error, $description ) {
		wp_die(
			esc_html( $description ),
			esc_html( ucfirst( str_replace( '_', ' ', $error ) ) ),
			array( 'response' => 400 )
		);
	}
}
