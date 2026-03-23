<?php
/**
 * Uninstall GoldT WebMCP Bridge
 *
 * Security-sensitive data (JWT secrets, tokens) is always deleted.
 * Other data (OAuth clients, settings) is deleted only if user opted in via Settings.
 *
 * @package GoldtWebMCP
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$goldtwmcp_delete_all = get_option( 'goldtwmcp_delete_on_uninstall', 0 );

delete_option( 'goldtwmcp_jwt_secret' );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	"DELETE FROM {$wpdb->options} 
     WHERE option_name LIKE 'goldtwmcp_refresh_%'"
);

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	"DELETE FROM {$wpdb->options} 
     WHERE option_name LIKE 'goldtwmcp_auth_code_%'"
);

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	"DELETE FROM {$wpdb->options} 
     WHERE option_name LIKE '_transient_goldtwmcp_%' 
        OR option_name LIKE '_transient_timeout_goldtwmcp_%'"
);

if ( $goldtwmcp_delete_all ) {

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		"DELETE FROM {$wpdb->options} 
         WHERE option_name LIKE 'goldtwmcp_client_%'"
	);

	delete_option( 'goldtwmcp_rate_limit_per_minute' );
	delete_option( 'goldtwmcp_rate_limit_per_hour' );
	delete_option( 'goldtwmcp_delete_on_uninstall' );
	delete_option( 'goldtwmcp_plan' );
	delete_option( 'goldtwmcp_version' );
	delete_option( 'goldtwmcp_installed' );
	delete_option( 'goldtwmcp_welcome_notice' );

	if ( class_exists( 'Redis' ) && defined( 'REDIS_HOST' ) ) {
		try {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
			$goldtwmcp_redis = new Redis();
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
			$goldtwmcp_redis->connect( REDIS_HOST, defined( 'REDIS_PORT' ) ? REDIS_PORT : 6379 );

			if ( defined( 'REDIS_PASSWORD' ) ) {
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
				$goldtwmcp_redis->auth( REDIS_PASSWORD );
			}

			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
			$goldtwmcp_keys = $goldtwmcp_redis->keys( 'goldtwmcp:*' );
			if ( ! empty( $goldtwmcp_keys ) ) {
				foreach ( $goldtwmcp_keys as $goldtwmcp_key ) {
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
					$goldtwmcp_redis->del( $goldtwmcp_key );
				}
			}

			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
			$goldtwmcp_redis->close();
		} catch ( Exception $e ) {
			// Silently fail - Redis cleanup is not critical.
			unset( $e );
		}
	}
}

wp_cache_flush();
flush_rewrite_rules();

if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
	error_log(
		sprintf( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			'GoldT WebMCP Bridge: Uninstalled. Full cleanup: %s',
			$goldtwmcp_delete_all ? 'YES' : 'NO (data preserved)'
		)
	);
}
