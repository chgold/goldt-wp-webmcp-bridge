<?php
/**
 * Regression test (1.2.13, extended 1.2.14): confirmation flow for destructive / financial tools.
 *
 * Throw-away module "qaconfirm" with a self-previewing financial tool, a destructive
 * tool without a preview callback, a conditional tool and a read tool that declares
 * confirmation (must stay ungated). Its side effect is a counter option, so every
 * check can prove that nothing ran. Fixtures (two editors, a subscriber, tokens,
 * pending actions, grants, log rows, the counter) are removed at the end.
 *
 *   wp --path=/workspace/platforms/wordpress eval-file tests/confirmation-flow.php
 *
 * Checks:
 *   - manifest: confirmation + risk on gated tools, agent `confirm` argument removed,
 *     description says so, top-level `confirmation` descriptor, read tool not gated;
 *   - direct call (REST with Bearer token and in-process gateway) → 202
 *     confirmation_required, preview, confirm_url, NO side effect, agent's
 *     confirm:true ignored; repeated call → same pending action;
 *   - re-submission with confirmation_id while pending → still 202, nothing runs;
 *   - user step (Confirmation::confirm as the owner) → executes exactly once;
 *   - replay of the confirmation and re-submission afterwards → stored result,
 *     never a second execution; different arguments → confirmation_mismatch;
 *   - another user cannot confirm; reject → final; expired → cannot be confirmed;
 *   - autonomy grant (user + tool + client) → runs directly; other client still asks;
 *     revoke → back to confirmation;
 *   - conditional tool asks only for the risky variant;
 *   - read-only token never reaches the gate (insufficient_scope);
 *   - the confirm page refuses requests that carry an Authorization header / no cookie;
 *   - status_url (REST) reports pending / executed for the owner only;
 *   - every decision is in the audit log;
 *   - 1.2.14 — the user's summary is never empty and never the agent-facing text:
 *     template from the arguments, preview['summary'] from the tool, a generic sentence
 *     when a tool declares nothing, a row stored without a summary, the user's own
 *     language, `user_message`; the confirm page shows it as the headline;
 *   - 1.2.14 — no needless second request: the same call after the user confirmed
 *     returns the stored result ("already executed"), within the short window or while
 *     the result was not collected; `confirmation_new: true` asks again on purpose.
 *
 * Exit status 0 = every check passed, 1 = at least one failed.
 *
 * @package GoldtWebMCP
 */

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.NamingConventions.PrefixAllGlobals, WordPress.PHP.DiscouragedPHPFunctions, WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.DB.DirectDatabaseQuery, Squiz.PHP.DisallowMultipleAssignments.Found, Generic.Files.OneObjectStructurePerFile.MultipleFound, Universal.Files.SeparateFunctionsFromOO.Mixed, WordPress.PHP.YodaConditions.NotYoda, WordPress.Files.FileName, Universal.NamingConventions.NoReservedKeywordParameterNames -- CLI test script (runs inside wp eval-file, not in a request).
if ( ! defined( 'WP_CLI' ) ) {
	exit;
}

use GoldtWebMCP\Core\Confirmation;

/**
 * Throw-away module.
 */
class QAConfirm_Module extends \GoldtWebMCP\Modules\Module_Base {

	/**
	 * Module name.
	 *
	 * @var string
	 */
	protected $module_name = 'qaconfirm';

