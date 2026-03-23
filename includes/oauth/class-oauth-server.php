<?php
/**
 * OAuth server class file.
 *
 * @package GoldtWebMCP
 */

namespace GoldtWebMCP\OAuth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OAuth 2.0 Authorization Server with PKCE support.
 *
 * @package GoldtWebMCP
 */
class OAuth_Server {

	/**
	 * Default access token lifetime in seconds (1 hour).
	 *
	 * @var int
	 */
	private $default_token_lifetime = 3600;

	/**
	 * Default authorization code lifetime in seconds (10 minutes).
	 *
	 * @var int
	 */
	private $default_code_lifetime = 600;

	/**
	 * Default refresh token lifetime in seconds (30 days).
	 *
	 * @var int
	 */
	private $default_refresh_token_lifetime = 2592000;

	/**
	 * Generate authorization code.
	 *
	 * @param string $client_id Client identifier.
	 * @param int    $user_id WordPress user ID.
	 * @param string $redirect_uri Redirect URI.
	 * @param string $code_challenge PKCE code challenge.
	 * @param string $code_challenge_method PKCE challenge method.
	 * @param array  $scopes Requested scopes.
	 * @return string|\WP_Error Authorization code or error.
	 */
	public function create_authorization_code( $client_id, $user_id, $redirect_uri, $code_challenge, $code_challenge_method, $scopes ) {
		global $wpdb;

		$code       = $this->generate_token( 128 );
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + $this->default_code_lifetime );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- OAuth authorization code generation
		$inserted = $wpdb->insert(
			"{$wpdb->prefix}goldtwmcp_oauth_codes",
			array(
				'code'                  => $code,
				'client_id'             => $client_id,
				'user_id'               => $user_id,
				'redirect_uri'          => $redirect_uri,
				'code_challenge'        => $code_challenge,
				'code_challenge_method' => $code_challenge_method,
				'scopes'                => wp_json_encode( $scopes ),
				'expires_at'            => $expires_at,
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return new \WP_Error( 'db_error', 'Failed to create authorization code' );
		}

		return $code;
	}

