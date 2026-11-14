<?php
/**
 * Security regression test (1.2.12): scope defaults and who may receive / use the write scope.
 *
 * Creates its own fixtures (a subscriber, a customer when WooCommerce is active,
 * a contributor, an author, an editor, an EDD shop worker when that role exists,
 * OAuth tokens for some of them, and a throw-away module with declared and
 * undeclared tools), checks:
 *   - Scopes::filter_for_user() / the connection prompt: write only for users
 *     with an editing capability (subscriber / customer get read only);
 *   - undeclared tools fail closed (read verbs read, delete verbs delete, others write);
 *   - REST tool calls: a read token cannot call write / delete tools, a write token
 *     of a subscriber (issued before 1.2.12) is refused at call time;
 *   - the in-process gateway applies the same rule (denied_layer = scope);
 * and deletes every fixture again (users, tokens, registry rows).
 *
 *   wp --path=/workspace/platforms/wordpress eval-file tests/security-regression.php
 *
 * Exit status 0 = every check passed, 1 = at least one failed.
 *
 * @package GoldtWebMCP
 */

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.NamingConventions.PrefixAllGlobals, WordPress.PHP.DiscouragedPHPFunctions, WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.DB.DirectDatabaseQuery, Squiz.PHP.DisallowMultipleAssignments.Found, Generic.Files.OneObjectStructurePerFile.MultipleFound, Universal.Files.SeparateFunctionsFromOO.Mixed -- CLI test script (runs inside wp eval-file, not in a request).
if ( ! defined( 'WP_CLI' ) ) {
	exit;
}

/**
 * Throw-away module: one declared read tool that writes nothing, and undeclared tools of every verb class.
 */
class QASec_Scope_Module extends \GoldtWebMCP\Modules\Module_Base {

	/**
	 * Module name.
	 *
	 * @var string
	 */
	protected $module_name = 'qasecscope';

	/**
	 * Register tools.
	 */
	protected function register_tools() {
		$schema = array(
			'type'       => 'object',
			'properties' => array(),
		);
		foreach ( array( 'getThing', 'listThings', 'searchThings', 'exportThings', 'validateThing', 'list404Things', 'createThing', 'updateThing', 'setThing', 'doThing', 'importThing', 'deleteThing', 'removeThing', 'purgeThings' ) as $t ) {
			$this->register_tool(
				$t,
				array(
					'description'  => 'qa',
					'input_schema' => $schema,
					'callback'     => array( $this, 'ok' ),
				)
			);
		}
		foreach ( array(
			'declaredRead'   => 'read',
			'declaredWrite'  => 'write',
			'declaredDelete' => 'delete',
			'declaredAdmin'  => 'admin',
		) as $t => $scope ) {
			$this->register_tool(
				$t,
				array(
					'description'    => 'qa',
					'input_schema'   => $schema,
					'required_scope' => $scope,
					'callback'       => array( $this, 'ok' ),
				)
			);
		}
	}

	/**
	 * Every tool: return ok.
	 *
	 * @return array
	 */
	public function ok() {
		return array( 'ok' => true );
	}
}

$GLOBALS['qasec_fails']  = 0;
$GLOBALS['qasec_passes'] = 0;

/**
 * Record one check.
 *
 * @param bool   $ok    Passed.
 * @param string $label Label.
 * @param mixed  $extra Shown on failure.
 */
function qasec_check( $ok, $label, $extra = null ) {
	if ( $ok ) {
		++$GLOBALS['qasec_passes'];
		echo "  ok   {$label}\n";
		return;
	}
	++$GLOBALS['qasec_fails'];
	echo "  FAIL {$label}" . ( null !== $extra ? ' → ' . substr( wp_json_encode( is_wp_error( $extra ) ? array( $extra->get_error_code(), $extra->get_error_message() ) : $extra ), 0, 300 ) : '' ) . "\n";
}

/**
 * Call a tool through the REST handler with a Bearer token; return [status, code].
 *
 * @param object $endpoint Tools endpoint.
 * @param string $token    Access token.
 * @param int    $uid      Token user.
 * @param string $tool     Tool.
 * @return array
 */
function qasec_rest( $endpoint, $token, $uid, $tool ) {
	$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
	wp_set_current_user( $uid );
	$req = new WP_REST_Request( 'POST', '/goldt-webmcp-bridge/v1/tools/' . $tool );
	$req->set_url_params( array( 'tool' => $tool ) );
	$req->set_header( 'Content-Type', 'application/json' );
	$req->set_body( '{}' );
	$r = $endpoint->execute_tool( $req );
	unset( $_SERVER['HTTP_AUTHORIZATION'] );
	wp_set_current_user( 0 );
	if ( is_wp_error( $r ) ) {
		$d = $r->get_error_data();
		return array( is_array( $d ) && isset( $d['status'] ) ? (int) $d['status'] : 400, $r->get_error_code() );
	}
	return array( 200, 'ok' );
}

$plugin   = GoldtWebMCP_Plugin::instance();
$endpoint = $plugin->get_tools_endpoint();
$gateway  = goldtwmcp_tool_gateway();
$oauth    = new \GoldtWebMCP\OAuth\OAuth_Server();
$tag      = 'qasec' . wp_rand( 1000, 9999 );
$users    = array();
$tokens   = array();

