<?php
/**
 * Security regression test (1.2.13): an access token is a key to AI Connect's tools only (SEC-20),
 * and getUsers shows other users only as the WordPress REST API would (SEC-21).
 *
 * Creates its own fixtures (a subscriber without posts, an author with one published post, an
 * editor and an administrator, OAuth tokens for the subscriber and the editor), checks:
 *   - in-process: the determine_current_user filter resolves the token's user ONLY for
 *     AI Connect agent routes — never for core REST routes, admin-ajax.php, admin-post.php,
 *     front-end pages, PATH_INFO tricks, a ?rest_route= override, or the human-only routes
 *     generate-prompt / my-tokens; the goldtwmcp_bearer_allowed_routes filter whitelists;
 *   - over real HTTP (rest_url()): the token on /wp/v2/... and the spoof variants answers
 *     403 goldtwmcp_token_route_not_allowed, generate-prompt cannot mint a new token, while
 *     the AI Connect tool endpoints (REST and /api/aiconnect-tools) keep working;
 *   - getUsers for a subscriber: only authors with published posts, no registration date,
 *     role filter refused, no e-mail / login search oracle; for an administrator unchanged;
 * and deletes every fixture again (users, tokens, registry rows, the post).
 *
 *   wp --path=/workspace/platforms/wordpress eval-file tests/rest-scope-regression.php
 *
 * Exit status 0 = every check passed, 1 = at least one failed.
 *
 * @package GoldtWebMCP
 */

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.NamingConventions.PrefixAllGlobals, WordPress.PHP.DiscouragedPHPFunctions, WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.DB.DirectDatabaseQuery, WordPress.Security.NonceVerification -- CLI test script (runs inside wp eval-file, not in a request).
if ( ! defined( 'WP_CLI' ) ) {
	exit;
}

$GLOBALS['qars_fails']  = 0;
$GLOBALS['qars_passes'] = 0;

/**
 * Record one check.
 *
 * @param bool   $ok    Passed.
 * @param string $label Label.
 * @param mixed  $extra Shown on failure.
 */
function qars_check( $ok, $label, $extra = null ) {
	if ( $ok ) {
		++$GLOBALS['qars_passes'];
		echo "  ok   {$label}\n";
		return;
	}
	++$GLOBALS['qars_fails'];
	echo "  FAIL {$label}" . ( null !== $extra ? ' → ' . substr( wp_json_encode( $extra ), 0, 300 ) : '' ) . "\n";
}

$tag    = 'qars' . wp_rand( 1000, 9999 );
$users  = array();
$tokens = array();
$posts  = array();

register_shutdown_function(
	function () use ( &$users, &$tokens, &$posts ) {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/user.php';
		foreach ( $posts as $pid ) {
			wp_delete_post( $pid, true );
		}
		foreach ( $tokens as $t ) {
			$wpdb->delete( $wpdb->prefix . 'goldtwmcp_oauth_tokens', array( 'token' => $t ) );
		}
		foreach ( $users as $uid ) {
			$wpdb->delete( $wpdb->prefix . 'goldtwmcp_oauth_tokens', array( 'user_id' => (int) $uid ) );
			$wpdb->delete( $wpdb->prefix . 'aiconnect_token_registry', array( 'user_id' => (int) $uid ) );
			wp_delete_user( $uid );
		}
		echo "\ncleanup: removed " . count( $users ) . ' users, ' . count( $tokens ) . ' tokens, ' . count( $posts ) . " posts\n";
		echo "RESULT: {$GLOBALS['qars_passes']} passed, {$GLOBALS['qars_fails']} failed\n";
		exit( $GLOBALS['qars_fails'] ? 1 : 0 );
	}
);

$make = function ( $role ) use ( $tag, &$users ) {
	$id = wp_insert_user(
		array(
			'user_login' => "{$tag}_{$role}",
			'user_pass'  => wp_generate_password( 24 ),
			'user_email' => "{$tag}_{$role}@example.invalid",
			'role'       => $role,
		)
	);
	if ( is_wp_error( $id ) ) {
		echo "fixture error: {$role}: " . $id->get_error_message() . "\n";
		exit( 1 );
	}
	$users[] = $id;
	return $id;
};

