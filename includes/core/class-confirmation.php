<?php
/**
 * Confirmation flow (user-confirm-v1): destructive and financial tools need the
 * user's own confirmation, enforced in code.
 *
 * @package GoldtWebMCP
 */

namespace GoldtWebMCP\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A tool registered with `confirmation` is never executed by an agent call alone.
 *
 * Flow (spec: CONFIRMATION-FLOW-SPEC.md, Servio SPEC §6.5 extended):
 *   1. The agent calls the tool. The bridge does NOT run it: it stores a pending
 *      action (user + tool + arguments hash + client, single use, expires) and
 *      answers 202 `confirmation_required` with a preview, `confirmation_id`,
 *      `confirm_url` and `status_url`.
 *   2. The user opens confirm_url in their own logged-in browser session
 *      (wp-admin, auth cookie + nonce; Bearer tokens are refused there), sees the
 *      preview and confirms or rejects. Confirming runs the tool once, as that user.
 *   3. The agent learns the outcome by calling the tool again with the same
 *      arguments plus `confirmation_id`, or by GET status_url. Neither ever runs the
 *      tool: a re-submission after execution returns the stored result.
 *
 * The agent's own `confirm: true` argument is ignored for these tools — it is
 * removed from the schema and from the arguments; only the user step sets it.
 *
 * Autonomy: a user may let one client run one tool without asking (per user, per
 * tool, per client, revocable, audited). Site owners can switch autonomy off
 * with the goldtwmcp_confirmation_autonomy_allowed filter.
 *
 * Tool declaration (register_tool config):
 *   'confirmation' => 'required'
 *   'confirmation' => array(
 *       'required' => true | callable( array $args ): bool,   // conditional
 *       'risk'     => 'financial' | 'destructive',
 *       'summary'  => 'Records a refund for order #{order_id}',  // shown to the user
 *       'preview'  => null | 'self' | callable( array $args ): array|\WP_Error,
 *   )
 * preview 'self' = the tool callback itself builds the preview: it is called in
 * preview mode (is_preview() true) and must return a `confirmation_required`
 * WP_Error carrying data['preview'] BEFORE any side effect. Only use 'self' for a
 * callback that checks is_confirmed_execution() (or the add-on helper does).
 *
 * @since 1.2.13
 */
class Confirmation {

	const FLOW        = 'user-confirm-v1';
	const DB_VERSION  = '1.0.0';
	const DB_OPTION   = 'goldtwmcp_confirmation_db_version';
	const PAGE_SLUG   = 'goldtwmcp-ai-actions';
	const DEFAULT_TTL = 900;
	const KEEP_DAYS   = 30;

	/**
	 * Current mode: null (normal agent call), 'preview' or 'confirmed'.
	 *
	 * @var string|null
	 */
	private static $mode = null;

	/**
	 * In confirmed mode: the only tool + arguments hash allowed to run.
	 *
	 * @var array|null
	 */
	private static $expected = null;

	/**
	 * Who is calling (set by REST / Servio route / in-process gateway).
	 *
	 * @var array{channel: string, client_id: string}
	 */
	private static $context = array(
		'channel'   => 'rest',
		'client_id' => '',
	);

	// ---------------------------------------------------------------- state.

	/**
	 * True only while the bridge runs a tool the user confirmed (or granted autonomy for).
	 *
	 * @return bool
	 */
	public static function is_confirmed_execution() {
		return 'confirmed' === self::$mode;
	}

	/**
	 * True while a 'self' preview callback is being asked for its preview.
	 *
	 * @return bool
	 */
	public static function is_preview() {
		return 'preview' === self::$mode;
	}

	/**
	 * Set the caller context for the next tool calls (REST: client_id of the token).
	 *
	 * @param string $channel   rest | servio | inprocess.
	 * @param string $client_id OAuth client_id, or the in-process caller name.
	 * @return void
	 */
	public static function set_context( $channel, $client_id ) {
		self::$context = array(
			'channel'   => sanitize_key( (string) $channel ),
			'client_id' => substr( sanitize_text_field( (string) $client_id ), 0, 80 ),
		);
	}

	/**
	 * Current caller context.
	 *
	 * @return array{channel: string, client_id: string}
	 */
	public static function get_context() {
		return self::$context;
	}

	/**
	 * Client key used for autonomy grants: "{channel}:{client_id}".
	 *
	 * @return string
	 */
	private static function client_key() {
		$c = self::$context;
		return ( '' !== $c['channel'] ? $c['channel'] : 'rest' ) . ':' . ( '' !== $c['client_id'] ? $c['client_id'] : 'unknown' );
	}

	// ------------------------------------------------------- declarations.