register_shutdown_function(
	function () use ( &$users, &$tokens ) {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/user.php';
		foreach ( $tokens as $t ) {
			$wpdb->delete( $wpdb->prefix . 'goldtwmcp_oauth_tokens', array( 'token' => $t ) );
		}
		foreach ( $users as $uid ) {
			$wpdb->delete( $wpdb->prefix . 'aiconnect_token_registry', array( 'user_id' => (int) $uid ) );
			wp_delete_user( $uid );
		}
		echo "\ncleanup: removed " . count( $users ) . ' users, ' . count( $tokens ) . " tokens\n";
		echo "RESULT: {$GLOBALS['qasec_passes']} passed, {$GLOBALS['qasec_fails']} failed\n";
		exit( $GLOBALS['qasec_fails'] ? 1 : 0 );
	}
);

$make = function ( $role ) use ( $tag, &$users ) {
	$id = wp_insert_user(
		array(
			'user_login' => $tag . '_' . $role,
			'user_email' => $tag . '_' . $role . '@example.invalid',
			'user_pass'  => wp_generate_password( 24 ),
			'role'       => $role,
		)
	);
	if ( is_wp_error( $id ) ) {
		echo "fixture failed for {$role}: " . $id->get_error_message() . "\n";
		return 0;
	}
	$users[] = (int) $id;
	return (int) $id;
};

$sub    = $make( 'subscriber' );
$contr  = $make( 'contributor' );
$author = $make( 'author' );
$editor = $make( 'editor' );
$cust   = wp_roles()->is_role( 'customer' ) ? $make( 'customer' ) : 0;
$worker = wp_roles()->is_role( 'shop_worker' ) ? $make( 'shop_worker' ) : 0;
$admin  = (int) ( get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
)[0] ?? 1 );
$all    = array( 'read', 'write', 'delete', 'manage_users', 'admin' );

echo "== Scopes granted at consent (Scopes::filter_for_user) ==\n";
$expect = array(
	'subscriber'  => array( $sub, array( 'read' ) ),
	'contributor' => array( $contr, array( 'read', 'write', 'delete' ) ), // Core contributors hold delete_posts (own drafts).
	'author'      => array( $author, array( 'read', 'write', 'delete' ) ),
	'editor'      => array( $editor, array( 'read', 'write', 'delete' ) ),
	'admin'       => array( $admin, $all ),
);
if ( $cust ) {
	$expect['customer'] = array( $cust, array( 'read' ) );
}
foreach ( $expect as $label => list( $uid, $want ) ) {
	$got = \GoldtWebMCP\OAuth\Scopes::filter_for_user( $all, $uid );
	qasec_check( $got === $want, "{$label} gets " . implode( '+', $want ), $got );
}
if ( $worker ) {
	$got = \GoldtWebMCP\OAuth\Scopes::filter_for_user( $all, $worker );
	qasec_check( in_array( 'write', $got, true ), 'EDD shop_worker (no edit_posts, edits products/payments) keeps write', $got );
}
qasec_check( ! \GoldtWebMCP\OAuth\Scopes::user_can_use_scope( 'write', $sub ), 'subscriber cannot use the write scope at call time' );
qasec_check( \GoldtWebMCP\OAuth\Scopes::user_can_use_scope( 'read', $sub ), 'subscriber can use the read scope' );

// Connection prompt scopes (private method, called as each user).
$derive = new ReflectionMethod( $endpoint, 'derive_scopes_from_caps' );
$derive->setAccessible( true );
wp_set_current_user( $sub );
$got = $derive->invoke( $endpoint );
qasec_check( array( 'read' ) === $got, 'connection prompt: subscriber → read only', $got );
wp_set_current_user( $editor );
$got = $derive->invoke( $endpoint );
qasec_check( array( 'read', 'write', 'delete' ) === $got, 'connection prompt: editor → read+write+delete', $got );
wp_set_current_user( 0 );

echo "\n== Undeclared tools fail closed ==\n";
$qa = new QASec_Scope_Module( null );
$endpoint->register_module( $qa );
$tools = $qa->get_tools();
$want  = array(
	'getThing'       => 'read',
	'listThings'     => 'read',
	'searchThings'   => 'read',
	'exportThings'   => 'read',
	'validateThing'  => 'read',
	'list404Things'  => 'read',
	'createThing'    => 'write',
	'updateThing'    => 'write',
	'setThing'       => 'write',
	'doThing'        => 'write',
	'importThing'    => 'write',
	'deleteThing'    => 'delete',
	'removeThing'    => 'delete',
	'purgeThings'    => 'delete',
	'declaredRead'   => 'read',
	'declaredWrite'  => 'write',
	'declaredDelete' => 'delete',
	'declaredAdmin'  => 'admin',
);
foreach ( $want as $t => $scope ) {
	qasec_check( ( $tools[ $t ]['required_scope'] ?? null ) === $scope, "{$t} → {$scope}", $tools[ $t ]['required_scope'] ?? null );
}
$undeclared = \GoldtWebMCP\Modules\Module_Base::get_undeclared_scope_tools();
qasec_check( isset( $undeclared['qasecscope.createThing'] ) && ! isset( $undeclared['qasecscope.declaredRead'] ), 'undeclared tools are reported, declared ones are not' );
if ( class_exists( 'WooCommerce' ) ) {
	$cart = $endpoint->resolve_tool( 'woocommerce.addToCart' );
	qasec_check( $cart && 'read' === $cart['required_scope'], 'legacy self-service tool woocommerce.addToCart keeps read (customer cart)', $cart ? $cart['required_scope'] : null );
}