	/**
	 * Register tools.
	 */
	protected function register_tools() {
		$this->register_tool(
			'refundThing',
			array(
				'description'    => 'Refund a thing. Requires confirm: true.',
				'input_schema'   => array(
					'type'       => 'object',
					'required'   => array( 'thing_id', 'confirm' ),
					'properties' => array(
						'thing_id' => array( 'type' => 'integer' ),
						'amount'   => array( 'type' => 'number' ),
						'confirm'  => array( 'type' => 'boolean' ),
					),
				),
				'required_scope' => 'write',
				'confirmation'   => array(
					'risk'    => 'financial',
					'summary' => 'Refund thing #{thing_id}',
					'preview' => 'self',
				),
			)
		);
		$this->register_tool(
			'deleteThing',
			array(
				'description'    => 'Delete a thing.',
				'input_schema'   => array(
					'type'       => 'object',
					'required'   => array( 'thing_id' ),
					'properties' => array( 'thing_id' => array( 'type' => 'integer' ) ),
				),
				'required_scope' => 'delete',
				'confirmation'   => 'required',
			)
		);
		$this->register_tool(
			'setThingStatus',
			array(
				'description'    => 'Set a status.',
				'input_schema'   => array(
					'type'       => 'object',
					'required'   => array( 'thing_id', 'status' ),
					'properties' => array(
						'thing_id' => array( 'type' => 'integer' ),
						'status'   => array( 'type' => 'string' ),
					),
				),
				'required_scope' => 'write',
				'confirmation'   => array(
					'required' => static function ( $a ) {
						return 'cancelled' === ( $a['status'] ?? '' );
					},
					'preview'  => static function ( $a ) {
						return array(
							'thing_id' => $a['thing_id'],
							'from'     => 'open',
							'to'       => $a['status'],
						);
					},
				),
			)
		);
		$this->register_tool(
			'wipeThings',
			array(
				'description'    => 'Wipe things.',
				'input_schema'   => array(
					'type'       => 'object',
					'properties' => array( 'older_than_days' => array( 'type' => 'integer' ) ),
				),
				'required_scope' => 'delete',
				'confirmation'   => array(
					'summary' => 'Wipe old things',
					'preview' => static function ( $a ) {
						return array(
							'summary' => sprintf( 'Delete %d things older than %d days', 3, (int) ( $a['older_than_days'] ?? 30 ) ),
							'details' => array( 'This cannot be undone.' ),
							'count'   => 3,
						);
					},
				),
				'callback'       => static fn() => array( 'wiped' => 3 ),
			)
		);
		$this->register_tool(
			'getThing',
			array(
				'description'    => 'Read a thing.',
				'input_schema'   => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'required_scope' => 'read',
				'confirmation'   => 'required',
				'callback'       => static fn() => array( 'thing' => 1 ),
			)
		);
	}

	/**
	 * Side effect.
	 *
	 * @param string $what Tool.
	 * @return int
	 */
	private function bump( $what ) {
		$n = (int) get_option( 'qaconfirm_counter_' . $what, 0 ) + 1;
		update_option( 'qaconfirm_counter_' . $what, $n, false );
		return $n;
	}

	/**
	 * Self-previewing financial tool (add-on helper pattern).
	 *
	 * @param array $p Args.
	 * @return array|WP_Error
	 */
	public function execute_refundThing( $p ) {
		if ( Confirmation::is_preview() ) {
			return new WP_Error(
				'confirmation_required',
				'refund thing',
				array(
					'status'  => 202,
					'preview' => array(
						'thing_id' => $p['thing_id'],
						'amount'   => $p['amount'] ?? 10,
					),
				)
			);
		}
		if ( ! Confirmation::is_confirmed_execution() && empty( $p['confirm'] ) ) {
			return new WP_Error( 'confirmation_required', 'legacy', array( 'status' => 400 ) );
		}
		return array(
			'refunded' => true,
			'count'    => $this->bump( 'refund' ),
		);
	}

	/**
	 * Destructive tool.
	 *
	 * @param array $p Args.
	 * @return array
	 */
	public function execute_deleteThing( $p ) {
		return array(
			'deleted' => (int) $p['thing_id'],
			'count'   => $this->bump( 'delete' ),
		);
	}

	/**
	 * Conditional tool.
	 *
	 * @param array $p Args.
	 * @return array
	 */
	public function execute_setThingStatus( $p ) {
		return array(
			'status' => $p['status'],
			'count'  => $this->bump( 'status' ),
		);
	}
}

$GLOBALS['qacf_fails']  = 0;
$GLOBALS['qacf_passes'] = 0;

/**
 * Record one check.
 *
 * @param bool   $ok    Passed.
 * @param string $label Label.
 * @param mixed  $extra Shown on failure.
 */
function qacf_check( $ok, $label, $extra = null ) {
	if ( $ok ) {
		++$GLOBALS['qacf_passes'];
		echo "  ok   {$label}\n";
		return;
	}
	++$GLOBALS['qacf_fails'];
	echo "  FAIL {$label}" . ( null !== $extra ? ' → ' . substr( (string) wp_json_encode( is_wp_error( $extra ) ? array( $extra->get_error_code(), $extra->get_error_message() ) : $extra ), 0, 400 ) : '' ) . "\n";
}

/**
 * Counter value.
 *
 * @param string $what Counter.
 * @return int
 */
function qacf_count( $what ) {
	wp_cache_delete( 'qaconfirm_counter_' . $what, 'options' );
	return (int) get_option( 'qaconfirm_counter_' . $what, 0 );
}

/**
 * REST call with a Bearer token: [http status, body array].
 *
 * @param object $endpoint Tools endpoint.
 * @param string $token    Token.
 * @param int    $uid      User.
 * @param string $tool     Tool.
 * @param array  $args     Body.
 * @return array
 */
