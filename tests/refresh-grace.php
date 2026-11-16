<?php
/**
 * Refresh-token rotation grace window (1.2.13).
 *
 * A client that repeats a refresh with the refresh token it has just used gets the SAME new
 * pair back for refresh_grace_seconds() (default 60) — never a second pair — and nothing once
 * the window is over, after the new pair was revoked, for another client, or after a plain
 * revoke of the old token. Runs over real HTTP against the OAuth token endpoint, creates its own
 * user + tokens and deletes them again.
 *
 *   wp --path=/workspace/platforms/wordpress eval-file tests/refresh-grace.php
 *
 * Exit status 0 = every check passed, 1 = at least one failed.
 *
 * @package GoldtWebMCP
 */

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.NamingConventions.PrefixAllGlobals, WordPress.PHP.DiscouragedPHPFunctions, WordPress.DB.DirectDatabaseQuery -- CLI test script (runs inside wp eval-file, not in a request).
if ( ! defined( 'WP_CLI' ) ) {
	exit;
}

$GLOBALS['qarg_fails']  = 0;
$GLOBALS['qarg_passes'] = 0;

/**
 * Record one check.
 *
 * @param bool   $ok    Passed.
 * @param string $label Label.
 * @param mixed  $extra Shown on failure.
 */
function qarg_check( $ok, $label, $extra = null ) {
	if ( $ok ) {
		++$GLOBALS['qarg_passes'];
		echo "  ok   {$label}\n";
		return;
	}
	++$GLOBALS['qarg_fails'];
	echo "  FAIL {$label}" . ( null !== $extra ? ' → ' . substr( wp_json_encode( $extra ), 0, 300 ) : '' ) . "\n";
}

global $wpdb;
$tag   = 'qarg' . wp_rand( 1000, 9999 );
$table = $wpdb->prefix . 'goldtwmcp_oauth_tokens';
$uid   = wp_insert_user(
	array(
		'user_login' => "{$tag}_editor",
		'user_pass'  => wp_generate_password( 24 ),
		'user_email' => "{$tag}_editor@example.invalid",
		'role'       => 'editor',
	)
);
if ( is_wp_error( $uid ) ) {
	echo 'fixture error: ' . $uid->get_error_message() . "\n";
	exit( 1 );
}

register_shutdown_function(
	function () use ( $uid, $table ) {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/user.php';
		$wpdb->delete( $table, array( 'user_id' => (int) $uid ) );
		$wpdb->delete( $wpdb->prefix . 'aiconnect_token_registry', array( 'user_id' => (int) $uid ) );
		wp_delete_user( $uid );
		echo "\ncleanup: removed fixture user + tokens\n";
		echo "RESULT: {$GLOBALS['qarg_passes']} passed, {$GLOBALS['qarg_fails']} failed\n";
		exit( $GLOBALS['qarg_fails'] ? 1 : 0 );
	}
);

$cols = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from prefix.
qarg_check( in_array( 'rotated_at', $cols, true ) && in_array( 'rotated_to_id', $cols, true ), 'DB 2.0.9: rotated_at + rotated_to_id columns exist', $cols );
qarg_check( 60 === \GoldtWebMCP\OAuth\OAuth_Server::refresh_grace_seconds(), 'default grace window is 60 seconds' );

$oauth = new \GoldtWebMCP\OAuth\OAuth_Server();
$url   = rest_url( 'goldt-webmcp-bridge/v1/oauth/token' );
$post  = function ( $refresh, $client = 'goldnat-master' ) use ( $url ) {
	$r = wp_remote_post(
		$url,
		array(
			'timeout' => 30,
			'body'    => array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $refresh,
				'client_id'     => $client,
			),
		)
	);
	if ( is_wp_error( $r ) ) {
		return array( 0, array( 'transport' => $r->get_error_message() ) );
	}
	return array( (int) wp_remote_retrieve_response_code( $r ), json_decode( (string) wp_remote_retrieve_body( $r ), true ) );
};
$valid = function ( $access ) use ( $oauth ) {
	return ! is_wp_error( $oauth->validate_token( $access ) );
};

