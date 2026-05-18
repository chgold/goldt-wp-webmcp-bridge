<?php
/**
 * Token registry service class file.
 *
 * The token registry is a sidecar table that records metadata about every
 * issued/refreshed access token without storing the full secret. We only
 * persist the first 16 characters of the token (the prefix) so the table is
 * safe to expose to administrators for inventory and revocation purposes.
 *
 * @package GoldtWebMCP
 */

namespace GoldtWebMCP\OAuth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Token Registry service.
 *
 * Provides register/touch/revoke/list operations for the
 * {prefix}aiconnect_token_registry table.
 *
 * @package GoldtWebMCP
 */
class Token_Registry {

	/**
	 * Length of the token prefix that is stored in the registry.
	 *
	 * @var int
	 */
	const PREFIX_LENGTH = 16;

	/**
	 * Allowed values for the `source` column.
	 *
	 * @var string[]
	 */
	const SOURCES = array( 'generator', 'oauth', 'refresh' );

	/**
	 * Get the fully qualified registry table name.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'aiconnect_token_registry';
	}

	/**
	 * Compute the registry prefix for a raw token.
	 *
	 * @param string $token Full token string.
	 * @return string Empty string if the token cannot be prefixed.
	 */
	public static function prefix( $token ) {
		if ( ! is_string( $token ) || '' === $token ) {
			return '';
		}
		return (string) substr( $token, 0, self::PREFIX_LENGTH );
	}