$sub    = $make( 'subscriber' );
$author = $make( 'author' );
$editor = $make( 'editor' );
$admin  = $make( 'administrator' );

$posts[] = wp_insert_post(
	array(
		'post_title'  => "{$tag} published",
		'post_status' => 'publish',
		'post_author' => $author,
		'post_type'   => 'post',
	)
);

$oauth    = new \GoldtWebMCP\OAuth\OAuth_Server();
$mint     = function ( $uid ) use ( $oauth, &$tokens ) {
	$t        = $oauth->create_access_token( 'goldnat-master', $uid, array( 'read' ), 'oauth' );
	$tokens[] = $t['access_token'];
	return $t['access_token'];
};
$sub_tok  = $mint( $sub );
$edit_tok = $mint( $editor );

// ---------------------------------------------------------------------------------------------
echo "\n== SEC-20 in-process: which requests may the token log in on ==\n";
$bearer = new \GoldtWebMCP\OAuth\Bearer_Auth();
$rp     = (string) wp_parse_url( rest_url(), PHP_URL_PATH ); // e.g. /wp-json/ or /wp/wp-json/.
$base   = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
$saved  = array( $_SERVER, $_GET, $_POST, isset( $GLOBALS['wp'] ) ? $GLOBALS['wp'] : null );
if ( isset( $GLOBALS['wp'] ) ) {
	$GLOBALS['wp'] = clone $GLOBALS['wp'];
	unset( $GLOBALS['wp']->query_vars['rest_route'] );
}
$probe = function ( $uri, $get = array(), $post = array() ) use ( $bearer, $sub_tok ) {
	$_SERVER['REQUEST_URI']        = $uri;
	$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $sub_tok;
	$_GET                          = $get;
	$_POST                         = $post;
	return $bearer->determine_user_from_bearer_token( false );
};
$deny_cases = array(
	'core REST /wp/v2/users/me'                => array( $rp . 'wp/v2/users/me' ),
	'core REST /wp/v2/comments'                => array( $rp . 'wp/v2/comments' ),
	'admin-ajax.php'                           => array( $base . 'wp-admin/admin-ajax.php' ),
	'admin-post.php'                           => array( $base . 'wp-admin/admin-post.php' ),
	'front-end page'                           => array( $base ),
	'PATH_INFO trick admin-ajax.php/wp-json/…' => array( $base . 'wp-admin/admin-ajax.php' . $rp . 'goldt-webmcp-bridge/v1/tools/x' ),
	'query string fakes our namespace'         => array( $rp . 'wp/v2/users/me?x=' . $rp . 'goldt-webmcp-bridge/v1/' ),
	'?rest_route= overrides our path'          => array( $rp . 'goldt-webmcp-bridge/v1/manifest', array( 'rest_route' => '/wp/v2/users/me' ) ),
	'POST rest_route= overrides our path'      => array( $rp . 'goldt-webmcp-bridge/v1/manifest', array(), array( 'rest_route' => '/wp/v2/users/me' ) ),
	'dot-dot route'                            => array( $rp . 'goldt-webmcp-bridge/../wp/v2/users/me' ),
	'human-only generate-prompt'               => array( $rp . 'goldt-webmcp-bridge/v1/generate-prompt' ),
	'human-only my-tokens'                     => array( $rp . 'goldt-webmcp-bridge/v1/my-tokens' ),
	'human-only my-tokens/{id}'                => array( $rp . 'goldt-webmcp-bridge/v1/my-tokens/5' ),
);
foreach ( $deny_cases as $label => $c ) {
	$got = $probe( $c[0], $c[1] ?? array(), $c[2] ?? array() );
	qars_check( false === $got, "token does not log in: {$label}", $got );
}
$allow_cases = array(
	'REST tool call'               => array( $rp . 'goldt-webmcp-bridge/v1/tools/wordpress.getCurrentUser' ),
	'REST tool list'               => array( $rp . 'goldt-webmcp-bridge/v1/tools' ),
	'?rest_route= to our tool'     => array( $base, array( 'rest_route' => '/goldt-webmcp-bridge/v1/tools/wordpress.getCurrentUser' ) ),
	'confirmation status (1.2.13)' => array( $rp . 'goldt-webmcp-bridge/v1/confirmations/conf_' . str_repeat( 'a', 32 ) ),
);
foreach ( $allow_cases as $label => $c ) {
	$got = $probe( $c[0], $c[1] ?? array() );
	qars_check( (int) $got === (int) $sub, "token logs in on: {$label}", $got );
}
$wl = function () {
	return array( '/wp/v2/media' );
};
add_filter( 'goldtwmcp_bearer_allowed_routes', $wl );
qars_check( (int) $probe( $rp . 'wp/v2/media' ) === (int) $sub, 'goldtwmcp_bearer_allowed_routes whitelists /wp/v2/media' );
qars_check( false === $probe( $rp . 'wp/v2/users/me' ), 'whitelist does not open other core routes' );
remove_filter( 'goldtwmcp_bearer_allowed_routes', $wl );
qars_check( false === $probe( $rp . 'wp/v2/media' ), 'whitelist removed → /wp/v2/media refused again' );

