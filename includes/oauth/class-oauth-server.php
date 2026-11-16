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
	 * @param string $source Issue source for the token registry (oauth|refresh|generator).
	 * @return array|\WP_Error Token data or error.
	 */
	public function create_access_token( $client_id, $user_id, $scopes, $source = 'oauth' ) {
		global $wpdb;

		$now                            = time();
		$token                          = 'xfa_' . $this->generate_token( 64 );
		$refresh_token                  = 'xfr_' . $this->generate_token( 64 );
		$expires_at_ts                  = $now + $this->default_token_lifetime;
		$refresh_token_expires_at_ts    = $now + $this->default_refresh_token_lifetime;
		$expires_at                     = gmdate( 'Y-m-d H:i:s', $expires_at_ts );
		$refresh_token_expires_at_mysql = gmdate( 'Y-m-d H:i:s', $refresh_token_expires_at_ts );

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
				'refresh_token_expires_at' => $refresh_token_expires_at_mysql,
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return new \WP_Error( 'db_error', 'Failed to create access token' );
		}

		Token_Registry::register(
			$token,
			(int) $user_id,
			(string) $client_id,
			is_array( $scopes ) ? $scopes : array(),
			$now,
			$expires_at_ts,
			$source
		);

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

		if ( Token_Registry::is_revoked( $token ) ) {
			return new \WP_Error( 'invalid_token', 'Token has been revoked' );
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
			// Grace window: the refresh token was retired by a refresh a moment ago.
			$replay = $this->refresh_grace_replay( $token_data );
			if ( null !== $replay ) {
				return $replay;
			}
			return new \WP_Error( 'invalid_grant', 'Refresh token has been revoked' );
		}

		if ( strtotime( $token_data->refresh_token_expires_at . ' UTC' ) < time() ) {
			return new \WP_Error( 'invalid_grant', 'Refresh token expired' );
		}

		// Retire the old pair. Atomic: of two concurrent refreshes with the same
		// refresh token, exactly one wins and mints the new pair; the other one
		// gets that same pair through the grace window below.
		$now_mysql = gmdate( 'Y-m-d H:i:s' );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- OAuth token rotation (atomic claim).
		$claimed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}goldtwmcp_oauth_tokens SET revoked_at = %s, rotated_at = %s WHERE id = %d AND revoked_at IS NULL",
				$now_mysql,
				$now_mysql,
				(int) $token_data->id
			)
		);

		if ( 1 !== (int) $claimed ) {
			return $this->wait_for_concurrent_rotation( (int) $token_data->id );
		}

		Token_Registry::revoke( $token_data->token );

		// Create new access token and refresh token, marked as a refresh.
		$new_token = $this->create_access_token(
			$token_data->client_id,
			$token_data->user_id,
			json_decode( $token_data->scopes, true ),
			'refresh'
		);

		if ( is_array( $new_token ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- link the retired row to its successor (grace window).
			$new_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}goldtwmcp_oauth_tokens WHERE token = %s",
					$new_token['access_token']
				)
			);
			if ( $new_id > 0 ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- link the retired row to its successor (grace window).
				$wpdb->update(
					"{$wpdb->prefix}goldtwmcp_oauth_tokens",
					array( 'rotated_to_id' => $new_id ),
					array( 'id' => (int) $token_data->id ),
					array( '%d' ),
					array( '%d' )
				);
			}
		}

		return $new_token;
	}

	/**
	 * Seconds during which a just-rotated refresh token still returns the pair
	 * that replaced it (default 60, filter `goldtwmcp_refresh_grace_seconds`,
	 * 0 disables, at most 300).
	 *
	 * @return int
	 */
	public static function refresh_grace_seconds() {
		/**
		 * Grace window for a repeated refresh with a just-rotated refresh token.
		 *
		 * @param int $seconds Default 60.
		 */
		$seconds = (int) \apply_filters( 'goldtwmcp_refresh_grace_seconds', 60 );
		return max( 0, min( 300, $seconds ) );
	}

	/**
	 * Grace window for refresh-token rotation.
	 *
	 * When a client repeats a refresh with the refresh token it has just used
	 * (the response was lost, a retry, two workers sharing one credential), it
	 * gets back the SAME new pair the first refresh produced — never a second
	 * one — provided:
	 *  - the old row was retired by a refresh (not by a revoke) at most
	 *    refresh_grace_seconds() ago,
	 *  - its successor still exists, belongs to the same user and client, is not
	 *    revoked and its access token has not expired.
	 * Anything else returns null (the caller answers invalid_grant). A revoke of
	 * the new pair therefore also ends the grace window at once.
	 *
	 * @param object $old Row of the retired token (goldtwmcp_oauth_tokens).
	 * @return array|null Token response, or null when no grace applies.
	 */
	private function refresh_grace_replay( $old ) {
		global $wpdb;

		$grace = self::refresh_grace_seconds();
		if ( $grace <= 0 || empty( $old->rotated_at ) || empty( $old->rotated_to_id ) ) {
			return null;
		}
		$rotated = strtotime( $old->rotated_at . ' UTC' );
		if ( false === $rotated || ( time() - $rotated ) > $grace ) {
			return null;
		}

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- grace-window lookup of the successor pair.
		$next = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}goldtwmcp_oauth_tokens WHERE id = %d",
				(int) $old->rotated_to_id
			)
		);
		if ( ! $next || null !== $next->revoked_at ) {
			return null;
		}
		if ( (string) $next->client_id !== (string) $old->client_id || (int) $next->user_id !== (int) $old->user_id ) {
			return null;
		}
		$now        = time();
		$expires    = strtotime( $next->expires_at . ' UTC' );
		$rt_expires = strtotime( (string) $next->refresh_token_expires_at . ' UTC' );
		if ( false === $expires || $expires <= $now || Token_Registry::is_revoked( $next->token ) ) {
			return null;
		}

		$scopes = json_decode( (string) $next->scopes, true );
		$scopes = is_array( $scopes ) ? $scopes : array();

		return array(
			'access_token'             => $next->token,
			'token_type'               => 'Bearer',
			'expires_in'               => $expires - $now,
			'refresh_token'            => $next->refresh_token,
			'refresh_token_expires_in' => false === $rt_expires ? 0 : max( 0, $rt_expires - $now ),
			'scope'                    => implode( ' ', $scopes ),
		);
	}

	/**
	 * A concurrent refresh claimed this row first: wait (up to ~2 s) until it
	 * has linked its new pair, then return that pair through the grace window.
	 *
	 * @param int $old_id Row id of the refresh token being exchanged.
	 * @return array|\WP_Error
	 */
	private function wait_for_concurrent_rotation( $old_id ) {
		global $wpdb;

		for ( $i = 0; $i < 20; $i++ ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- must read the live row written by the concurrent request.
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}goldtwmcp_oauth_tokens WHERE id = %d",
					(int) $old_id
				)
			);
			if ( ! $row || empty( $row->rotated_at ) ) {
				break; // Revoked (not rotated) meanwhile.
			}
			if ( ! empty( $row->rotated_to_id ) ) {
				$replay = $this->refresh_grace_replay( $row );
				if ( null !== $replay ) {
					return $replay;
				}
				break;
			}
			usleep( 100000 );
		}

		return new \WP_Error( 'invalid_grant', 'Refresh token has been revoked' );
	}

	/**
	 * Revoke a token pair (RFC 7009).
	 *
	 * $token may be the access token OR the refresh token of a pair; either one
	 * revokes the whole row, so neither half keeps working. $token_type_hint
	 * ('access_token' | 'refresh_token') only changes which column is tried
	 * first. Tokens are stored as-is in this plugin (there is no hash column),
	 * so the lookup is a direct match on the token / refresh_token columns.
	 *
	 * @param string      $token           Access or refresh token to revoke.
	 * @param string|null $token_type_hint Optional lookup hint.
	 * @return bool True if a row was revoked by this call.
	 */
	public function revoke_token( $token, $token_type_hint = null ) {
		global $wpdb;

		$token = (string) $token;
		if ( '' === $token ) {
			return false;
		}

		$columns = array( 'token', 'refresh_token' );
		if ( 'refresh_token' === $token_type_hint ) {
			$columns = array_reverse( $columns );
		}

		$row = null;
		foreach ( $columns as $column ) {
			if ( 'refresh_token' === $column ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- OAuth token revocation lookup, must hit the DB.
				$row = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT id, token, revoked_at FROM {$wpdb->prefix}goldtwmcp_oauth_tokens WHERE refresh_token = %s",
						$token
					)
				);
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- OAuth token revocation lookup, must hit the DB.
				$row = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT id, token, revoked_at FROM {$wpdb->prefix}goldtwmcp_oauth_tokens WHERE token = %s",
						$token
					)
				);
			}
			if ( $row ) {
				break;
			}
		}

		if ( ! $row ) {
			return false;
		}

		$updated = 0;
		if ( null === $row->revoked_at ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- OAuth token revocation.
			$updated = $wpdb->update(
				"{$wpdb->prefix}goldtwmcp_oauth_tokens",
				array( 'revoked_at' => gmdate( 'Y-m-d H:i:s' ) ),
				array( 'id' => (int) $row->id ),
				array( '%s' ),
				array( '%d' )
			);
		}

		// Keep the token registry (admin "Token Registry" + "My AI Tokens") in step.
		// It is keyed by the access token's prefix, so always pass the access token.
		$revoked_by = get_current_user_id();
		Token_Registry::revoke( (string) $row->token, $revoked_by ? $revoked_by : null );

		// Token validation always reads the DB (no transient / object-cache layer
		// for tokens), so the revoke is effective on the very next request.

		/**
		 * Fires after an OAuth token pair was revoked via the RFC 7009 path.
		 *
		 * @param int    $token_row_id Row ID in {prefix}goldtwmcp_oauth_tokens.
		 * @param string $prefix       16-char access-token prefix (never the full secret).
		 */
		do_action( 'goldtwmcp_token_revoked', (int) $row->id, Token_Registry::prefix( (string) $row->token ) );

		return $updated > 0;
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

		// Try normalized (strip suffixes like -ai, _client, etc.).
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

		// Try adding common suffixes (e.g. 'claude' → 'claude-ai').
		$base    = $this->normalize_client_id( $client_id );
		$try_ids = array( $base . '-ai', $base . '_ai', $base . '-app', $base . '_app' );
		foreach ( $try_ids as $try_id ) {
			if ( $try_id === $client_id ) {
				continue;
			}
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- OAuth client fuzzy suffix lookup
			$client = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT client_id FROM {$wpdb->prefix}goldtwmcp_oauth_clients WHERE client_id = %s",
					$try_id
				)
			);
			if ( null !== $client ) {
				return $try_id;
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
		$suffixes   = array( '_client', '_ai', '_app', '_bot', '_agent', '-client', '-ai', '-app', '-bot', '-agent' );
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