echo "\n== REST: token scope vs tool scope ==\n";
$issue = function ( $uid, array $scopes ) use ( $oauth, &$tokens ) {
	$t = $oauth->create_access_token( 'claude-ai', $uid, $scopes, 'oauth' );
	if ( is_wp_error( $t ) ) {
		echo 'token fixture failed: ' . $t->get_error_message() . "\n";
		return '';
	}
	$tokens[] = $t['access_token'];
	return $t['access_token'];
};
$ed_read  = $issue( $editor, array( 'read' ) );
$ed_write = $issue( $editor, array( 'read', 'write' ) );
$ed_all   = $issue( $editor, array( 'read', 'write', 'delete' ) );
$sub_old  = $issue( $sub, array( 'read', 'write' ) ); // As issued to a subscriber before 1.2.12.
$adm_read = $issue( $admin, array( 'read' ) );
$adm_all  = $issue( $admin, $all );

$cases = array(
	array( $ed_read, $editor, 'qasecscope.getThing', 200, 'editor read token → read tool' ),
	array( $ed_read, $editor, 'qasecscope.createThing', 403, 'editor read token → undeclared create tool refused' ),
	array( $ed_read, $editor, 'qasecscope.doThing', 403, 'editor read token → undeclared non-verb tool refused' ),
	array( $ed_read, $editor, 'qasecscope.declaredWrite', 403, 'editor read token → declared write tool refused' ),
	array( $ed_write, $editor, 'qasecscope.createThing', 200, 'editor write token → write tool' ),
	array( $ed_write, $editor, 'qasecscope.deleteThing', 403, 'editor write token → undeclared delete tool refused (needs delete)' ),
	array( $ed_all, $editor, 'qasecscope.removeThing', 200, 'editor delete token → delete tool' ),
	array( $ed_all, $editor, 'qasecscope.declaredAdmin', 403, 'editor token → admin tool refused' ),
	array( $sub_old, $sub, 'qasecscope.getThing', 200, 'subscriber old write token → read tool still works' ),
	array( $sub_old, $sub, 'qasecscope.createThing', 403, 'subscriber old write token → write tool refused (no editing capability)' ),
	array( $sub_old, $sub, 'qasecscope.declaredWrite', 403, 'subscriber old write token → declared write tool refused' ),
	array( $adm_read, $admin, 'qasecscope.declaredAdmin', 403, 'admin read token → admin tool refused' ),
	array( $adm_read, $admin, 'qasecscope.updateThing', 403, 'admin read token → write tool refused' ),
	array( $adm_all, $admin, 'qasecscope.declaredAdmin', 200, 'admin full token → admin tool' ),
);
if ( $endpoint->resolve_tool( 'wordpress.updateMedia' ) ) {
	$cases[] = array( $ed_read, $editor, 'wordpress.updateMedia', 403, 'editor read token → wordpress.updateMedia refused (insufficient_scope)' );
	$cases[] = array( $sub_old, $sub, 'wordpress.updateMedia', 403, 'subscriber old write token → wordpress.updateMedia refused' );
}
if ( $endpoint->resolve_tool( 'elementor.deletePage' ) ) {
	$cases[] = array( $adm_read, $admin, 'elementor.deletePage', 403, 'admin read token → elementor.deletePage refused' );
	$cases[] = array( $adm_read, $admin, 'elementor.importSite', 403, 'admin read token → elementor.importSite refused' );
}
foreach ( $cases as list( $tok, $uid, $tool, $status, $label ) ) {
	list( $got, $code ) = qasec_rest( $endpoint, $tok, $uid, $tool );
	qasec_check( $got === $status, "{$label} [{$got} {$code}]", array( $got, $code ) );
}

echo "\n== In-process gateway ==\n";
$r = $gateway->call_as( $sub, 'qasecscope.createThing' );
qasec_check( ! $r['success'] && 'scope' === $r['denied_layer'], 'subscriber → write tool denied at the scope layer', $r );
$r = $gateway->call_as( $sub, 'qasecscope.getThing' );
qasec_check( $r['success'], 'subscriber → read tool allowed', $r );
$r = $gateway->call_as( $contr, 'qasecscope.createThing' );
qasec_check( $r['success'], 'contributor → write tool allowed (edit_posts)', $r );
$r = $gateway->call_as( 0, 'qasecscope.doThing' );
qasec_check( ! $r['success'] && 'guest' === $r['denied_layer'], 'guest → undeclared non-verb tool denied (no longer read)', $r );