function qacf_rest( $endpoint, $token, $uid, $tool, array $args ) {
	$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
	wp_set_current_user( $uid );
	$req = new WP_REST_Request( 'POST', '/goldt-webmcp-bridge/v1/tools/' . $tool );
	$req->set_url_params( array( 'tool' => $tool ) );
	$req->set_header( 'Content-Type', 'application/json' );
	$req->set_body( (string) wp_json_encode( $args ) );
	$r = $endpoint->execute_tool( $req );
	unset( $_SERVER['HTTP_AUTHORIZATION'] );
	wp_set_current_user( 0 );
	if ( is_wp_error( $r ) ) {
		$d = $r->get_error_data();
		return array( is_array( $d ) && isset( $d['status'] ) ? (int) $d['status'] : 400, array( 'code' => $r->get_error_code() ) );
	}
	$r = rest_ensure_response( $r );
	return array( (int) $r->get_status(), (array) $r->get_data() );
}

/**
 * Run as a user.
 *
 * @param int      $uid User.
 * @param callable $fn  Callback.
 * @return mixed
 */
function qacf_as( $uid, callable $fn ) {
	$prev = get_current_user_id();
	wp_set_current_user( $uid );
	try {
		return $fn();
	} finally {
		wp_set_current_user( $prev );
	}
}

global $wpdb;
$plugin   = GoldtWebMCP_Plugin::instance();
$endpoint = $plugin->get_tools_endpoint();
$gateway  = goldtwmcp_tool_gateway();
$oauth    = new \GoldtWebMCP\OAuth\OAuth_Server();
$tag      = 'qacf' . wp_rand( 1000, 9999 );
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
			$wpdb->delete( $wpdb->prefix . 'goldtwmcp_pending_actions', array( 'user_id' => (int) $uid ) );
			$wpdb->delete( $wpdb->prefix . 'goldtwmcp_autonomy_grants', array( 'user_id' => (int) $uid ) );
			$wpdb->delete( $wpdb->prefix . 'goldtwmcp_confirmation_log', array( 'user_id' => (int) $uid ) );
			wp_delete_user( $uid );
		}
		foreach ( array( 'refund', 'delete', 'status' ) as $c ) {
			delete_option( 'qaconfirm_counter_' . $c );
		}
		echo "\ncleanup: removed " . count( $users ) . ' users, ' . count( $tokens ) . " tokens, their pending actions, grants and log rows\n";
		echo "RESULT: {$GLOBALS['qacf_passes']} passed, {$GLOBALS['qacf_fails']} failed\n";
		exit( $GLOBALS['qacf_fails'] ? 1 : 0 );
	}
);