$_SERVER = $saved[0];
$_GET    = $saved[1];
$_POST   = $saved[2];
if ( null !== $saved[3] ) {
	$GLOBALS['wp'] = $saved[3];
}
unset( $_SERVER['HTTP_AUTHORIZATION'] );

// ---------------------------------------------------------------------------------------------
echo "\n== SEC-20 over HTTP (" . rest_url() . ") ==\n";
$http = function ( $method, $url, $token, $body = null ) {
	$args = array(
		'method'  => $method,
		'timeout' => 30,
		'headers' => array(
			'Authorization' => 'Bearer ' . $token,
			'Content-Type'  => 'application/json',
		),
	);
	if ( null !== $body ) {
		$args['body'] = wp_json_encode( $body );
	}
	$r = wp_remote_request( $url, $args );
	if ( is_wp_error( $r ) ) {
		return array( 0, array( 'transport' => $r->get_error_message() ), '' );
	}
	$raw = (string) wp_remote_retrieve_body( $r );
	return array( (int) wp_remote_retrieve_response_code( $r ), json_decode( $raw, true ), $raw );
};
foreach ( array(
	'/wp/v2/users/me'                => rest_url( 'wp/v2/users/me' ),
	'?rest_route= on our path'       => add_query_arg( 'rest_route', '/wp/v2/users/me', rest_url( 'goldt-webmcp-bridge/v1/manifest' ) ),
	'query string fakes namespace'   => rest_url( 'wp/v2/users/me' ) . '?x=' . rawurlencode( $rp . 'goldt-webmcp-bridge/v1/' ),
	'/?rest_route=/wp/v2/users/me'   => add_query_arg( 'rest_route', '/wp/v2/users/me', home_url( '/' ) ),
) as $label => $url ) {
	list( $code, $json, $raw ) = $http( 'GET', $url, $sub_tok );
	qars_check(
		403 === $code && 'goldtwmcp_token_route_not_allowed' === ( $json['code'] ?? '' ) && false === strpos( $raw, '"id":' . $sub ),
		"core REST refused for the token: {$label} [{$code}]",
		array( $code, $json )
	);
}
list( $code, $json, $raw ) = $http( 'POST', rest_url( 'goldt-webmcp-bridge/v1/generate-prompt' ), $edit_tok );
qars_check( 403 === $code && false === strpos( $raw, 'access_token' ), "editor read token cannot mint a new token via generate-prompt [{$code}]", array( $code, $json ) );
list( $code, $json, $raw ) = $http( 'GET', rest_url( 'goldt-webmcp-bridge/v1/my-tokens' ), $edit_tok );
qars_check( 403 === $code, "token cannot list the user's connections via my-tokens [{$code}]", array( $code, $json ) );
list( $code, $json ) = $http( 'POST', rest_url( 'goldt-webmcp-bridge/v1/tools/wordpress.getCurrentUser' ), $sub_tok, new stdClass() );
qars_check( 200 === $code && (int) ( $json['data']['id'] ?? 0 ) === (int) $sub, "REST tool call still works [{$code}]", array( $code, $json ) );
list( $code, $json ) = $http( 'POST', home_url( '/api/aiconnect-tools?name=wordpress.getCurrentUser' ), $sub_tok, new stdClass() );
qars_check( 200 === $code && (int) ( $json['data']['id'] ?? 0 ) === (int) $sub, "Servio /api/aiconnect-tools still works [{$code}]", array( $code, $json ) );
$r = wp_remote_get( rest_url( 'goldt-webmcp-bridge/v1/manifest' ), array( 'timeout' => 30 ) );
qars_check( 200 === (int) wp_remote_retrieve_response_code( $r ), 'public manifest still answers without a token' );