	/**
	 * Exchange authorization code for access token.
	 *
	 * @param string $code Authorization code.
	 * @param string $client_id Client identifier.
	 * @param string $code_verifier PKCE code verifier.
	 * @param string $redirect_uri Redirect URI.
	 * @return array|\WP_Error Token data or error.
	 */
	public function exchange_code_for_token( $code, $client_id, $code_verifier, $redirect_uri ) {
		global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- OAuth code exchange
		$auth_code = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}goldtwmcp_oauth_codes WHERE code = %s",
				$code
			)
		);

		if ( ! $auth_code ) {
			return new \WP_Error( 'invalid_grant', 'Authorization code not found' );
		}

		if ( null !== $auth_code->used_at ) {
			return new \WP_Error( 'invalid_grant', 'Authorization code already used' );
		}

		if ( strtotime( $auth_code->expires_at . ' UTC' ) < time() ) {
			return new \WP_Error( 'invalid_grant', 'Authorization code expired' );
		}

		if ( $auth_code->client_id !== $client_id ) {
			return new \WP_Error( 'invalid_client', 'Client ID mismatch' );
		}

		if ( $auth_code->redirect_uri !== $redirect_uri ) {
			return new \WP_Error( 'invalid_grant', 'Redirect URI mismatch' );
		}

		if ( ! $this->verify_pkce( $code_verifier, $auth_code->code_challenge, $auth_code->code_challenge_method ) ) {
			return new \WP_Error( 'invalid_grant', 'PKCE verification failed' );
		}

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Mark OAuth code as used
		$wpdb->update(
			"{$wpdb->prefix}goldtwmcp_oauth_codes",
			array( 'used_at' => gmdate( 'Y-m-d H:i:s' ) ),
			array( 'id' => $auth_code->id ),
			array( '%s' ),
			array( '%d' )
		);

		$token = $this->create_access_token(
			$auth_code->client_id,
			$auth_code->user_id,
			json_decode( $auth_code->scopes, true )
		);

		return $token;
	}

	/**
	 * Create access token with refresh token.
	 *
	 * @param string $client_id Client identifier.
	 * @param int    $user_id WordPress user ID.
	 * @param array  $scopes Granted scopes.
	 * @return array|\WP_Error Token data or error.
	 */
	public function create_access_token( $client_id, $user_id, $scopes ) {
		global $wpdb;

		$token                    = 'wpc_' . $this->generate_token( 64 );
		$refresh_token            = 'wpr_' . $this->generate_token( 64 );
		$expires_at               = gmdate( 'Y-m-d H:i:s', time() + $this->default_token_lifetime );
		$refresh_token_expires_at = gmdate( 'Y-m-d H:i:s', time() + $this->default_refresh_token_lifetime );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- OAuth access token creation
		$inserted = $wpdb->insert(
			"{$wpdb->prefix}goldtwmcp_oauth_tokens",
			array(
				'token'                    => $token,
				'refresh_token'            => $refresh_token,
				'client_id'                => $client_id,
				'user_id'                  => $user_id,
				'scopes'                   => wp_json_encode( $scopes ),
				'expires_at'               => $expires_at,
				'refresh_token_expires_at' => $refresh_token_expires_at,
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return new \WP_Error( 'db_error', 'Failed to create access token' );
		}

		return array(
			'access_token'             => $token,
			'token_type'               => 'Bearer',
			'expires_in'               => $this->default_token_lifetime,
			'refresh_token'            => $refresh_token,
			'refresh_token_expires_in' => $this->default_refresh_token_lifetime,
			'scope'                    => implode( ' ', $scopes ),
		);
	}

	/**
	 * Validate access token.
	 *
	 * @param string $token Access token string.
	 * @return array|\WP_Error Token data array or error.
	 */
	public function validate_token( $token ) {
		global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- OAuth token validation
		$token_data = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}goldtwmcp_oauth_tokens WHERE token = %s",
				$token
			)
		);

		if ( ! $token_data ) {
			return new \WP_Error( 'invalid_token', 'Token not found' );
		}

		if ( null !== $token_data->revoked_at ) {
			return new \WP_Error( 'invalid_token', 'Token has been revoked' );
		}

		if ( strtotime( $token_data->expires_at . ' UTC' ) < time() ) {
			return new \WP_Error( 'invalid_token', 'Token expired' );
		}

		return array(
			'user_id'   => $token_data->user_id,
			'client_id' => $token_data->client_id,
			'scopes'    => json_decode( $token_data->scopes, true ),
		);
	}

	/**
	 * Exchange refresh token for new access token.
	 *
	 * @param string $refresh_token Refresh token string.
	 * @param string $client_id Client identifier.
	 * @return array|\WP_Error New token data or error.
	 */
	public function exchange_refresh_token( $refresh_token, $client_id ) {
		global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- OAuth refresh token exchange
		$token_data = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}goldtwmcp_oauth_tokens WHERE refresh_token = %s",
				$refresh_token
			)
		);

		if ( ! $token_data ) {
			return new \WP_Error( 'invalid_grant', 'Refresh token not found' );
		}

		if ( $token_data->client_id !== $client_id ) {
			return new \WP_Error( 'invalid_client', 'Client ID mismatch' );
		}

		if ( null !== $token_data->revoked_at ) {
			return new \WP_Error( 'invalid_grant', 'Refresh token has been revoked' );
		}

		if ( strtotime( $token_data->refresh_token_expires_at . ' UTC' ) < time() ) {
			return new \WP_Error( 'invalid_grant', 'Refresh token expired' );
		}

		// Revoke the old access token and refresh token.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- OAuth token revocation
		$wpdb->update(
			"{$wpdb->prefix}goldtwmcp_oauth_tokens",
			array( 'revoked_at' => gmdate( 'Y-m-d H:i:s' ) ),
			array( 'id' => $token_data->id ),
			array( '%s' ),
			array( '%d' )
		);

		// Create new access token and refresh token.
		$new_token = $this->create_access_token(
			$token_data->client_id,
			$token_data->user_id,
			json_decode( $token_data->scopes, true )
		);

		return $new_token;
	}

	/**
	 * Revoke access token.
	 *
	 * @param string $token Access token to revoke.
	 * @return bool True if revoked successfully.
	 */
	public function revoke_token( $token ) {
		global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- OAuth token revocation
		$updated = $wpdb->update(
			"{$wpdb->prefix}goldtwmcp_oauth_tokens",
			array( 'revoked_at' => gmdate( 'Y-m-d H:i:s' ) ),
			array( 'token' => $token ),
			array( '%s' ),
			array( '%s' )
		);

		return false !== $updated;
	}

	/**
	 * Verify PKCE code challenge.
	 *
	 * @param string $code_verifier Code verifier from the client.
	 * @param string $code_challenge Code challenge stored during authorization.
	 * @param string $method Challenge method (must be S256).
	 * @return bool
	 */
	private function verify_pkce( $code_verifier, $code_challenge, $method ) {
		if ( 'S256' !== $method ) {
			return false;
		}

		$computed_challenge = $this->base64url_encode(
			hash( 'sha256', $code_verifier, true )
		);

		return hash_equals( $code_challenge, $computed_challenge );
	}

	/**
	 * Validate client and return canonical client_id, or false if not found.
	 *
	 * Tries exact match first, then a normalized match (strips common AI suffixes).
	 *
	 * @param string $client_id Client ID to validate.
	 * @return string|false Canonical client_id on success, false on failure.
	 */
	public function validate_client( $client_id ) {
		global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- OAuth client validation
		$client = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT client_id FROM {$wpdb->prefix}goldtwmcp_oauth_clients WHERE client_id = %s",
				$client_id
			)
		);

		if ( null !== $client ) {
			return $client_id;
		}

		$normalized = $this->normalize_client_id( $client_id );
		if ( $normalized !== $client_id ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- OAuth client fuzzy lookup
			$client = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT client_id FROM {$wpdb->prefix}goldtwmcp_oauth_clients WHERE client_id = %s",
					$normalized
				)
			);
			if ( null !== $client ) {
				return $normalized;
			}
		}

		return false;
	}

	/**
	 * Normalize a client_id by stripping common AI-related suffixes.
	 *
	 * @param string $client_id Raw client_id from the request.
	 * @return string Normalized client_id.
	 */
	private function normalize_client_id( $client_id ) {
		$suffixes   = array( '_client', '_ai', '_app', '_bot', '_agent' );
		$normalized = strtolower( trim( $client_id ) );
		foreach ( $suffixes as $suffix ) {
			if ( substr( $normalized, -strlen( $suffix ) ) === $suffix ) {
				$normalized = substr( $normalized, 0, -strlen( $suffix ) );
				break;
			}
		}
		return $normalized;
	}

	/**
	 * Validate redirect URI.
	 *
	 * @param string $client_id Client identifier.
	 * @param string $redirect_uri Redirect URI to validate.
	 * @return bool
	 */
	public function validate_redirect_uri( $client_id, $redirect_uri ) {
		global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- OAuth redirect URI validation
		$client = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT redirect_uris FROM {$wpdb->prefix}goldtwmcp_oauth_clients WHERE client_id = %s",
				$client_id
			)
		);

		if ( ! $client ) {
			return false;
		}

		$allowed_uris = json_decode( $client->redirect_uris, true );
		return in_array( $redirect_uri, $allowed_uris, true );
	}

	/**
	 * Validate scopes.
	 *
	 * @param string $client_id Client identifier.
	 * @param array  $requested_scopes Scopes being requested.
	 * @return bool
	 */
	public function validate_scopes( $client_id, $requested_scopes ) {
		global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- OAuth scope validation
		$client = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT allowed_scopes FROM {$wpdb->prefix}goldtwmcp_oauth_clients WHERE client_id = %s",
				$client_id
			)
		);

		if ( ! $client ) {
			return false;
		}

		$allowed_scopes = json_decode( $client->allowed_scopes, true );

		foreach ( $requested_scopes as $scope ) {
			if ( ! in_array( $scope, $allowed_scopes, true ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Generate random token.
	 *
	 * @param int $length Token length in hex characters.
	 * @return string
	 */
	private function generate_token( $length = 64 ) {
		return bin2hex( random_bytes( $length / 2 ) );
	}

	/**
	 * Base64 URL encoding (RFC 7636).
	 *
	 * @param string $data Binary data to encode.
	 * @return string
	 */
	private function base64url_encode( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}
}