$make     = function ( $role, $suffix = '' ) use ( $tag, &$users ) {
	$id = wp_insert_user(
		array(
			'user_login' => $tag . '_' . $role . $suffix,
			'user_email' => $tag . '_' . $role . $suffix . '@example.invalid',
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
$editor   = $make( 'editor' );
$other    = $make( 'editor', '2' );
$issue    = function ( $uid, array $scopes ) use ( $oauth, &$tokens ) {
	$t        = $oauth->create_access_token( 'claude-ai', $uid, $scopes, 'oauth' );
	$tokens[] = $t['access_token'];
	return $t['access_token'];
};
$tok_all  = $issue( $editor, array( 'read', 'write', 'delete' ) );
$tok_read = $issue( $editor, array( 'read' ) );

Confirmation::maybe_install();
$qa = new QAConfirm_Module( $plugin->get_manifest_instance() );
$endpoint->register_module( $qa );

echo "== Manifest ==\n";
$manifest = $plugin->get_manifest_instance()->generate();
$by_name  = array();
foreach ( $manifest['tools'] as $t ) {
	$by_name[ $t['name'] ] = $t;
}
qacf_check( 'required' === ( $by_name['qaconfirm.refundThing']['confirmation'] ?? null ) && 'financial' === ( $by_name['qaconfirm.refundThing']['risk'] ?? null ), 'refundThing: confirmation=required, risk=financial' );
qacf_check( ! isset( $by_name['qaconfirm.refundThing']['input_schema']['properties']['confirm'] ) && ! in_array( 'confirm', $by_name['qaconfirm.refundThing']['input_schema']['required'], true ), 'refundThing: agent confirm argument removed from schema' );
qacf_check( false !== strpos( $by_name['qaconfirm.refundThing']['description'], 'confirmation_required' ), 'refundThing: description tells the agent about the user step' );
qacf_check( 'conditional' === ( $by_name['qaconfirm.setThingStatus']['confirmation'] ?? null ), 'setThingStatus: confirmation=conditional' );
qacf_check( ! isset( $by_name['qaconfirm.getThing']['confirmation'] ), 'read tool declaring confirmation stays ungated' );
qacf_check( isset( $manifest['confirmation']['flow'] ) && Confirmation::FLOW === $manifest['confirmation']['flow'] && in_array( 'qaconfirm.deleteThing', $manifest['confirmation']['tools'], true ), 'top-level confirmation descriptor lists gated tools' );
qacf_check( false !== strpos( (string) ( $manifest['instructions'] ?? '' ), 'CONFIRMATION' ), 'manifest instructions explain the confirmation step' );

echo "\n== Direct call without the user's confirmation ==\n";
list( $st, $body ) = qacf_rest(
	$endpoint,
	$tok_all,
	$editor,
	'qaconfirm.refundThing',
	array(
		'thing_id' => 7,
		'amount'   => 25,
		'confirm'  => true,
	)
);
qacf_check( 202 === $st && 'confirmation_required' === ( $body['code'] ?? '' ), 'REST refundThing with confirm:true → 202 confirmation_required', array( $st, $body ) );
qacf_check( 0 === qacf_count( 'refund' ), 'no side effect (agent confirm:true ignored)' );
qacf_check( 25 === ( $body['preview']['amount'] ?? null ) && 7 === ( $body['preview']['thing_id'] ?? null ), 'preview built by the tool itself', $body['preview'] ?? null );
qacf_check( preg_match( '/^conf_[a-z0-9]{32}$/', (string) ( $body['confirmation_id'] ?? '' ) ) && false !== strpos( (string) ( $body['confirm_url'] ?? '' ), Confirmation::PAGE_SLUG ) && ! empty( $body['status_url'] ) && ! empty( $body['expires_at'] ), 'envelope: confirmation_id, confirm_url, status_url, expires_at' );
qacf_check( 'Refund thing #7' === ( $body['summary'] ?? '' ), 'summary filled from arguments', $body['summary'] ?? null );
$cid = (string) ( $body['confirmation_id'] ?? '' );

list( $st2, $body2 ) = qacf_rest(
	$endpoint,
	$tok_all,
	$editor,
	'qaconfirm.refundThing',
	array(
		'thing_id' => 7,
		'amount'   => 25,
	)
);
qacf_check( 202 === $st2 && $cid === ( $body2['confirmation_id'] ?? '' ), 'same call again → same pending action (no duplicate)', $body2 );

$g = $gateway->call_as( $editor, 'qaconfirm.deleteThing', array( 'thing_id' => 3 ), array( 'caller' => 'qa-test' ) );
qacf_check( 202 === $g['status'] && 'confirmation_required' === ( $g['error']['code'] ?? '' ) && 'confirmation' === $g['denied_layer'] && ! empty( $g['confirmation']['confirm_url'] ), 'in-process gateway deleteThing → 202 confirmation_required', $g );
qacf_check( 0 === qacf_count( 'delete' ), 'gateway: no side effect' );
qacf_check( array( 'arguments' => array( 'thing_id' => 3 ) ) === ( $g['confirmation']['preview'] ?? null ), 'no preview callback → preview = the arguments', $g['confirmation']['preview'] ?? null );
$del_id = (string) ( $g['confirmation']['confirmation_id'] ?? '' );

list( $st3, $body3 ) = qacf_rest( $endpoint, $tok_read, $editor, 'qaconfirm.deleteThing', array( 'thing_id' => 3 ) );
qacf_check( 403 === $st3 && 'insufficient_scope' === ( $body3['code'] ?? '' ), 'read-only token never reaches the gate (insufficient_scope)', array( $st3, $body3 ) );

echo "\n== Re-submission while pending ==\n";
list( $st, $body ) = qacf_rest(
	$endpoint,
	$tok_all,
	$editor,
	'qaconfirm.refundThing',
	array(
		'thing_id'        => 7,
		'amount'          => 25,
		'confirmation_id' => $cid,
	)
);
qacf_check( 202 === $st && $cid === ( $body['confirmation_id'] ?? '' ), 'with confirmation_id, still pending → 202', array( $st, $body ) );
qacf_check( 0 === qacf_count( 'refund' ), 'agent cannot confirm by re-submitting' );

echo "\n== Status URL ==\n";
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $tok_all;
wp_set_current_user( $editor );
$req = new WP_REST_Request( 'GET', '/goldt-webmcp-bridge/v1/confirmations/' . $cid );
$req->set_url_params( array( 'id' => $cid ) );
$s = rest_ensure_response( $endpoint->get_confirmation_status( $req ) )->get_data();
qacf_check( 'pending' === ( $s['status'] ?? '' ) && ! empty( $s['confirm_url'] ), 'status_url → pending', $s );
wp_set_current_user( $other );
$s2 = $endpoint->get_confirmation_status( $req );
qacf_check( is_wp_error( $s2 ) && 'confirmation_not_found' === $s2->get_error_code(), 'status_url of another user → 404', $s2 );
unset( $_SERVER['HTTP_AUTHORIZATION'] );
wp_set_current_user( 0 );

echo "\n== Confirm page session check ==\n";
qacf_check( false === qacf_as( $editor, array( '\GoldtWebMCP\User\Confirmation_Page', 'is_human_session' ) ), 'no logged-in cookie (CLI / Bearer) → not a human session' );
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $tok_all;
qacf_check( false === qacf_as( $editor, array( '\GoldtWebMCP\User\Confirmation_Page', 'is_human_session' ) ), 'Authorization header present → refused' );
unset( $_SERVER['HTTP_AUTHORIZATION'] );

echo "\n== The user confirms ==\n";
$o = qacf_as( $other, fn() => Confirmation::confirm( $cid ) );
qacf_check( ! $o['ok'] && 'not_found' === $o['status'] && 0 === qacf_count( 'refund' ), 'another user cannot confirm it', $o );
$o = qacf_as( $editor, fn() => Confirmation::confirm( $cid ) );
qacf_check( $o['ok'] && 'executed' === $o['status'] && 1 === qacf_count( 'refund' ), 'owner confirms → executes once', $o );
qacf_check( true === ( $o['result']['refunded'] ?? null ), 'tool ran in confirmed mode (its own check passed)', $o );

echo "\n== Replay protection ==\n";
$o = qacf_as( $editor, fn() => Confirmation::confirm( $cid ) );
qacf_check( ! $o['ok'] && 'executed' === $o['status'] && 1 === qacf_count( 'refund' ), 'confirming again → refused, still 1 execution', $o );
list( $st, $body ) = qacf_rest(
	$endpoint,
	$tok_all,
	$editor,
	'qaconfirm.refundThing',
	array(
		'thing_id'        => 7,
		'amount'          => 25,
		'confirmation_id' => $cid,
	)
);
qacf_check( 200 === $st && 'executed' === ( $body['confirmation']['status'] ?? '' ) && 1 === ( $body['count'] ?? null ) && 1 === qacf_count( 'refund' ), 're-submission after execution → stored result, no second run', array( $st, $body ) );
list( $st, $body ) = qacf_rest(
	$endpoint,
	$tok_all,
	$editor,
	'qaconfirm.refundThing',
	array(
		'thing_id'        => 8,
		'amount'          => 25,
		'confirmation_id' => $cid,
	)
);
qacf_check( 409 === $st && 'confirmation_mismatch' === ( $body['code'] ?? '' ), 'confirmation_id with different arguments → confirmation_mismatch', array( $st, $body ) );
list( $st, $body ) = qacf_rest(
	$endpoint,
	$tok_all,
	$editor,
	'qaconfirm.refundThing',
	array(
		'thing_id' => 7,
		'amount'   => 25,
	)
);
qacf_check( 200 === $st && true === ( $body['confirmation']['already_executed'] ?? null ) && $cid === ( $body['confirmation']['confirmation_id'] ?? '' ) && 1 === qacf_count( 'refund' ), '1.2.14: the same call right after the user confirmed → the stored result, no second request', array( $st, $body ) );
qacf_check( 0 === strpos( (string) ( $body['message'] ?? '' ), 'Already executed' ) && false !== strpos( (string) ( $body['confirmation']['note'] ?? '' ), 'confirmation_new' ), '… and the answer says plainly: already executed, not run again', $body );
qacf_check( 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}goldtwmcp_pending_actions WHERE user_id = %d AND tool = %s AND status = 'pending'", $editor, 'qaconfirm.refundThing' ) ), '… and nothing new waits for the user' );
list( $st, $body ) = qacf_rest(
	$endpoint,
	$tok_all,
	$editor,
	'qaconfirm.refundThing',
	array(
		'thing_id'         => 7,
		'amount'           => 25,
		'confirmation_new' => true,
	)
);
qacf_check( 202 === $st && $cid !== ( $body['confirmation_id'] ?? '' ) && 1 === qacf_count( 'refund' ), 'confirmation_new: true → a NEW confirmation request (the user asked again)', array( $st, $body ) );
$second = (string) ( $body['confirmation_id'] ?? '' );

echo "\n== Reject + expiry ==\n";
$ok = qacf_as( $editor, fn() => Confirmation::reject( $second ) );
qacf_check( $ok, 'owner rejects the second refund' );
$o = qacf_as( $editor, fn() => Confirmation::confirm( $second ) );
qacf_check( ! $o['ok'] && 1 === qacf_count( 'refund' ), 'rejected action cannot be confirmed', $o );
list( $st, $body ) = qacf_rest(
	$endpoint,
	$tok_all,
	$editor,
	'qaconfirm.refundThing',
	array(
		'thing_id'        => 7,
		'amount'          => 25,
		'confirmation_id' => $second,
	)
);
qacf_check( 410 === $st && 'confirmation_rejected' === ( $body['code'] ?? '' ), 're-submission of a rejected action → 410 confirmation_rejected', array( $st, $body ) );
$wpdb->update( $wpdb->prefix . 'goldtwmcp_pending_actions', array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'id' => $del_id ) );
$o = qacf_as( $editor, fn() => Confirmation::confirm( $del_id ) );
qacf_check( ! $o['ok'] && 0 === qacf_count( 'delete' ), 'expired action cannot be confirmed', $o );
qacf_check( 'expired' === ( Confirmation::get_action( $del_id )['status'] ?? '' ), 'expired action is marked expired' );

echo "\n== Autonomy grant ==\n";
qacf_check( Confirmation::grant( $editor, 'qaconfirm.deleteThing', 'inprocess:qa-test' ), 'grant: editor lets inprocess:qa-test run deleteThing' );
$g = $gateway->call_as( $editor, 'qaconfirm.deleteThing', array( 'thing_id' => 4 ), array( 'caller' => 'qa-test' ) );
qacf_check( 200 === $g['status'] && 1 === qacf_count( 'delete' ), 'granted client → runs directly', $g );
$g = $gateway->call_as( $editor, 'qaconfirm.deleteThing', array( 'thing_id' => 4 ), array( 'caller' => 'other-agent' ) );
qacf_check( 202 === $g['status'] && 1 === qacf_count( 'delete' ), 'another client is still asked', $g );
$g = $gateway->call_as( $other, 'qaconfirm.deleteThing', array( 'thing_id' => 4 ), array( 'caller' => 'qa-test' ) );
qacf_check( 202 === $g['status'] && 1 === qacf_count( 'delete' ), 'another user with the same client is still asked', $g );
$grant = Confirmation::find_grant( $editor, 'qaconfirm.deleteThing', 'inprocess:qa-test' );
qacf_check( ! Confirmation::revoke_grant( (int) $grant['id'], $other ), 'another user cannot revoke the grant' );
qacf_check( Confirmation::revoke_grant( (int) $grant['id'], $editor ), 'owner revokes the grant' );
$g = $gateway->call_as( $editor, 'qaconfirm.deleteThing', array( 'thing_id' => 4 ), array( 'caller' => 'qa-test' ) );
qacf_check( 202 === $g['status'] && 1 === qacf_count( 'delete' ), 'after revoke → back to confirmation', $g );
add_filter( 'goldtwmcp_confirmation_autonomy_allowed', '__return_false' );
Confirmation::grant( $editor, 'qaconfirm.deleteThing', 'inprocess:qa-test' );
$g = $gateway->call_as( $editor, 'qaconfirm.deleteThing', array( 'thing_id' => 4 ), array( 'caller' => 'qa-test' ) );
qacf_check( 202 === $g['status'] && 1 === qacf_count( 'delete' ), 'site switched autonomy off → grant ignored', $g );
remove_filter( 'goldtwmcp_confirmation_autonomy_allowed', '__return_false' );

$o = qacf_as( $editor, fn() => Confirmation::confirm( (string) $g['confirmation']['confirmation_id'], true ) );
qacf_check( $o['ok'] && 2 === qacf_count( 'delete' ), 'confirm with "let this agent act" → executes', $o );
qacf_check( null !== Confirmation::find_grant( $editor, 'qaconfirm.deleteThing', 'inprocess:qa-test' ), '… and records the grant' );

echo "\n== Conditional confirmation ==\n";
$g = $gateway->call_as(
	$editor,
	'qaconfirm.setThingStatus',
	array(
		'thing_id' => 1,
		'status'   => 'open',
	),
	array( 'caller' => 'qa-test' )
);
qacf_check( 200 === $g['status'] && 1 === qacf_count( 'status' ), 'safe variant (status=open) runs directly', $g );
$g = $gateway->call_as(
	$editor,
	'qaconfirm.setThingStatus',
	array(
		'thing_id' => 1,
		'status'   => 'cancelled',
	),
	array( 'caller' => 'qa-test' )
);
qacf_check( 202 === $g['status'] && 1 === qacf_count( 'status' ) && 'cancelled' === ( $g['confirmation']['preview']['to'] ?? '' ), 'risky variant (status=cancelled) → 202 with callable preview', $g );

echo "\n== 1.2.14: duplicate window ==\n";
$dup_args = array(
	'thing_id' => 21,
	'amount'   => 5,
);
list( $st, $body ) = qacf_rest( $endpoint, $tok_all, $editor, 'qaconfirm.refundThing', $dup_args );
$dup_id            = (string) ( $body['confirmation_id'] ?? '' );
$before            = qacf_count( 'refund' );
$o                 = qacf_as( $editor, fn() => Confirmation::confirm( $dup_id ) );
qacf_check( $o['ok'] && $before + 1 === qacf_count( 'refund' ), 'fixture: user confirms refund of thing 21', $o );
// The user took 10 minutes to come back to the agent; the agent never collected the result.
$wpdb->update( $wpdb->prefix . 'goldtwmcp_pending_actions', array( 'decided_at' => gmdate( 'Y-m-d H:i:s', time() - 600 ) ), array( 'id' => $dup_id ) );
list( $st, $body ) = qacf_rest( $endpoint, $tok_all, $editor, 'qaconfirm.refundThing', $dup_args );
qacf_check( 200 === $st && $dup_id === ( $body['confirmation']['confirmation_id'] ?? '' ) && $before + 1 === qacf_count( 'refund' ), 'result not collected yet (10 min later) → stored result, no second request', array( $st, $body ) );
qacf_check( ! empty( Confirmation::get_action( $dup_id )['delivered_at'] ), '… and the result is now marked as collected' );
list( $st, $body ) = qacf_rest( $endpoint, $tok_all, $editor, 'qaconfirm.refundThing', $dup_args );
qacf_check( 202 === $st && $dup_id !== ( $body['confirmation_id'] ?? '' ) && $before + 1 === qacf_count( 'refund' ), 'collected and outside the short window → an identical call is a new request again', array( $st, $body ) );
qacf_as( $editor, fn() => Confirmation::reject( (string) ( $body['confirmation_id'] ?? '' ) ) );
list( $st, $body ) = qacf_rest( $endpoint, $tok_all, $other, 'qaconfirm.refundThing', $dup_args );
qacf_check( 403 === $st || ( 202 === $st && $dup_id !== ( $body['confirmation_id'] ?? '' ) ), 'another user never gets the first user\'s result', array( $st, $body ) );
add_filter( 'goldtwmcp_confirmation_duplicate_window', '__return_zero' );
$wpdb->update( $wpdb->prefix . 'goldtwmcp_pending_actions', array( 'decided_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $dup_id ) );
list( $st, $body ) = qacf_rest( $endpoint, $tok_all, $editor, 'qaconfirm.refundThing', $dup_args );
qacf_check( 202 === $st, 'filter goldtwmcp_confirmation_duplicate_window = 0 switches it off', array( $st, $body ) );
qacf_as( $editor, fn() => Confirmation::reject( (string) ( $body['confirmation_id'] ?? '' ) ) );
remove_filter( 'goldtwmcp_confirmation_duplicate_window', '__return_zero' );

echo "\n== 1.2.14: the user's summary ==\n";
$agent_text = array( 'Not executed', 'Do NOT', 'confirmation_id', 'call this tool' );
$clean      = static function ( $text ) use ( $agent_text ) {
	if ( ! is_string( $text ) || '' === trim( $text ) ) {
		return false;
	}
	foreach ( $agent_text as $needle ) {
		if ( false !== stripos( $text, $needle ) ) {
			return false;
		}
	}
	return true;
};
$g          = $gateway->call_as( $editor, 'qaconfirm.deleteThing', array( 'thing_id' => 31 ), array( 'caller' => 'qa-summary' ) );
qacf_check( Confirmation::generic_summary( 'qaconfirm.deleteThing' ) === ( $g['confirmation']['summary'] ?? '' ) && 'Run the action “delete thing”' === $g['confirmation']['summary'], 'tool that declares no summary → generic sentence, never empty', $g['confirmation']['summary'] ?? null );
$row = Confirmation::get_action( (string) $g['confirmation']['confirmation_id'] );
qacf_check( '' !== (string) $row['summary'], '… and it is stored with the pending action', $row['summary'] );
$g = $gateway->call_as( $editor, 'qaconfirm.wipeThings', array( 'older_than_days' => 14 ), array( 'caller' => 'qa-summary' ) );
qacf_check( 'Delete 3 things older than 14 days' === ( $g['confirmation']['summary'] ?? '' ), 'preview[summary] from the tool wins over the template (real numbers)', $g['confirmation'] ?? null );
qacf_check( ! isset( $g['confirmation']['preview']['summary'] ) && array( 'This cannot be undone.' ) === ( $g['confirmation']['preview']['details'] ?? null ), '… summary is not repeated inside the preview; details stay', $g['confirmation']['preview'] ?? null );
qacf_check( $clean( $g['confirmation']['summary'] ?? '' ) && $clean( $g['confirmation']['user_message'] ?? '' ) && false !== strpos( (string) $g['confirmation']['user_message'], 'Delete 3 things older than 14 days' ), 'summary + user_message are for the user (no agent instructions in them)', $g['confirmation'] ?? null );
qacf_check( false !== strpos( (string) ( $g['error']['message'] ?? '' ), 'Delete 3 things older than 14 days' ) && false !== strpos( (string) $g['error']['message'], 'never show it to the user' ), 'the agent message quotes the summary and says it is not for the user', $g['error'] ?? null );
$wipe_id = (string) $g['confirmation']['confirmation_id'];
$wpdb->update( $wpdb->prefix . 'goldtwmcp_pending_actions', array( 'summary' => '' ), array( 'id' => $wipe_id ) );
$env = Confirmation::pending_envelope( Confirmation::get_action( $wipe_id ) );
qacf_check( $clean( $env['summary'] ), 'a row stored without a summary (written by 1.2.13) still gets one', $env['summary'] );
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $tok_all;
wp_set_current_user( $editor );
$req = new WP_REST_Request( 'GET', '/goldt-webmcp-bridge/v1/confirmations/' . $wipe_id );
$req->set_url_params( array( 'id' => $wipe_id ) );
$s = rest_ensure_response( $endpoint->get_confirmation_status( $req ) )->get_data();
qacf_check( $clean( $s['summary'] ?? '' ), 'status_url carries the summary too', $s );
unset( $_SERVER['HTTP_AUTHORIZATION'] );
wp_set_current_user( 0 );
$long = Confirmation::resolve_summary( array( 'summary' => str_repeat( 'מחיקה ', 80 ) ), 'qaconfirm.deleteThing', array(), array() );
qacf_check( mb_strlen( $long ) <= 255 && mb_check_encoding( $long, 'UTF-8' ), 'a long non-Latin summary is cut on a character boundary (≤ 255 characters)', mb_strlen( $long ) );
qacf_check( 'Refund thing #9 of 12.5' === Confirmation::resolve_summary( array( 'summary' => 'Refund thing #{thing_id} of {total}' ), 'x.y', array( 'thing_id' => 9 ), array( 'total' => 12.5 ) ), 'template placeholders: arguments first, then preview values' );
qacf_check( 'Deletes all of it' === Confirmation::resolve_summary( array( 'summary' => "<b>Deletes</b>  all\n of it" ), 'x.y', array(), array() ), 'markup and line breaks are stripped from a summary' );

// The user's own language (Hebrew ships with the bridge).
update_user_meta( $editor, 'locale', 'he_IL' );
$g = $gateway->call_as( $editor, 'qaconfirm.deleteThing', array( 'thing_id' => 32 ), array( 'caller' => 'qa-summary' ) );
qacf_check( 1 === preg_match( '/\p{Hebrew}/u', (string) ( $g['confirmation']['summary'] ?? '' ) ) && 1 === preg_match( '/\p{Hebrew}/u', (string) ( $g['confirmation']['user_message'] ?? '' ) ), 'a Hebrew user gets the summary and user_message in Hebrew', $g['confirmation'] ?? null );
delete_user_meta( $editor, 'locale' );

// The confirm page: the summary is the headline, the tool name is a technical detail.
$page = new \GoldtWebMCP\User\Confirmation_Page();
wp_set_current_user( $editor );
$_GET['confirmation_id'] = $wipe_id;
$wpdb->update( $wpdb->prefix . 'goldtwmcp_pending_actions', array( 'summary' => 'Delete 3 things older than 14 days' ), array( 'id' => $wipe_id ) );
ob_start();
$page->render();
$html = (string) ob_get_clean();
unset( $_GET['confirmation_id'] );
wp_set_current_user( 0 );
qacf_check( false !== strpos( $html, '<strong>Delete 3 things older than 14 days</strong>' ) && false !== strpos( $html, 'This cannot be undone.' ) && false !== strpos( $html, 'Confirm and run' ), 'confirm page: summary headline + what is lost + buttons', substr( wp_strip_all_tags( $html ), 0, 400 ) );
qacf_check( $clean( wp_strip_all_tags( $html ) ), 'confirm page shows no agent-facing text' );

echo "\n== Audit log ==\n";
$events = array_column( Confirmation::list_log( $editor, 200 ), 'event' );
foreach ( array( 'requested', 'reused', 'confirmed', 'executed', 'replay_blocked', 'duplicate_blocked', 'mismatch', 'rejected', 'expired', 'autonomy_granted', 'autonomous_execute', 'autonomy_revoked' ) as $e ) {
	qacf_check( in_array( $e, $events, true ), "audit event {$e}" );
}