// ---------------------------------------------------------------------------------------------
echo "\n== SEC-21 getUsers visibility ==\n";
$gateway = goldtwmcp_tool_gateway();
$users_of = function ( $r ) {
	return isset( $r['result']['data']['users'] ) ? $r['result']['data']['users'] : null;
};
$ids_of = function ( $list ) {
	return array_map( fn( $u ) => (int) $u['id'], (array) $list );
};
$public_types = array_values(
	get_post_types(
		array(
			'public'       => true,
			'show_in_rest' => true,
		)
	)
);

$r    = $gateway->call_as( $sub, 'wordpress.getUsers', array( 'per_page' => 100 ) );
$list = $users_of( $r );
qars_check( is_array( $list ), 'subscriber may call getUsers', $r );
$all_public = true;
foreach ( (array) $list as $u ) {
	if ( (int) count_user_posts( (int) $u['id'], $public_types, true ) < 1 ) {
		$all_public = false;
	}
}
qars_check( $all_public, 'subscriber sees only authors with published posts' );
qars_check( in_array( (int) $author, $ids_of( $list ), true ), 'the author with a published post is listed' );
qars_check( ! in_array( (int) $sub, $ids_of( $list ), true ) && ! in_array( (int) $editor, $ids_of( $list ), true ), 'users without published posts are not listed' );
$has_reg = false;
foreach ( (array) $list as $u ) {
	if ( array_key_exists( 'registered', $u ) || array_key_exists( 'email', $u ) ) {
		$has_reg = true;
	}
}
qars_check( ! $has_reg, 'no registration date / e-mail for a subscriber' );

$r = $gateway->call_as( $sub, 'wordpress.getUsers', array( 'role' => 'administrator' ) );
qars_check( ! $r['success'] && 403 === (int) $r['status'], 'subscriber role filter refused (403)', $r );

$r = $gateway->call_as( $sub, 'wordpress.getUsers', array( 'search' => "{$tag}_author@example.invalid" ) );
qars_check( array() === (array) $users_of( $r ), 'search by e-mail finds nothing for a subscriber (no oracle)', $r );
$r = $gateway->call_as( $sub, 'wordpress.getUsers', array( 'search' => '@' ) );
qars_check( array() === (array) $users_of( $r ), 'search "@" finds nothing for a subscriber', $r );
$r = $gateway->call_as( $sub, 'wordpress.getUsers', array( 'search' => "{$tag}_subscriber" ) );
qars_check( array() === (array) $users_of( $r ), 'subscriber login is not findable (no posts)', $r );

$r = $gateway->call_as( $sub, 'wordpress.getUsers', array( 'per_page' => 1 ) );
qars_check( count( (array) $users_of( $r ) ) <= 1, 'per_page respected' );

$r    = $gateway->call_as( $admin, 'wordpress.getUsers', array( 'role' => 'subscriber', 'search' => $tag ) );
$list = $users_of( $r );
qars_check( in_array( (int) $sub, $ids_of( $list ), true ), 'administrator (list_users) can still filter by role and find users without posts', $r );
qars_check( isset( $list[0] ) && array_key_exists( 'registered', $list[0] ) && ! array_key_exists( 'email', $list[0] ), 'administrator sees registration date, still no e-mail', $list );