	/**
	 * Normalise a register_tool 'confirmation' value. Null = not gated.
	 *
	 * @param mixed  $value          Declaration.
	 * @param string $required_scope Tool scope (read tools are never gated).
	 * @return array|null
	 */
	public static function normalize( $value, $required_scope ) {
		if ( empty( $value ) || 'none' === $value ) {
			return null;
		}
		if ( 'read' === $required_scope ) {
			// A read tool has no side effect to confirm; refusing it would only break reads.
			return null;
		}
		if ( ! is_array( $value ) ) {
			$value = array( 'required' => true );
		}
		$risk = isset( $value['risk'] ) && in_array( $value['risk'], array( 'financial', 'destructive' ), true ) ? $value['risk'] : 'destructive';
		return array(
			'required' => array_key_exists( 'required', $value ) ? $value['required'] : true,
			'risk'     => $risk,
			'summary'  => isset( $value['summary'] ) ? (string) $value['summary'] : '',
			'preview'  => $value['preview'] ?? null,
		);
	}

	/**
	 * Remove the agent-side `confirm` argument from a gated tool's schema.
	 *
	 * @param array $schema Input schema.
	 * @return array
	 */
	public static function strip_confirm_from_schema( array $schema ) {
		if ( isset( $schema['properties']['confirm'] ) ) {
			unset( $schema['properties']['confirm'] );
		}
		if ( isset( $schema['required'] ) && is_array( $schema['required'] ) ) {
			$schema['required'] = array_values( array_diff( $schema['required'], array( 'confirm' ) ) );
		}
		return $schema;
	}

	/**
	 * Sentence appended to a gated tool's description, so agents know before calling.
	 *
	 * @param array $conf Normalised declaration.
	 * @return string
	 */
	public static function description_suffix( array $conf ) {
		$when = is_callable( $conf['required'] ) ? 'For the risky variants of this call, the' : 'The';
		return ' ' . $when . ' site requires the USER\'s own confirmation (' . $conf['risk'] . '): the call returns HTTP 202 confirmation_required with a preview and confirm_url instead of running. Show the preview and the link to the user and wait; do not retry on your own. After the user confirms, call again with confirmation_id to get the result.';
	}

	// ------------------------------------------------------------- the gate.

	/**
	 * Gate a gated tool call. Called from Module_Base::execute_tool after validation.
	 *
	 * @param \GoldtWebMCP\Modules\Module_Base $module    Module.
	 * @param string                           $method    Tool name without module prefix.
	 * @param array                            $tool      Tool definition (name, callback, confirmation, required_scope).
	 * @param array                            $raw       Raw arguments (may carry confirmation_id).
	 * @param array                            $validated Validated arguments; on "proceed" confirm=true is added.
	 * @return array|\WP_Error|null Null = run the tool now with $validated; otherwise the answer.
	 */
	public static function gate( $module, $method, array $tool, array $raw, array &$validated ) {
		$conf    = $tool['confirmation'];
		$full    = (string) $tool['name'];
		$user_id = (int) \get_current_user_id();
		$hash    = self::args_hash( $validated );

		// Confirmed execution started by the user step: only the expected tool + arguments.
		if ( self::is_confirmed_execution() ) {
			if ( self::$expected && self::$expected['tool'] === $full && hash_equals( self::$expected['hash'], $hash ) ) {
				$validated['confirm'] = true;
				return null;
			}
			return new \WP_Error( 'confirmation_mismatch', 'Only the confirmed action may run.', array( 'status' => 409 ) );
		}
		if ( self::is_preview() ) {
			// A preview callback must never trigger another gated tool.
			return new \WP_Error( 'confirmation_required', 'Nested gated call refused during preview.', array( 'status' => 409 ) );
		}

		// Conditional requirement (e.g. only status=cancelled).
		if ( is_callable( $conf['required'] ) && ! call_user_func( $conf['required'], $validated ) ) {
			return null;
		}
		// From here on the agent's own confirm flag means nothing.
		unset( $validated['confirm'] );

		if ( $user_id <= 0 ) {
			return new \WP_Error( 'confirmation_unavailable', 'This action needs a signed-in user who can confirm it.', array( 'status' => 403 ) );
		}

		self::maybe_install();

		// Re-submission with confirmation_id (Servio §6.5): report, never execute.
		$conf_id = isset( $raw['confirmation_id'] ) ? (string) $raw['confirmation_id'] : '';
		if ( '' !== $conf_id ) {
			return self::resubmission( $conf_id, $full, $hash, $user_id );
		}

		// Autonomy grant: the user let this client run this tool without asking.
		$grant = self::find_grant( $user_id, $full, self::client_key() );
		if ( $grant && self::autonomy_allowed( $full, $conf ) ) {
			self::log( 'autonomous_execute', '', $user_id, $full, array( 'grant_id' => (int) $grant['id'] ) );
			$validated['confirm'] = true;
			self::$mode           = 'confirmed';
			self::$expected       = array(
				'tool' => $full,
				'hash' => $hash,
			);
			try {
				return self::run_callback( $tool, $validated );
			} finally {
				self::$mode     = null;
				self::$expected = null;
			}
		}

		// Same request already waiting for the user: hand back the same pending action.
		$existing = self::find_pending( $user_id, $full, $hash, self::client_key() );
		if ( $existing ) {
			self::log( 'reused', $existing['id'], $user_id, $full );
			return self::pending_envelope( $existing );
		}

		// Build the preview (no side effect).
		$preview = self::build_preview( $conf, $tool, $validated );
		if ( \is_wp_error( $preview ) ) {
			return $preview;
		}
		if ( isset( $preview['__completed'] ) ) {
			// The tool answered without needing confirmation (dry run, report-only…).
			return $preview['__completed'];
		}

		$action = self::create_pending( $user_id, $full, $validated, $hash, $conf, $preview );
		if ( \is_wp_error( $action ) ) {
			return $action;
		}
		return self::pending_envelope( $action );
	}