	/**
	 * Best-effort lookup of the request IP address.
	 *
	 * @return string|null
	 */
	private static function get_request_ip() {
		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
		}
		return null;
	}

	/**
	 * Record a freshly issued token in the registry.
	 *
	 * Silent on failure — registry writes must never break token issuance.
	 *
	 * @param string $token       Full access token (only the prefix is stored).
	 * @param int    $user_id     Owning WordPress user ID.
	 * @param string $client_id   OAuth client identifier.
	 * @param array  $scopes      Granted scopes.
	 * @param int    $issued_at   Unix timestamp when the token was issued.
	 * @param int    $expires_at  Unix timestamp when the token expires.
	 * @param string $source      Issue source — one of: generator, oauth, refresh.
	 * @return bool True on success, false on failure.
	 */
	public static function register( $token, $user_id, $client_id, $scopes, $issued_at, $expires_at, $source = 'oauth' ) {
		global $wpdb;

		$prefix = self::prefix( $token );
		if ( '' === $prefix ) {
			return false;
		}

		if ( ! in_array( $source, self::SOURCES, true ) ) {
			$source = 'oauth';
		}

		$scope_string = is_array( $scopes ) ? implode( ' ', array_map( 'strval', $scopes ) ) : (string) $scopes;
		if ( strlen( $scope_string ) > 255 ) {
			$scope_string = substr( $scope_string, 0, 255 );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Sidecar registry insert.
		$result = $wpdb->insert(
			self::table_name(),
			array(
				'token_prefix' => $prefix,
				'user_id'      => (int) $user_id,
				'client_id'    => (string) $client_id,
				'scope'        => $scope_string,
				'issued_at'    => (int) $issued_at,
				'expires_at'   => (int) $expires_at,
				'last_used_at' => null,
				'revoked_at'   => null,
				'revoked_by'   => null,
				'source'       => $source,
				'ip_address'   => self::get_request_ip(),
			),
			array( '%s', '%d', '%s', '%s', '%d', '%d', null, null, null, '%s', '%s' )
		);

		return false !== $result;
	}

	/**
	 * Update `last_used_at` for an active registry row matching the token prefix.
	 *
	 * Silent on failure.
	 *
	 * @param string $token Full access token.
	 * @return void
	 */
	public static function touch( $token ) {
		global $wpdb;

		$prefix = self::prefix( $token );
		if ( '' === $prefix ) {
			return;
		}

		$now   = time();
		$table = self::table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$table}` SET last_used_at = %d WHERE token_prefix = %s AND revoked_at IS NULL AND expires_at > %d",
				$now,
				$prefix,
				$now
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Soft-delete (revoke) a token in the registry.
	 *
	 * Silent on failure — does not throw even if the row is missing.
	 *
	 * @param string   $token       Full access token.
	 * @param int|null $revoked_by  Acting user ID (admin/CLI). Null for self-revoke.
	 * @return void
	 */
	public static function revoke( $token, $revoked_by = null ) {
		global $wpdb;

		$prefix = self::prefix( $token );
		if ( '' === $prefix ) {
			return;
		}

		$data    = array( 'revoked_at' => time() );
		$formats = array( '%d' );
		if ( null !== $revoked_by ) {
			$data['revoked_by'] = (int) $revoked_by;
			$formats[]          = '%d';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Sidecar registry update, no caching needed for token operations.
		$wpdb->update(
			self::table_name(),
			$data,
			array(
				'token_prefix' => $prefix,
				'revoked_at'   => null,
			),
			$formats,
			array( '%s', null )
		);
	}

	/**
	 * Soft-delete a registry row by its numeric ID.
	 *
	 * @param int      $id          Registry row ID.
	 * @param int|null $revoked_by  Acting user ID.
	 * @return bool True if a row was updated, false otherwise.
	 */
	public static function revoke_by_id( $id, $revoked_by = null ) {
		global $wpdb;

		$id = (int) $id;
		if ( $id <= 0 ) {
			return false;
		}

		$data    = array( 'revoked_at' => time() );
		$formats = array( '%d' );
		if ( null !== $revoked_by ) {
			$data['revoked_by'] = (int) $revoked_by;
			$formats[]          = '%d';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Sidecar registry update, no caching needed for token operations.
		$updated = $wpdb->update(
			self::table_name(),
			$data,
			array(
				'id'         => $id,
				'revoked_at' => null,
			),
			$formats,
			array( '%d', null )
		);

		return is_int( $updated ) && $updated > 0;
	}

	/**
	 * Check whether a token is active in the registry (registered, not revoked, not expired).
	 *
	 * @param string $token Full access token.
	 * @return bool
	 */
	public static function is_active( $token ) {
		global $wpdb;

		$prefix = self::prefix( $token );
		if ( '' === $prefix ) {
			return false;
		}

		$table = self::table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM `{$table}` WHERE token_prefix = %s AND revoked_at IS NULL AND expires_at > %d LIMIT 1",
				$prefix,
				time()
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return ! empty( $row );
	}

	/**
	 * Check whether a registry row was revoked for the given token prefix.
	 *
	 * A "true" return means the token *was* registered and is now revoked.
	 * "false" means the token is either not in the registry, expired by time,
	 * or still active — callers must combine this with their normal validation.
	 *
	 * @param string $token Full access token.
	 * @return bool
	 */
	public static function is_revoked( $token ) {
		global $wpdb;

		$prefix = self::prefix( $token );
		if ( '' === $prefix ) {
			return false;
		}

		$table = self::table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM `{$table}` WHERE token_prefix = %s AND revoked_at IS NOT NULL LIMIT 1",
				$prefix
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return ! empty( $row );
	}

	/**
	 * List registry rows with optional filters.
	 *
	 * @param array $args {
	 *     Optional filters.
	 *
	 *     @type string $status   'active' (default), 'revoked', or 'all'.
	 *     @type int    $user_id  Filter by user ID.
	 *     @type int    $limit    Max rows (default 100, max 500).
	 *     @type int    $offset   Pagination offset.
	 * }
	 * @return array<int, array<string, mixed>>
	 */
	public static function list( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'status'  => 'active',
			'user_id' => 0,
			'limit'   => 100,
			'offset'  => 0,
		);
		$args     = array_merge( $defaults, $args );

		$limit  = max( 1, min( 500, (int) $args['limit'] ) );
		$offset = max( 0, (int) $args['offset'] );

		$where  = array( '1=1' );
		$values = array();

		switch ( $args['status'] ) {
			case 'revoked':
				$where[] = 'revoked_at IS NOT NULL';
				break;
			case 'all':
				break;
			case 'active':
			default:
				$where[]  = 'revoked_at IS NULL';
				$where[]  = 'expires_at > %d';
				$values[] = time();
				break;
		}

		if ( (int) $args['user_id'] > 0 ) {
			$where[]  = 'user_id = %d';
			$values[] = (int) $args['user_id'];
		}

		$where_sql = implode( ' AND ', $where );
		$table     = self::table_name();

		$values[] = $limit;
		$values[] = $offset;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Where clause built from whitelisted fragments, values bound via prepare.
		$rows = $wpdb->get_results(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $values is a dynamic array built from optional filters; count matches at runtime.
				"SELECT * FROM `{$table}` WHERE {$where_sql} ORDER BY issued_at DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$values
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Get a registry row by its numeric ID.
	 *
	 * @param int $id Row ID.
	 * @return array<string, mixed>|null
	 */
	public static function get( $id ) {
		global $wpdb;

		$id = (int) $id;
		if ( $id <= 0 ) {
			return null;
		}

		$table = self::table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE id = %d",
				$id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $row ) ? $row : null;
	}
}
