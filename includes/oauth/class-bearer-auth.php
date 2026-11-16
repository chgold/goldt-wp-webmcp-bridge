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
	 * REST namespace prefix of this plugin's routes.
	 */
	const OWN_NAMESPACE = '/goldt-webmcp-bridge/';

	/**
	 * Routes inside our namespace that are for the user's own browser session only.
	 * An access token is never accepted there: a token could otherwise mint a new
	 * token with every scope its user is entitled to (generate-prompt), or list and
	 * revoke the user's other connections (my-tokens).
	 *
	 * @var string[]
	 */
	const HUMAN_ONLY_ROUTES = array(
		'/goldt-webmcp-bridge/v1/generate-prompt',
		'/goldt-webmcp-bridge/v1/my-tokens',
	);

	/**
	 * The REST route this HTTP request addresses, or '' when it is not a REST request.
	 *
	 * Mirrors how WordPress itself picks the route: a `rest_route` query/body
	 * variable wins over the pretty-permalink path (WP::parse_request), so a request
	 * like /wp-json/goldt-webmcp-bridge/v1/manifest?rest_route=/wp/v2/users/me is
	 * treated as the core route it really dispatches to. Once WordPress has parsed
	 * the request, its own query var is used (authoritative).
	 *
	 * @return string Route starting with '/', or ''.
	 */
	public static function current_rest_route() {
		if ( isset( $GLOBALS['wp'] ) && is_object( $GLOBALS['wp'] ) && ! empty( $GLOBALS['wp']->query_vars['rest_route'] ) ) {
			return '/' . ltrim( (string) $GLOBALS['wp']->query_vars['rest_route'], '/' );
		}
		// phpcs:disable WordPress.Security.NonceVerification -- route detection only, no state change.
		foreach ( array( $_POST, $_GET ) as $source ) {
			if ( isset( $source['rest_route'] ) && is_string( $source['rest_route'] ) ) {
				return '/' . ltrim( sanitize_text_field( wp_unslash( $source['rest_route'] ) ), '/' );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only parsed, never output or stored.
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$path = rawurldecode( $path );
		$pref = trim( \rest_get_url_prefix(), '/' ) . '/';
		// Anchored at the start of the path (after the site's base path): a REST
		// prefix further along the path — e.g. /wp-admin/admin-ajax.php/wp-json/…
		// via PATH_INFO — is not a REST request.
		$bases = array_unique(
			array(
				trailingslashit( (string) wp_parse_url( \home_url( '/' ), PHP_URL_PATH ) ),
				trailingslashit( (string) wp_parse_url( \site_url( '/' ), PHP_URL_PATH ) ),
				'/',
			)
		);
		foreach ( $bases as $base ) {
			$full = $base . $pref;
			if ( 0 === strpos( $path, $full ) ) {
				return '/' . ltrim( substr( $path, strlen( $full ) ), '/' );
			}
		}
		return '';
	}

	/**
	 * May an AI Connect access token authenticate a request to this route?
	 *
	 * Yes only for this plugin's own agent routes (tools, confirmation status,
	 * public OAuth/manifest routes). Never for core / other plugins' REST routes,
	 * and never for the human-only routes above. A site owner who really wants an
	 * agent token to reach a further route lists its prefix in the
	 * `goldtwmcp_bearer_allowed_routes` filter (default: none).
	 *
	 * @param string $route REST route ('' = not a REST request).
	 * @return bool
	 */
	public static function token_allowed_for_route( $route ) {
		$route = (string) $route;
		if ( '' === $route || false !== strpos( $route, '..' ) || false !== strpos( $route, '//' ) ) {
			return false;
		}
		foreach ( self::HUMAN_ONLY_ROUTES as $human ) {
			if ( $route === $human || 0 === strpos( $route, $human . '/' ) ) {
				return false;
			}
		}
		if ( 0 === strpos( $route, self::OWN_NAMESPACE ) ) {
			return true;
		}
		/**
		 * Extra REST route prefixes an AI Connect access token may authenticate.
		 *
		 * Default empty: the token works only on AI Connect's own routes, so an agent
		 * can do exactly what the plugin's tools and scopes allow — not everything the
		 * user could do through the core REST API. Example: array( '/wp/v2/media' ).
		 *
		 * @param string[] $prefixes Route prefixes (each starting with '/').
		 */
		$allowed = (array) \apply_filters( 'goldtwmcp_bearer_allowed_routes', array() );
		foreach ( $allowed as $prefix ) {
			$prefix = '/' . ltrim( (string) $prefix, '/' );
			if ( '/' !== $prefix && 0 === strpos( $route, $prefix ) ) {
				return true;
			}
		}
		return false;
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

		// SEC-20: the token is a key to AI Connect's tools only. It never logs the
		// user in on core REST routes, admin-ajax.php, admin-post.php or front-end
		// pages (the /api/aiconnect-tools handler authenticates on its own).
		if ( ! self::token_allowed_for_route( self::current_rest_route() ) ) {
			return $user_id;
		}

		$token_data = $this->oauth_server->validate_token( $token );

		if ( is_wp_error( $token_data ) ) {
			return $user_id;
		}

		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : null;
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : null;
		Token_Registry::touch( $token, $ip, $ua );

		// Lazy cleanup — best-effort, swallow all errors.
		try {
			if ( Token_Registry::should_run_cleanup() ) {
				Token_Registry::run_cleanup();
			}
		} catch ( \Throwable $e ) {
			unset( $e ); // Intentionally swallowed — cleanup must never break auth.
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

		$route = self::current_rest_route();

		// SEC-20: an AI Connect token sent to a route it is not valid for is refused
		// outright (instead of silently treating the caller as a guest), so a client
		// learns the token is scoped to the AI Connect tools. Tokens of other plugins
		// (which fail our validation) are left alone.
		$presented = $this->get_bearer_token();
		if ( $presented && ! self::token_allowed_for_route( $route ) && ! is_wp_error( $this->oauth_server->validate_token( $presented ) ) ) {
			return new \WP_Error(
				'goldtwmcp_token_route_not_allowed',
				'This AI Connect access token is valid only for the AI Connect tool endpoints of this site, not for ' . ( '' === $route ? 'this URL' : $route ) . '.',
				array( 'status' => 403 )
			);
		}

		if ( ! $this->is_goldtwmcp_request( $route ) ) {
			return $result;
		}

		// Public endpoints that don't require authentication.
		if ( $this->is_public_endpoint( $route ) ) {
			return $result;
		}

		// Cookie-authenticated user-facing endpoints (generate-prompt, my-tokens).
		// These use the WP REST nonce + same-origin session cookie; do not require Bearer
		// (and a Bearer token was refused above, so the user here comes from the cookie).
		if ( $this->is_user_facing_endpoint( $route ) ) {
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

		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : null;
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : null;
		Token_Registry::touch( $token, $ip, $ua );

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
	 * Is this a request to one of this plugin's REST routes?
	 *
	 * Decided on the route WordPress dispatches to — not on a substring of the raw
	 * URI, which a query string could fake.
	 *
	 * @param string|null $route REST route (null = detect).
	 * @return bool
	 */
	private function is_goldtwmcp_request( $route = null ) {
		$route = null === $route ? self::current_rest_route() : (string) $route;
		return 0 === strpos( $route, self::OWN_NAMESPACE );
	}

	/**
	 * Is this one of the public routes (token, revoke, manifest)?
	 *
	 * @param string|null $route REST route (null = detect).
	 * @return bool
	 */
	private function is_public_endpoint( $route = null ) {
		$route = null === $route ? self::current_rest_route() : (string) $route;
		return in_array(
			untrailingslashit( $route ),
			array(
				'/goldt-webmcp-bridge/v1/oauth/token',
				'/goldt-webmcp-bridge/v1/oauth/revoke',
				'/goldt-webmcp-bridge/v1/manifest',
			),
			true
		);
	}

	/**
	 * Endpoints meant for the logged-in user's browser session (not for AI agents).
	 *
	 * Bypasses the Bearer requirement; each endpoint's own permission_callback still
	 * enforces login, and WordPress's REST cookie check enforces the nonce.
	 *
	 * @param string|null $route REST route (null = detect).
	 * @return bool
	 */
	private function is_user_facing_endpoint( $route = null ) {
		$route = null === $route ? self::current_rest_route() : (string) $route;
		foreach ( self::HUMAN_ONLY_ROUTES as $human ) {
			if ( $route === $human || 0 === strpos( $route, $human . '/' ) ) {
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
	 * Authenticate the current request via Bearer token.
	 *
	 * Used by the Servio /api/aiconnect-tools handler which runs outside
	 * the WP REST infrastructure (so the rest_authentication_errors filter
	 * never fires). Validates the token and sets the WordPress user context
	 * so that current_user_can() works in tool handlers.
	 *
	 * @return true|\WP_Error True on success, WP_Error on failure.
	 */
	public function authenticate_request() {
		$token = $this->get_bearer_token();

		if ( ! $token ) {
			return new \WP_Error(
				'rest_not_logged_in',
				'Bearer token required.',
				array( 'status' => 401 )
			);
		}

		$token_data = $this->oauth_server->validate_token( $token );

		if ( \is_wp_error( $token_data ) ) {
			return new \WP_Error(
				'rest_invalid_token',
				$token_data->get_error_message(),
				array( 'status' => 401 )
			);
		}

		// Set the WordPress user context so current_user_can() works.
		\wp_set_current_user( $token_data['user_id'] );

		// Record token usage for the registry.
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : null;
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : null;
		Token_Registry::touch( $token, $ip, $ua );

		return true;
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
