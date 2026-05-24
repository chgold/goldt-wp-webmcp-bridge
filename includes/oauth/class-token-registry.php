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
	 * Update last_used_at, last_used_ip, and last_used_ua for an active registry row.
	 *
	 * Called on every successful bearer auth. Silent on failure — registry writes
	 * must never break user requests.
	 *
	 * @param string      $token Full access token.
	 * @param string|null $ip    Client IP address (IPv4 or IPv6, max 45 chars).
	 * @param string|null $ua    HTTP User-Agent string (truncated to 255 chars).
	 * @return void
	 */
	public static function touch( $token, $ip = null, $ua = null ) {
		global $wpdb;

		$prefix = self::prefix( $token );
		if ( '' === $prefix ) {
			return;
		}

		$now   = time();
		$table = self::table_name();

		// Sanitize ip: must be a valid IP (IPv4 or IPv6).
		if ( null !== $ip ) {
			$ip = (string) $ip;
			if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				$ip = null;
			}
		}

		// Sanitize ua: truncate to 255.
		if ( null !== $ua ) {
			$ua = substr( (string) $ua, 0, 255 );
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$table}` SET last_used_at = %d, last_used_ip = %s, last_used_ua = %s WHERE token_prefix = %s AND revoked_at IS NULL AND expires_at > %d",
				$now,
				$ip,
				$ua,
				$prefix,
				$now
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Cascade-revoke the matching rows in the OAuth tokens table.
	 *
	 * Matches by the 16-char prefix stored in LEFT(token, 16). This invalidates
	 * BOTH the access_token AND the refresh_token on the same row, so an AI agent
	 * cannot exchange its refresh_token for a new access_token after a revoke.
	 *
	 * Idempotent: WHERE revoked_at IS NULL so repeat calls are no-ops.
	 * Silent on failure — cascade errors must never surface to users.
	 *
	 * @param string[] $prefixes Array of 16-char token prefixes.
	 * @param int      $time     Unix timestamp to use as revoked_at value.
	 * @return void
	 */
	private static function cascade_revoke_oauth_rows( array $prefixes, $time ) {
		global $wpdb;

		if ( empty( $prefixes ) ) {
			return;
		}

		// Sanitize: only plausible-length strings.
		$prefixes = array_values(
			array_filter(
				$prefixes,
				function ( $p ) {
					return is_string( $p ) && strlen( $p ) >= 4 && strlen( $p ) <= 32;
				}
			)
		);

		if ( empty( $prefixes ) ) {
			return;
		}

		try {
			$oauth_table  = $wpdb->prefix . 'goldtwmcp_oauth_tokens';
			$now_mysql    = gmdate( 'Y-m-d H:i:s', (int) $time );
			$placeholders = implode( ', ', array_fill( 0, count( $prefixes ), '%s' ) );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Cascade revoke; table from $wpdb->prefix; prefixes sanitized.
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE `{$oauth_table}` SET revoked_at = %s WHERE revoked_at IS NULL AND LEFT(token, 16) IN ({$placeholders})",
					array_merge( array( $now_mysql ), $prefixes )
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		} catch ( \Throwable $e ) {
			// Best-effort: swallow all errors — cascade failures must never surface to users.
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Cascade error logging; non-blocking audit trail.
			error_log( 'Token_Registry::cascade_revoke_oauth_rows failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Soft-delete (revoke) a token in the registry and cascade to oauth_tokens.
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

		$time    = time();
		$data    = array( 'revoked_at' => $time );
		$formats = array( '%d' );
		if ( null !== $revoked_by ) {
			$data['revoked_by'] = (int) $revoked_by;
			$formats[]          = '%d';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Sidecar registry update, no caching needed for token operations.
		$affected = $wpdb->update(
			self::table_name(),
			$data,
			array(
				'token_prefix' => $prefix,
				'revoked_at'   => null,
			),
			$formats,
			array( '%s', null )
		);

		if ( $affected > 0 ) {
			self::cascade_revoke_oauth_rows( array( $prefix ), $time );
		}
	}

	/**
	 * Soft-delete a registry row by its numeric ID, with cascade to oauth_tokens.
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

		$table = self::table_name();

		// Fetch prefix for cascade before revoking.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$prefix = $wpdb->get_var(
			$wpdb->prepare( "SELECT token_prefix FROM `{$table}` WHERE id = %d", $id )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$time    = time();
		$data    = array( 'revoked_at' => $time );
		$formats = array( '%d' );
		if ( null !== $revoked_by ) {
			$data['revoked_by'] = (int) $revoked_by;
			$formats[]          = '%d';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Sidecar registry update, no caching needed for token operations.
		$updated = $wpdb->update(
			$table,
			$data,
			array(
				'id'         => $id,
				'revoked_at' => null,
			),
			$formats,
			array( '%d', null )
		);

		$ok = is_int( $updated ) && $updated > 0;
		if ( $ok && $prefix ) {
			self::cascade_revoke_oauth_rows( array( (string) $prefix ), $time );
		}

		return $ok;
	}

	/**
	 * Revoke all active tokens for a specific user, cascading to oauth_tokens.
	 *
	 * Used by the user-facing "Revoke all my tokens" action.
	 *
	 * @param int      $user_id    WordPress user ID.
	 * @param int|null $revoked_by Acting user (same user for self-revoke, admin ID for admin action).
	 * @return int Number of registry rows revoked.
	 */
	public static function revoke_all_for_user( $user_id, $revoked_by = null ) {
		global $wpdb;

		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return 0;
		}

		$table = self::table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$prefixes = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT token_prefix FROM `{$table}` WHERE user_id = %d AND revoked_at IS NULL",
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( empty( $prefixes ) ) {
			return 0;
		}

		$time = time();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk revoke for user.
		$affected = $wpdb->update(
			$table,
			array(
				'revoked_at' => $time,
				'revoked_by' => null !== $revoked_by ? (int) $revoked_by : 0,
			),
			array(
				'user_id'    => $user_id,
				'revoked_at' => null,
			),
			array( '%d', '%d' ),
			array( '%d', null )
		);

		$affected = is_int( $affected ) ? $affected : 0;

		if ( $affected > 0 ) {
			self::cascade_revoke_oauth_rows( $prefixes, $time );
		}

		return $affected;
	}

	/**
	 * Revoke a set of registry rows by their IDs, cascading to oauth_tokens.
	 *
	 * Used by the user-facing "Revoke all matching current filter" action.
	 *
	 * @param int[]    $ids        Array of registry row IDs to revoke.
	 * @param int|null $revoked_by Acting user ID.
	 * @return int Number of registry rows revoked.
	 */
	public static function revoke_by_ids( array $ids, $revoked_by = null ) {
		global $wpdb;

		if ( empty( $ids ) ) {
			return 0;
		}

		$table    = self::table_name();
		$ids_ints = array_map( 'intval', $ids );
		$ids_ints = array_filter(
			$ids_ints,
			function ( $id ) {
				return $id > 0;
			}
		);

		if ( empty( $ids_ints ) ) {
			return 0;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $ids_ints ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$prefixes = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT token_prefix FROM `{$table}` WHERE id IN ({$placeholders})",
				$ids_ints
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		if ( empty( $prefixes ) ) {
			return 0;
		}

		$time = time();
		$rb   = null !== $revoked_by ? (int) $revoked_by : 0;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$affected = (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$table}` SET revoked_at = %d, revoked_by = %d WHERE id IN ({$placeholders}) AND revoked_at IS NULL",
				array_merge( array( $time, $rb ), $ids_ints )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		if ( $affected > 0 ) {
			self::cascade_revoke_oauth_rows( $prefixes, $time );
		}

		return $affected;
	}

	/**
	 * Revoke all tokens never used and older than $days days.
	 *
	 * @param int      $days       Threshold age in days (default 30).
	 * @param int|null $revoked_by Acting user ID (0 for system/cron).
	 * @return int Number of registry rows revoked.
	 */
	public static function revoke_unused( $days = 30, $revoked_by = null ) {
		global $wpdb;

		$cutoff = time() - ( (int) $days * DAY_IN_SECONDS );
		$table  = self::table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$prefixes = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT token_prefix FROM `{$table}` WHERE last_used_at IS NULL AND issued_at < %d AND revoked_at IS NULL",
				$cutoff
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( empty( $prefixes ) ) {
			return 0;
		}

		$time = time();
		$rb   = null !== $revoked_by ? (int) $revoked_by : 0;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$table}` SET revoked_at = %d, revoked_by = %d WHERE last_used_at IS NULL AND issued_at < %d AND revoked_at IS NULL",
				$time,
				$rb,
				$cutoff
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$affected = (int) $wpdb->rows_affected;

		if ( $affected > 0 ) {
			self::cascade_revoke_oauth_rows( $prefixes, $time );
		}

		return $affected;
	}

	/**
	 * Revoke all tokens not used in the last $days days.
	 *
	 * @param int      $days       Threshold inactivity in days (default 180).
	 * @param int|null $revoked_by Acting user ID (0 for system/cron).
	 * @return int Number of registry rows revoked.
	 */
	public static function revoke_inactive( $days = 180, $revoked_by = null ) {
		global $wpdb;

		$cutoff = time() - ( (int) $days * DAY_IN_SECONDS );
		$table  = self::table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$prefixes = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT token_prefix FROM `{$table}` WHERE last_used_at IS NOT NULL AND last_used_at < %d AND revoked_at IS NULL",
				$cutoff
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( empty( $prefixes ) ) {
			return 0;
		}

		$time = time();
		$rb   = null !== $revoked_by ? (int) $revoked_by : 0;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$table}` SET revoked_at = %d, revoked_by = %d WHERE last_used_at IS NOT NULL AND last_used_at < %d AND revoked_at IS NULL",
				$time,
				$rb,
				$cutoff
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$affected = (int) $wpdb->rows_affected;

		if ( $affected > 0 ) {
			self::cascade_revoke_oauth_rows( $prefixes, $time );
		}

		return $affected;
	}

	/**
	 * Check whether the lazy cleanup should run (>24h since last run).
	 *
	 * @return bool True if cleanup should run now.
	 */
	public static function should_run_cleanup() {
		$last = (int) get_option( 'goldt_webmcp_last_cleanup', 0 );
		return ( time() - $last ) > DAY_IN_SECONDS;
	}

	/**
	 * Run all 4 cleanup rules and record the timestamp.
	 *
	 * Rules 1-3 soft-delete registry rows AND cascade into oauth_tokens.
	 * Rule 4 hard-deletes registry rows already revoked > 365 days ago.
	 *
	 * @return array{unused:int, inactive:int, expired:int, deleted:int}
	 */
	public static function run_cleanup() {
		global $wpdb;

		$table  = self::table_name();
		$now    = time();
		$result = array(
			'unused'   => 0,
			'inactive' => 0,
			'expired'  => 0,
			'deleted'  => 0,
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		try {
			// Rule 1: never used + older than 30 days → revoke + cascade.
			$p1 = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT token_prefix FROM `{$table}` WHERE last_used_at IS NULL AND issued_at < %d AND revoked_at IS NULL",
					$now - 30 * DAY_IN_SECONDS
				)
			);
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE `{$table}` SET revoked_at = %d, revoked_by = 0 WHERE last_used_at IS NULL AND issued_at < %d AND revoked_at IS NULL",
					$now,
					$now - 30 * DAY_IN_SECONDS
				)
			);
			$result['unused'] = (int) $wpdb->rows_affected;
			if ( $result['unused'] > 0 && ! empty( $p1 ) ) {
				self::cascade_revoke_oauth_rows( $p1, $now );
			}

			// Rule 2: last used > 180 days ago → revoke + cascade.
			$p2 = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT token_prefix FROM `{$table}` WHERE last_used_at IS NOT NULL AND last_used_at < %d AND revoked_at IS NULL",
					$now - 180 * DAY_IN_SECONDS
				)
			);
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE `{$table}` SET revoked_at = %d, revoked_by = 0 WHERE last_used_at IS NOT NULL AND last_used_at < %d AND revoked_at IS NULL",
					$now,
					$now - 180 * DAY_IN_SECONDS
				)
			);
			$result['inactive'] = (int) $wpdb->rows_affected;
			if ( $result['inactive'] > 0 && ! empty( $p2 ) ) {
				self::cascade_revoke_oauth_rows( $p2, $now );
			}

			// Rule 3: expired > 90 days ago and not yet revoked → revoke + cascade.
			$p3 = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT token_prefix FROM `{$table}` WHERE revoked_at IS NULL AND expires_at < %d",
					$now - 90 * DAY_IN_SECONDS
				)
			);
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE `{$table}` SET revoked_at = %d, revoked_by = 0 WHERE revoked_at IS NULL AND expires_at < %d",
					$now,
					$now - 90 * DAY_IN_SECONDS
				)
			);
			$result['expired'] = (int) $wpdb->rows_affected;
			if ( $result['expired'] > 0 && ! empty( $p3 ) ) {
				self::cascade_revoke_oauth_rows( $p3, $now );
			}

			// Rule 4: revoked > 365 days ago → hard DELETE (PII erasure).
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM `{$table}` WHERE revoked_at IS NOT NULL AND revoked_at < %d",
					$now - 365 * DAY_IN_SECONDS
				)
			);
			$result['deleted'] = (int) $wpdb->rows_affected;

		} catch ( \Throwable $e ) {
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Cleanup error logging; non-blocking audit trail.
			error_log( 'Token_Registry::run_cleanup failed: ' . $e->getMessage() );
		}

		update_option( 'goldt_webmcp_last_cleanup', $now );

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Cleanup audit log; intentional production logging per Token Management SPEC §5.3.
		error_log(
			sprintf(
				'[GoldtWebMCP] Token cleanup: unused=%d inactive=%d expired=%d deleted=%d',
				$result['unused'],
				$result['inactive'],
				$result['expired'],
				$result['deleted']
			)
		);

		return $result;
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
	 *     @type string $status   'active'|'renewable'|'unused'|'inactive'|'expired'|'revoked'|'all'. Default 'active'.
	 *     @type int    $user_id  Filter by user ID (0 = all).
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
		$now    = time();

		$where  = array( '1=1' );
		$values = array();

		switch ( $args['status'] ) {

			// Revoked_at IS NOT NULL.
			case 'revoked':
				$where[] = 'revoked_at IS NOT NULL';
				break;

			// Not-yet-expired tokens that have been used (= truly Active).
			case 'active':
				$where[]  = 'revoked_at IS NULL';
				$where[]  = 'expires_at > %d';
				$where[]  = 'last_used_at IS NOT NULL';
				$values[] = $now;
				break;

			// Not-yet-expired tokens that were NEVER used.
			case 'unused':
				$where[]  = 'revoked_at IS NULL';
				$where[]  = 'expires_at > %d';
				$where[]  = 'last_used_at IS NULL';
				$values[] = $now;
				break;

			// Tokens not used in the last 30 days (but used at some point).
			case 'inactive':
				$where[]  = 'revoked_at IS NULL';
				$where[]  = 'last_used_at IS NOT NULL';
				$where[]  = 'last_used_at < %d';
				$values[] = $now - 30 * DAY_IN_SECONDS;
				break;

			// access_token expired but refresh_token is still valid.
			case 'renewable':
				$where[]  = 'revoked_at IS NULL';
				$where[]  = 'expires_at <= %d';
				$where[]  = '(refresh_expires_at IS NOT NULL AND refresh_expires_at > %d)';
				$values[] = $now;
				$values[] = $now;
				break;

			// access_token expired, no valid refresh.
			case 'expired':
				$where[]  = 'revoked_at IS NULL';
				$where[]  = 'expires_at <= %d';
				$where[]  = '(refresh_expires_at IS NULL OR refresh_expires_at <= %d)';
				$values[] = $now;
				$values[] = $now;
				break;

			case 'all':
			default:
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

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Where clause built from whitelisted constant fragments only; values bound via prepare().
		$rows = $wpdb->get_results(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $values is a dynamic array built from optional filters; count matches at runtime.
				"SELECT * FROM `{$table}` WHERE {$where_sql} ORDER BY issued_at DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$values
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Check whether the current user has at least one active or renewable token.
	 *
	 * Used to decide whether to show the "My AI Tokens" nav entry.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return bool
	 */
	public static function user_has_active_tokens( $user_id ) {
		global $wpdb;

		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return false;
		}

		$table = self::table_name();
		$now   = time();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$table}` WHERE user_id = %d AND (revoked_at IS NULL OR revoked_at = 0) AND (expires_at > %d OR (refresh_expires_at IS NOT NULL AND refresh_expires_at > %d))",
				$user_id,
				$now,
				$now
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $count > 0;
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