echo "\n== repeat within the window → the same new pair ==\n";
$p0 = $oauth->create_access_token( 'goldnat-master', $uid, array( 'read', 'write' ), 'oauth' );
list( $c1, $p1 ) = $post( $p0['refresh_token'] );
qarg_check( 200 === $c1 && ! empty( $p1['access_token'] ) && ! empty( $p1['refresh_token'] ), 'first refresh → 200 + new pair', array( $c1, $p1 ) );
qarg_check( $p1['refresh_token'] !== $p0['refresh_token'] && $p1['access_token'] !== $p0['access_token'], 'first refresh rotates both tokens' );
qarg_check( ! $valid( $p0['access_token'] ), 'old access token no longer valid after rotation' );
list( $c2, $p2 ) = $post( $p0['refresh_token'] );
qarg_check( 200 === $c2, 'repeat with the just-rotated refresh token → 200', array( $c2, $p2 ) );
qarg_check( isset( $p2['access_token'] ) && $p2['access_token'] === $p1['access_token'] && $p2['refresh_token'] === $p1['refresh_token'], 'repeat returns the SAME new pair (no second pair minted)', array( $p1, $p2 ) );
qarg_check( isset( $p2['expires_in'] ) && $p2['expires_in'] > 3000 && $p2['expires_in'] <= 3600 && 'Bearer' === $p2['token_type'] && 'read write' === $p2['scope'], 'repeat response has remaining lifetime, type and scope', $p2 );
$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE user_id = %d", $uid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from prefix.
qarg_check( 2 === $count, 'exactly 2 token rows exist (original + one successor)', $count );
qarg_check( $valid( $p1['access_token'] ), 'new access token valid' );
list( $c3, $p3 ) = $post( $p0['refresh_token'] );
qarg_check( 200 === $c3 && $p3['access_token'] === $p1['access_token'], 'third repeat in window → still the same pair' );

echo "\n== the new pair keeps rotating normally ==\n";
list( $c4, $p4 ) = $post( $p1['refresh_token'] );
qarg_check( 200 === $c4 && $p4['access_token'] !== $p1['access_token'], 'refresh with the new refresh token → next pair', array( $c4, $p4 ) );
list( $c5, $p5 ) = $post( $p0['refresh_token'] );
qarg_check( 400 === $c5 && 'invalid_grant' === ( $p5['code'] ?? '' ), 'grandparent refresh token (successor already rotated) → invalid_grant', array( $c5, $p5 ) );

echo "\n== window over → invalid_grant ==\n";
$wpdb->update( $table, array( 'rotated_at' => gmdate( 'Y-m-d H:i:s', time() - 61 ) ), array( 'refresh_token' => $p1['refresh_token'] ) );
list( $c6, $p6 ) = $post( $p1['refresh_token'] );
qarg_check( 400 === $c6 && 'invalid_grant' === ( $p6['code'] ?? '' ), 'repeat 61 s after rotation → invalid_grant', array( $c6, $p6 ) );

echo "\n== new pair revoked → no grace ==\n";
$q0 = $oauth->create_access_token( 'goldnat-master', $uid, array( 'read' ), 'oauth' );
list( , $q1 ) = $post( $q0['refresh_token'] );
\GoldtWebMCP\OAuth\Token_Registry::revoke( $q1['access_token'] );
list( $c7, $p7 ) = $post( $q0['refresh_token'] );
qarg_check( 400 === $c7 && 'invalid_grant' === ( $p7['code'] ?? '' ), 'successor revoked by the user → repeat refused', array( $c7, $p7 ) );

echo "\n== other client → refused ==\n";
$r0 = $oauth->create_access_token( 'goldnat-master', $uid, array( 'read' ), 'oauth' );
list( , $r1 ) = $post( $r0['refresh_token'] );
list( $c8, $p8 ) = $post( $r0['refresh_token'], 'claude-ai' );
qarg_check( 400 === $c8 && empty( $p8['access_token'] ), 'repeat with a different client_id → refused, no tokens', array( $c8, $p8 ) );

echo "\n== plain revoke (not a rotation) → no grace ==\n";
$s0 = $oauth->create_access_token( 'goldnat-master', $uid, array( 'read' ), 'oauth' );
\GoldtWebMCP\OAuth\Token_Registry::revoke( $s0['access_token'] );
list( $c9, $p9 ) = $post( $s0['refresh_token'] );
qarg_check( 400 === $c9 && empty( $p9['access_token'] ), 'refresh token of a revoked connection → refused', array( $c9, $p9 ) );

echo "\n== grace disabled by filter ==\n";
add_filter( 'goldtwmcp_refresh_grace_seconds', '__return_zero' );
qarg_check( 0 === \GoldtWebMCP\OAuth\OAuth_Server::refresh_grace_seconds(), 'filter can switch the window off (0)' );
remove_filter( 'goldtwmcp_refresh_grace_seconds', '__return_zero' );
add_filter(
	'goldtwmcp_refresh_grace_seconds',
	function () {
		return 99999;
	}
);
qarg_check( 300 === \GoldtWebMCP\OAuth\OAuth_Server::refresh_grace_seconds(), 'filter is capped at 300 seconds' );