	/**
	 * Run the tool callback (exceptions → WP_Error, like Module_Base).
	 *
	 * @param array $tool Tool definition.
	 * @param array $args Arguments.
	 * @return mixed
	 */
	private static function run_callback( array $tool, array $args ) {
		if ( ! is_callable( $tool['callback'] ) ) {
			return new \WP_Error( 'tool_not_callable', 'Tool is not callable' );
		}
		try {
			return call_user_func( $tool['callback'], $args );
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'tool_execution_error', $e->getMessage() );
		}
	}

	/**
	 * Build the preview for a pending action.
	 *
	 * @param array $conf      Declaration.
	 * @param array $tool      Tool definition.
	 * @param array $validated Arguments (without confirm).
	 * @return array|\WP_Error Preview array, or ['__completed' => result] when the tool already answered.
	 */
	private static function build_preview( array $conf, array $tool, array $validated ) {
		$preview = $conf['preview'];
		if ( 'self' === $preview ) {
			self::$mode = 'preview';
			try {
				$out = self::run_callback( $tool, $validated );
			} finally {
				self::$mode = null;
			}
			if ( \is_wp_error( $out ) ) {
				if ( 'confirmation_required' !== $out->get_error_code() ) {
					return $out;
				}
				$data = $out->get_error_data();
				$p    = is_array( $data ) && isset( $data['preview'] ) && is_array( $data['preview'] ) ? $data['preview'] : array();
				if ( '' !== $out->get_error_message() ) {
					$p = array_merge( array( 'what' => $out->get_error_message() ), $p );
				}
				return $p;
			}
			// No confirmation needed for this variant (e.g. dry_run): the tool already answered.
			return array( '__completed' => $out );
		}
		if ( is_callable( $preview ) ) {
			$p = call_user_func( $preview, $validated );
			if ( \is_wp_error( $p ) ) {
				return $p;
			}
			return is_array( $p ) ? $p : array( 'preview' => $p );
		}
		return array( 'arguments' => $validated );
	}

	/**
	 * Answer to a re-submission carrying confirmation_id.
	 *
	 * @param string $conf_id Confirmation ID.
	 * @param string $full    Tool.
	 * @param string $hash    Arguments hash.
	 * @param int    $user_id User.
	 * @return array|\WP_Error
	 */
	private static function resubmission( $conf_id, $full, $hash, $user_id ) {
		$action = self::get_action( $conf_id );
		if ( ! $action || (int) $action['user_id'] !== $user_id ) {
			return new \WP_Error( 'confirmation_not_found', 'Unknown confirmation_id for this user.', array( 'status' => 404 ) );
		}
		if ( $action['tool'] !== $full || ! hash_equals( (string) $action['args_hash'], $hash ) ) {
			self::log( 'mismatch', $conf_id, $user_id, $full );
			return new \WP_Error( 'confirmation_mismatch', 'confirmation_id belongs to a different tool or different arguments. A confirmation covers exactly the previewed call.', array( 'status' => 409 ) );
		}
		switch ( $action['status'] ) {
			case 'pending':
				return self::pending_envelope( $action );
			case 'executed':
			case 'failed':
				self::log( 'replay_blocked', $conf_id, $user_id, $full );
				return self::executed_answer( $action );
			case 'rejected':
				return new \WP_Error( 'confirmation_rejected', 'The user rejected this action. Do not retry it unless the user asks again.', array( 'status' => 410 ) );
			case 'executing':
				return new \WP_Error( 'confirmation_in_progress', 'The user confirmed; the action is running. Check status_url shortly.', array( 'status' => 409 ) );
			default:
				return new \WP_Error( 'confirmation_expired', 'This confirmation expired before the user confirmed it. Ask the user whether to start again.', array( 'status' => 410 ) );
		}
	}

	/**
	 * Stored result of an executed action (never re-executed).
	 *
	 * @param array $action Action row.
	 * @return array|\WP_Error
	 */
	private static function executed_answer( array $action ) {
		$result = json_decode( (string) $action['result_json'], true );
		$meta   = array(
			'confirmation_id' => $action['id'],
			'status'          => $action['status'],
			'executed_at'     => self::iso( $action['decided_at'] ),
			'note'            => 'Already executed once after the user confirmed. Re-submitting never runs it again.',
		);
		if ( 'failed' === $action['status'] ) {
			$code = is_array( $result ) && isset( $result['code'] ) ? (string) $result['code'] : 'execution_failed';
			$msg  = is_array( $result ) && isset( $result['message'] ) ? (string) $result['message'] : 'The confirmed action failed.';
			return new \WP_Error( $code, $msg, array_merge( array( 'status' => is_array( $result ) && isset( $result['status'] ) ? (int) $result['status'] : 400 ), array( 'confirmation' => $meta ) ) );
		}
		if ( ! is_array( $result ) ) {
			$result = array( 'data' => $result );
		}
		self::redact_delivered_secrets( $action );
		$result['confirmation'] = $meta;
		return $result;
	}

	/**
	 * A result may carry a secret (e.g. a new application password). It is handed to
	 * the agent once; afterwards the stored copy is redacted.
	 *
	 * @param array $action Action row.
	 * @return void
	 */
	private static function redact_delivered_secrets( array $action ) {
		global $wpdb;
		$result = json_decode( (string) $action['result_json'], true );
		if ( ! is_array( $result ) ) {
			return;
		}
		$changed  = false;
		$redacted = self::redact( $result, $changed );
		if ( $changed ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bridge's own table.
			$wpdb->update( $wpdb->prefix . 'goldtwmcp_pending_actions', array( 'result_json' => (string) wp_json_encode( $redacted ) ), array( 'id' => $action['id'] ), array( '%s' ), array( '%s' ) );
		}
	}

	/**
	 * Replace values of password / secret / token keys.
	 *
	 * @param array $data    Data.
	 * @param bool  $changed Set when something was replaced.
	 * @return array
	 */
	private static function redact( array $data, &$changed ) {
		foreach ( $data as $k => $v ) {
			if ( is_array( $v ) ) {
				$data[ $k ] = self::redact( $v, $changed );
			} elseif ( is_string( $k ) && preg_match( '/(password|secret|token)$/i', $k ) && is_string( $v ) && '' !== $v && 0 !== strpos( $v, '[redacted' ) ) {
				$data[ $k ] = '[redacted after first delivery]';
				$changed    = true;
			}
		}
		return $data;
	}

	/**
	 * The 202 envelope for a pending action.
	 *
	 * @param array $action Action row.
	 * @return array
	 */
	public static function pending_envelope( array $action ) {
		$preview  = json_decode( (string) $action['preview_json'], true );
		$expires  = (int) strtotime( $action['expires_at'] . ' UTC' );
		$envelope = array(
			'confirmation_id' => $action['id'],
			'confirm_url'     => self::confirm_url( $action['id'], (int) $action['user_id'] ),
			'status_url'      => \rest_url( 'goldt-webmcp-bridge/v1/confirmations/' . $action['id'] ),
			'expires_at'      => gmdate( 'c', $expires ),
			'expires_in'      => max( 0, $expires - time() ),
			'tool'            => $action['tool'],
			'risk'            => $action['risk'],
			'summary'         => (string) $action['summary'],
			'preview'         => is_array( $preview ) ? $preview : array(),
			'flow'            => self::FLOW,
		);
		return array_merge(
			array(
				'success' => false,
				'status'  => 'pending_confirmation',
				'code'    => 'confirmation_required',
				'message' => 'Not executed. This action needs the user\'s own confirmation. Show the user the preview and ask them to open confirm_url (signed in to the site) to confirm or reject. Do NOT call this tool again on your own and do not tell the user it was done. When the user says they confirmed, call it again with the same arguments plus confirmation_id (or GET status_url) to get the result.',
			),
			$envelope,
			array( 'data' => $envelope )
		);
	}

	/**
	 * Confirm page URL for an action (wp-admin, the user's own session).
	 *
	 * @param string $id      Action ID.
	 * @param int    $user_id Owner.
	 * @return string
	 */
	public static function confirm_url( $id, $user_id ) {
		$parent = \user_can( (int) $user_id, 'list_users' ) ? 'users.php' : 'profile.php';
		return \admin_url( $parent . '?page=' . self::PAGE_SLUG . '&confirmation_id=' . rawurlencode( (string) $id ) );
	}

	// --------------------------------------------------------- user decisions.

	/**
	 * The user confirms: run the action once, as the user. Caller has verified the
	 * session (cookie + nonce) and that the current user owns the action.
	 *
	 * @param string $id             Action ID.
	 * @param bool   $grant_autonomy Also let this client run this tool without asking from now on.
	 * @return array{ok: bool, status: string, result?: mixed, error?: string}
	 */
	public static function confirm( $id, $grant_autonomy = false ) {
		global $wpdb;
		$user_id = (int) \get_current_user_id();
		$action  = self::get_action( $id );
		if ( ! $action || (int) $action['user_id'] !== $user_id ) {
			return array(
				'ok'     => false,
				'status' => 'not_found',
				'error'  => 'Unknown action.',
			);
		}
		// Single use: only one request can move pending → executing.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic compare-and-set on the bridge's own table.
		$moved = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}goldtwmcp_pending_actions SET status = 'executing', decided_at = %s, decided_by = %d WHERE id = %s AND user_id = %d AND status = 'pending' AND expires_at > %s",
				gmdate( 'Y-m-d H:i:s' ),
				$user_id,
				$id,
				$user_id,
				gmdate( 'Y-m-d H:i:s' )
			)
		);
		if ( 1 !== (int) $moved ) {
			$action = self::get_action( $id );
			self::log( 'replay_blocked', $id, $user_id, $action['tool'] ?? '', array( 'status' => $action['status'] ?? '' ) );
			return array(
				'ok'     => false,
				'status' => (string) ( $action['status'] ?? 'unknown' ),
				'error'  => 'This action can no longer be confirmed (already decided or expired).',
			);
		}
		self::use_action_context( $action );
		self::log( 'confirmed', $id, $user_id, $action['tool'] );

		$args   = json_decode( (string) $action['args_json'], true );
		$args   = is_array( $args ) ? $args : array();
		$result = self::execute_as_confirmed( $action['tool'], $args );

		$failed = \is_wp_error( $result ) || ( is_array( $result ) && array_key_exists( 'success', $result ) && false === $result['success'] );
		if ( \is_wp_error( $result ) ) {
			$data   = $result->get_error_data();
			$stored = array(
				'code'    => $result->get_error_code(),
				'message' => $result->get_error_message(),
				'status'  => is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400,
			);
		} else {
			$stored = $result;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bridge's own table.
		$wpdb->update(
			$wpdb->prefix . 'goldtwmcp_pending_actions',
			array(
				'status'      => $failed ? 'failed' : 'executed',
				'result_json' => (string) wp_json_encode( $stored ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%s' )
		);
		self::log( $failed ? 'execution_failed' : 'executed', $id, $user_id, $action['tool'] );

		if ( $grant_autonomy ) {
			self::grant( $user_id, $action['tool'], (string) $action['client_key'], 'confirm_page' );
		}
		return array(
			'ok'     => ! $failed,
			'status' => $failed ? 'failed' : 'executed',
			'result' => $stored,
		);
	}

	/**
	 * Run a tool in confirmed mode through the module (validation, add-on checks and
	 * audit run again; the capability check inside the tool runs as the user).
	 *
	 * @param string $full Tool.
	 * @param array  $args Arguments.
	 * @return mixed
	 */
	private static function execute_as_confirmed( $full, array $args ) {
		$plugin   = class_exists( '\GoldtWebMCP_Plugin' ) ? \GoldtWebMCP_Plugin::instance() : null;
		$endpoint = $plugin ? $plugin->get_tools_endpoint() : null;
		$def      = $endpoint ? $endpoint->resolve_tool( $full ) : null;
		if ( ! $def ) {
			return new \WP_Error( 'tool_not_found', 'The tool is no longer available on this site.', array( 'status' => 404 ) );
		}
		$user_id = (int) \get_current_user_id();
		$blocked = \get_option( 'goldtwmcp_blacklisted_users', array() );
		if ( is_array( $blocked ) && in_array( $user_id, array_map( 'intval', $blocked ), true ) ) {
			return new \WP_Error( 'access_denied', 'Your access to AI Connect has been revoked.', array( 'status' => 403 ) );
		}
		if ( ! \GoldtWebMCP\OAuth\Scopes::user_can_use_scope( $def['required_scope'], $user_id ) ) {
			return new \WP_Error( 'insufficient_capability', 'Your account no longer has the permissions this action needs.', array( 'status' => 403 ) );
		}
		$previous       = self::$mode;
		self::$mode     = 'confirmed';
		self::$expected = array(
			'tool' => $full,
			'hash' => self::args_hash( $args ),
		);
		try {
			return $def['module']->execute_tool( $def['method'], $args );
		} finally {
			self::$mode     = $previous;
			self::$expected = null;
		}
	}

	/**
	 * The user rejects a pending action.
	 *
	 * @param string $id Action ID.
	 * @return bool
	 */
	public static function reject( $id ) {
		global $wpdb;
		$user_id = (int) \get_current_user_id();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bridge's own table.
		$moved = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}goldtwmcp_pending_actions SET status = 'rejected', decided_at = %s, decided_by = %d WHERE id = %s AND user_id = %d AND status = 'pending'",
				gmdate( 'Y-m-d H:i:s' ),
				$user_id,
				$id,
				$user_id
			)
		);
		if ( 1 === (int) $moved ) {
			$action = self::get_action( $id );
			if ( $action ) {
				self::use_action_context( $action );
			}
			self::log( 'rejected', $id, $user_id, $action['tool'] ?? '' );
			return true;
		}
		return false;
	}

	/**
	 * Log decisions under the client that asked (not the browser session).
	 *
	 * @param array $action Action row.
	 * @return void
	 */
	private static function use_action_context( array $action ) {
		$parts         = explode( ':', (string) $action['client_key'], 2 );
		self::$context = array(
			'channel'   => $parts[0],
			'client_id' => $parts[1] ?? '',
		);
	}

	// ---------------------------------------------------------------- grants.

	/**
	 * May autonomy be used for this tool on this site?
	 *
	 * @param string $tool Tool.
	 * @param array  $conf Declaration.
	 * @return bool
	 */
	public static function autonomy_allowed( $tool, array $conf ) {
		/**
		 * Filter whether users may let an agent run a confirmation tool without asking.
		 *
		 * @since 1.2.13
		 * @param bool   $allowed Default true.
		 * @param string $tool    Full tool name.
		 * @param string $risk    financial | destructive.
		 */
		return (bool) apply_filters( 'goldtwmcp_confirmation_autonomy_allowed', true, $tool, $conf['risk'] ?? 'destructive' );
	}

	/**
	 * Grant autonomy (user + tool + client).
	 *
	 * @param int    $user_id User.
	 * @param string $tool    Tool.
	 * @param string $client  Client key (channel:client_id).
	 * @param string $via     Where it was granted.
	 * @return bool
	 */
	public static function grant( $user_id, $tool, $client, $via = 'confirm_page' ) {
		global $wpdb;
		self::maybe_install();
		if ( self::find_grant( $user_id, $tool, $client ) ) {
			return true;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Bridge's own table.
		$ok = $wpdb->insert(
			$wpdb->prefix . 'goldtwmcp_autonomy_grants',
			array(
				'user_id'     => (int) $user_id,
				'tool'        => (string) $tool,
				'client_key'  => (string) $client,
				'granted_at'  => gmdate( 'Y-m-d H:i:s' ),
				'granted_via' => (string) $via,
			),
			array( '%d', '%s', '%s', '%s', '%s' )
		);
		if ( $ok ) {
			self::log( 'autonomy_granted', '', (int) $user_id, (string) $tool, array( 'client' => $client ) );
		}
		return (bool) $ok;
	}

	/**
	 * Revoke one of the current user's grants.
	 *
	 * @param int $grant_id Grant ID.
	 * @param int $user_id  Owner.
	 * @return bool
	 */
	public static function revoke_grant( $grant_id, $user_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bridge's own table.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}goldtwmcp_autonomy_grants WHERE id = %d AND user_id = %d AND revoked_at IS NULL", (int) $grant_id, (int) $user_id ), ARRAY_A );
		if ( ! $row ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bridge's own table.
		$wpdb->update( $wpdb->prefix . 'goldtwmcp_autonomy_grants', array( 'revoked_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => (int) $grant_id ), array( '%s' ), array( '%d' ) );
		self::log( 'autonomy_revoked', '', (int) $user_id, (string) $row['tool'], array( 'client' => $row['client_key'] ) );
		return true;
	}

	/**
	 * Active grant for user + tool + client.
	 *
	 * @param int    $user_id User.
	 * @param string $tool    Tool.
	 * @param string $client  Client key.
	 * @return array|null
	 */
	public static function find_grant( $user_id, $tool, $client ) {
		global $wpdb;
		if ( ! self::installed() ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Must be live (a revoke takes effect on the next call).
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}goldtwmcp_autonomy_grants WHERE user_id = %d AND tool = %s AND client_key = %s AND revoked_at IS NULL LIMIT 1", (int) $user_id, (string) $tool, (string) $client ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * The user's active grants.
	 *
	 * @param int $user_id User.
	 * @return array
	 */
	public static function list_grants( $user_id ) {
		global $wpdb;
		if ( ! self::installed() ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bridge's own table.
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}goldtwmcp_autonomy_grants WHERE user_id = %d AND revoked_at IS NULL ORDER BY granted_at DESC", (int) $user_id ), ARRAY_A );
	}

	// ----------------------------------------------------------------- store.

	/**
	 * Stable hash of the arguments (key order independent).
	 *
	 * @param array $args Arguments.
	 * @return string
	 */
	public static function args_hash( array $args ) {
		unset( $args['confirm'], $args['confirmation_id'] );
		return hash( 'sha256', (string) wp_json_encode( self::ksort_deep( $args ) ) );
	}

	/**
	 * Recursive ksort.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private static function ksort_deep( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		ksort( $value );
		foreach ( $value as $k => $v ) {
			$value[ $k ] = self::ksort_deep( $v );
		}
		return $value;
	}

	/**
	 * Pending-action lifetime in seconds (60 s … 24 h, default 15 min).
	 *
	 * @param string $tool Tool.
	 * @return int
	 */
	public static function ttl( $tool ) {
		/**
		 * Filter how long a pending action waits for the user.
		 *
		 * @since 1.2.13
		 * @param int    $seconds Default 900.
		 * @param string $tool    Full tool name.
		 */
		$ttl = (int) apply_filters( 'goldtwmcp_confirmation_ttl', self::DEFAULT_TTL, $tool );
		return max( 60, min( DAY_IN_SECONDS, $ttl ) );
	}

	/**
	 * Store a new pending action.
	 *
	 * @param int    $user_id User.
	 * @param string $full    Tool.
	 * @param array  $args    Arguments.
	 * @param string $hash    Arguments hash.
	 * @param array  $conf    Declaration.
	 * @param array  $preview Preview.
	 * @return array|\WP_Error
	 */
	private static function create_pending( $user_id, $full, array $args, $hash, array $conf, array $preview ) {
		global $wpdb;
		$id      = 'conf_' . strtolower( wp_generate_password( 32, false, false ) );
		$now     = time();
		$summary = $conf['summary'];
		if ( '' !== $summary ) {
			$summary = preg_replace_callback(
				'/\{([a-z0-9_]+)\}/',
				static function ( $m ) use ( $args ) {
					return isset( $args[ $m[1] ] ) && is_scalar( $args[ $m[1] ] ) ? (string) $args[ $m[1] ] : $m[0];
				},
				$summary
			);
		}
		$row = array(
			'id'           => $id,
			'user_id'      => (int) $user_id,
			'tool'         => $full,
			'args_json'    => (string) wp_json_encode( $args ),
			'args_hash'    => $hash,
			'preview_json' => (string) wp_json_encode( $preview ),
			'summary'      => substr( (string) $summary, 0, 255 ),
			'risk'         => $conf['risk'],
			'client_key'   => self::client_key(),
			'status'       => 'pending',
			'created_at'   => gmdate( 'Y-m-d H:i:s', $now ),
			'expires_at'   => gmdate( 'Y-m-d H:i:s', $now + self::ttl( $full ) ),
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Bridge's own table.
		$ok = $wpdb->insert( $wpdb->prefix . 'goldtwmcp_pending_actions', $row );
		if ( ! $ok ) {
			return new \WP_Error( 'confirmation_unavailable', 'The action could not be queued for confirmation, so it was not run.', array( 'status' => 503 ) );
		}
		self::log( 'requested', $id, (int) $user_id, $full );
		/**
		 * Fires when an action waits for the user's confirmation.
		 *
		 * @since 1.2.13
		 * @param array $row Pending action.
		 */
		do_action( 'goldtwmcp_confirmation_requested', $row );
		return $row;
	}

	/**
	 * Pending (not expired) action with the same user + tool + arguments + client.
	 *
	 * @param int    $user_id User.
	 * @param string $full    Tool.
	 * @param string $hash    Hash.
	 * @param string $client  Client key.
	 * @return array|null
	 */
	private static function find_pending( $user_id, $full, $hash, $client ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Must be live.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}goldtwmcp_pending_actions WHERE user_id = %d AND tool = %s AND args_hash = %s AND client_key = %s AND status = 'pending' AND expires_at > %s ORDER BY created_at DESC LIMIT 1", (int) $user_id, $full, $hash, $client, gmdate( 'Y-m-d H:i:s' ) ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * Action by ID (expires a pending row whose time is up).
	 *
	 * @param string $id Action ID.
	 * @return array|null
	 */
	public static function get_action( $id ) {
		global $wpdb;
		$id = (string) $id;
		if ( ! preg_match( '/^conf_[a-z0-9]{32}$/', $id ) || ! self::installed() ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Must be live.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}goldtwmcp_pending_actions WHERE id = %s", $id ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		if ( 'pending' === $row['status'] && strtotime( $row['expires_at'] . ' UTC' ) <= time() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bridge's own table.
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}goldtwmcp_pending_actions SET status = 'expired' WHERE id = %s AND status = 'pending'", $id ) );
			self::log( 'expired', $id, (int) $row['user_id'], $row['tool'] );
			$row['status'] = 'expired';
		}
		return $row;
	}

	/**
	 * The user's actions (newest first).
	 *
	 * @param int    $user_id User.
	 * @param string $status  pending | all.
	 * @param int    $limit   Max rows.
	 * @return array
	 */
	public static function list_actions( $user_id, $status = 'pending', $limit = 50 ) {
		global $wpdb;
		if ( ! self::installed() ) {
			return array();
		}
		if ( 'pending' === $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Expire stale rows first (lazy); table name from $wpdb->prefix.
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}goldtwmcp_pending_actions SET status = 'expired' WHERE user_id = %d AND status = 'pending' AND expires_at <= %s", (int) $user_id, gmdate( 'Y-m-d H:i:s' ) ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix.
			return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}goldtwmcp_pending_actions WHERE user_id = %d AND status = 'pending' ORDER BY created_at DESC LIMIT %d", (int) $user_id, (int) $limit ), ARRAY_A );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix.
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}goldtwmcp_pending_actions WHERE user_id = %d ORDER BY created_at DESC LIMIT %d", (int) $user_id, (int) $limit ), ARRAY_A );
	}

	/**
	 * Public status of an action for its owner (REST status_url).
	 *
	 * @param array $action Action row.
	 * @return array
	 */
	public static function public_status( array $action ) {
		$out = array(
			'confirmation_id' => $action['id'],
			'tool'            => $action['tool'],
			'status'          => $action['status'],
			'risk'            => $action['risk'],
			'created_at'      => self::iso( $action['created_at'] ),
			'expires_at'      => self::iso( $action['expires_at'] ),
			'decided_at'      => self::iso( $action['decided_at'] ),
		);
		if ( in_array( $action['status'], array( 'executed', 'failed' ), true ) ) {
			$out['result'] = json_decode( (string) $action['result_json'], true );
			self::redact_delivered_secrets( $action );
		}
		if ( 'pending' === $action['status'] ) {
			$out['confirm_url'] = self::confirm_url( $action['id'], (int) $action['user_id'] );
		}
		return $out;
	}

	/**
	 * MySQL UTC datetime → ISO 8601 (or null).
	 *
	 * @param string|null $dt Datetime.
	 * @return string|null
	 */
	private static function iso( $dt ) {
		if ( empty( $dt ) ) {
			return null;
		}
		return gmdate( 'c', (int) strtotime( $dt . ' UTC' ) );
	}

	/**
	 * Audit log row.
	 *
	 * @param string $event   Event.
	 * @param string $id      Action ID ('' for grant events).
	 * @param int    $user_id User the action belongs to.
	 * @param string $tool    Tool.
	 * @param array  $detail  Extra.
	 * @return void
	 */
	public static function log( $event, $id, $user_id, $tool, array $detail = array() ) {
		global $wpdb;
		if ( ! self::installed() ) {
			return;
		}
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Append-only audit table.
		$wpdb->insert(
			$wpdb->prefix . 'goldtwmcp_confirmation_log',
			array(
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
				'event'      => substr( (string) $event, 0, 32 ),
				'action_id'  => (string) $id,
				'user_id'    => (int) $user_id,
				'actor_id'   => (int) \get_current_user_id(),
				'tool'       => substr( (string) $tool, 0, 191 ),
				'client_key' => self::client_key(),
				'ip'         => substr( $ip, 0, 45 ),
				'detail'     => $detail ? (string) wp_json_encode( $detail ) : null,
			)
		);
		/**
		 * Fires for every confirmation-flow audit event.
		 *
		 * @since 1.2.13
		 */
		do_action( 'goldtwmcp_confirmation_event', $event, $id, (int) $user_id, (string) $tool, $detail );
	}

	/**
	 * Audit rows for a user (newest first).
	 *
	 * @param int $user_id User.
	 * @param int $limit   Max rows.
	 * @return array
	 */
	public static function list_log( $user_id, $limit = 30 ) {
		global $wpdb;
		if ( ! self::installed() ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bridge's own table.
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}goldtwmcp_confirmation_log WHERE user_id = %d ORDER BY id DESC LIMIT %d", (int) $user_id, (int) $limit ), ARRAY_A );
	}

	/**
	 * Delete decided / expired actions older than KEEP_DAYS (the audit log stays).
	 *
	 * @return void
	 */
	public static function cleanup() {
		global $wpdb;
		if ( ! self::installed() ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bridge's own table.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}goldtwmcp_pending_actions WHERE status <> 'pending' AND created_at < %s", gmdate( 'Y-m-d H:i:s', time() - self::KEEP_DAYS * DAY_IN_SECONDS ) ) );
	}

	// ---------------------------------------------------------------- schema.

	/**
	 * Are the tables installed at the current schema version?
	 *
	 * @return bool
	 */
	public static function installed() {
		return self::DB_VERSION === get_option( self::DB_OPTION );
	}

	/**
	 * Create / upgrade the three tables (dbDelta, idempotent).
	 *
	 * @return void
	 */
	public static function maybe_install() {
		if ( self::installed() ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$cc = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$wpdb->prefix}goldtwmcp_pending_actions (
            id VARCHAR(40) NOT NULL,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            tool VARCHAR(191) NOT NULL,
            args_json LONGTEXT NOT NULL,
            args_hash CHAR(64) NOT NULL,
            preview_json LONGTEXT NULL,
            summary VARCHAR(255) NOT NULL DEFAULT '',
            risk VARCHAR(20) NOT NULL DEFAULT 'destructive',
            client_key VARCHAR(120) NOT NULL DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            decided_at DATETIME NULL,
            decided_by BIGINT(20) UNSIGNED NULL,
            result_json LONGTEXT NULL,
            PRIMARY KEY  (id),
            KEY user_status (user_id,status),
            KEY lookup (user_id,tool,args_hash)
        ) $cc;"
		);
		dbDelta(
			"CREATE TABLE {$wpdb->prefix}goldtwmcp_autonomy_grants (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            tool VARCHAR(191) NOT NULL,
            client_key VARCHAR(120) NOT NULL,
            granted_at DATETIME NOT NULL,
            granted_via VARCHAR(40) NOT NULL DEFAULT '',
            revoked_at DATETIME NULL,
            PRIMARY KEY  (id),
            KEY user_tool (user_id,tool)
        ) $cc;"
		);
		dbDelta(
			"CREATE TABLE {$wpdb->prefix}goldtwmcp_confirmation_log (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL,
            event VARCHAR(32) NOT NULL,
            action_id VARCHAR(40) NOT NULL DEFAULT '',
            user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            actor_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            tool VARCHAR(191) NOT NULL DEFAULT '',
            client_key VARCHAR(120) NOT NULL DEFAULT '',
            ip VARCHAR(45) NOT NULL DEFAULT '',
            detail TEXT NULL,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY action_id (action_id)
        ) $cc;"
		);
		update_option( self::DB_OPTION, self::DB_VERSION, false );
	}
}
