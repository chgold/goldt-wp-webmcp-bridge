<?php
/**
 * In-process tool gateway.
 *
 * @package GoldtWebMCP
 */

namespace GoldtWebMCP\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lets another plugin on the same site call the Bridge's registered tools
 * in-process (PHP, no HTTP, no OAuth token), as a given WordPress user, with
 * the access layers the REST endpoint applies to a token.
 *
 * Layers:
 *   not_found      the tool is not registered (provider plugin missing / not licensed)
 *   allowed_tools  the caller's own restriction list (context.allowed_tools — restriction only)
 *   master         the user is blocked from AI Connect (goldtwmcp_blacklisted_users)
 *   guest          anonymous callers may use `read`-scope tools only
 *   scope          the user lacks the WordPress capability behind the tool's scope
 *                  (Scopes::user_can_use_scope — same check as REST)
 *   rate_limit     per-user (per-IP for guests) limit, in a bucket separate from REST:
 *                  user_{id}@inprocess[:{bucket}]
 *
 * Content checks stay INSIDE each tool (current_user_can / read_post / comment
 * visibility), which run as the given user — the gateway adds none of its own,
 * so there is no double enforcement and no way to widen what a tool returns.
 *
 * Result: array{success: bool, status: int, result?: array, error?: array{code, message},
 *               denied_layer: ?string, tool: string}
 *
 * @since 1.2.11
 */
class Tool_Gateway {

	const CHANNEL_DEFAULT = 'inprocess';

	/**
	 * Tools endpoint holding the module registry.
	 *
	 * @var \GoldtWebMCP\API\Tools_Endpoint
	 */
	private $endpoint;

	/**
	 * Rate limiter (shared implementation with REST, separate keys).
	 *
	 * @var Rate_Limiter
	 */
	private $rate_limiter;

	/**
	 * Constructor.
	 *
	 * @param \GoldtWebMCP\API\Tools_Endpoint $endpoint Tools endpoint.
	 */
	public function __construct( $endpoint ) {
		$this->endpoint     = $endpoint;
		$this->rate_limiter = new Rate_Limiter();
	}

	/**
	 * Every registered tool: name, module, required_scope, description, input_schema.
	 *
	 * @return array<string,array>
	 */
	public function list_tools() {
		$out = array();
		foreach ( $this->endpoint->get_all_tools() as $name => $def ) {
			$out[ $name ] = array(
				'name'           => $name,
				'module'         => $def['module'],
				'required_scope' => $def['required_scope'],
				'description'    => $def['description'],
				'input_schema'   => $def['input_schema'],
			);
		}
		return $out;
	}

	/**
	 * Tool definition by full name (for example getPost of the WordPress module), or null.
	 *
	 * @param string $tool Full tool name.
	 * @return array|null
	 */
	public function resolve( $tool ) {
		$all = $this->list_tools();
		return $all[ (string) $tool ] ?? null;
	}

	/**
	 * Would the CURRENT user be allowed to call $tool (no execution, no rate count)?
	 *
	 * @param string $tool    Full tool name.
	 * @param array  $context allowed_tools (restriction list).
	 * @return array{allowed: bool, layer: ?string}
	 */
	public function check_access( $tool, array $context = array() ) {
		$layer = $this->denied_layer( (string) $tool, $context, \get_current_user_id() );
		return array(
			'allowed' => null === $layer,
			'layer'   => $layer,
		);
	}

	/**
	 * The check_access() check, as another user.
	 *
	 * @param int    $user_id User ID (0 = guest).
	 * @param string $tool    Full tool name.
	 * @param array  $context Context.
	 * @return array{allowed: bool, layer: ?string}
	 */
	public function check_access_as( $user_id, $tool, array $context = array() ) {
		return $this->as_user(
			(int) $user_id,
			function () use ( $tool, $context ) {
				return $this->check_access( $tool, $context );
			}
		);
	}

	/**
	 * Run a tool as the CURRENT user.
	 *
	 * Context keys: channel (default "inprocess"), caller (free text, for the
	 * goldtwmcp_tool_gateway_call action), allowed_tools (restriction), rate_bucket.
	 *
	 * @param string $tool    Full tool name.
	 * @param array  $args    Tool arguments.
	 * @param array  $context Context.
	 * @return array
	 */
	public function call( $tool, array $args = array(), array $context = array() ) {
		$tool    = (string) $tool;
		$user_id = (int) \get_current_user_id();
		$layer   = $this->denied_layer( $tool, $context, $user_id );
		if ( null !== $layer ) {
			$status = 'not_found' === $layer ? 404 : 403;
			return $this->finish( $tool, $context, $user_id, $this->deny( $tool, $layer, $status ) );
		}

		$identifier = $this->rate_identifier( $user_id, $context );
		$rate       = $this->rate_limiter->is_rate_limited( $identifier );
		if ( ! empty( $rate['limited'] ) ) {
			$out                     = $this->deny( $tool, 'rate_limit', 429 );
			$out['error']['message'] = 'Rate limit exceeded';
			$out['retry_after']      = (int) ( $rate['retry_after'] ?? 60 );
			return $this->finish( $tool, $context, $user_id, $out );
		}
		$this->rate_limiter->record_request( $identifier );

		$def    = $this->endpoint->resolve_tool( $tool );
		$result = $def['module']->execute_tool( $def['method'], $args );

		if ( \is_wp_error( $result ) ) {
			$data   = $result->get_error_data();
			$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;
			$out    = array(
				'success'      => false,
				'status'       => $status,
				'tool'         => $tool,
				'error'        => array(
					'code'    => (string) $result->get_error_code(),
					'message' => (string) $result->get_error_message(),
				),
				// A tool-level refusal (403/404 from the content check) is reported as the
				// content layer, so a caller can tell it from the gateway layers above.
				'denied_layer' => in_array( $status, array( 401, 403, 404 ), true ) ? 'content:' . $status : null,
			);
			return $this->finish( $tool, $context, $user_id, $out );
		}

		$ok  = ! ( is_array( $result ) && array_key_exists( 'success', $result ) && false === $result['success'] );
		$out = array(
			'success'      => $ok,
			'status'       => $ok ? 200 : 400,
			'tool'         => $tool,
			'result'       => is_array( $result ) ? $result : array( 'data' => $result ),
			'denied_layer' => null,
		);
		return $this->finish( $tool, $context, $user_id, $out );
	}

	/**
	 * Run a tool as another user (0 = guest). The previous current user is
	 * always restored, also when the tool throws.
	 *
	 * @param int    $user_id User ID.
	 * @param string $tool    Full tool name.
	 * @param array  $args    Tool arguments.
	 * @param array  $context Context.
	 * @return array
	 */
	public function call_as( $user_id, $tool, array $args = array(), array $context = array() ) {
		return $this->as_user(
			(int) $user_id,
			function () use ( $tool, $args, $context ) {
				return $this->call( $tool, $args, $context );
			}
		);
	}

	/**
	 * First blocking layer for $tool and the given user, or null.
	 *
	 * @param string $tool    Full tool name.
	 * @param array  $context Context.
	 * @param int    $user_id User ID.
	 * @return string|null
	 */
	private function denied_layer( $tool, array $context, $user_id ) {
		$def = $this->endpoint->resolve_tool( $tool );
		if ( ! $def ) {
			return 'not_found';
		}
		if ( array_key_exists( 'allowed_tools', $context ) && ! self::tool_in_list( $tool, (array) $context['allowed_tools'] ) ) {
			return 'allowed_tools';
		}
		if ( $user_id > 0 ) {
			$blocked = \get_option( 'goldtwmcp_blacklisted_users', array() );
			if ( is_array( $blocked ) && in_array( $user_id, array_map( 'intval', $blocked ), true ) ) {
				return 'master';
			}
		} elseif ( 'read' !== $def['required_scope'] ) {
			return 'guest';
		}
		if ( $user_id > 0 && ! \GoldtWebMCP\OAuth\Scopes::user_can_use_scope( $def['required_scope'], $user_id ) ) {
			return 'scope';
		}
		return null;
	}

	/**
	 * Is $tool in a restriction list? Supports exact names and "module.*".
	 *
	 * @param string $tool Full tool name.
	 * @param array  $names Allowed names.
	 * @return bool
	 */
	public static function tool_in_list( $tool, array $names ) {
		foreach ( $names as $entry ) {
			$entry = (string) $entry;
			if ( $entry === $tool ) {
				return true;
			}
			if ( substr( $entry, -2 ) === '.*' && strpos( $tool, substr( $entry, 0, -1 ) ) === 0 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Rate-limit identifier, separate from the REST bucket (user_{id}).
	 *
	 * @param int   $user_id User ID.
	 * @param array $context Context.
	 * @return string
	 */
	private function rate_identifier( $user_id, array $context ) {
		if ( $user_id > 0 ) {
			$who = 'user_' . $user_id;
		} else {
			$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
			$who = 'guest_' . substr( hash( 'sha256', $ip . wp_salt( 'nonce' ) ), 0, 16 );
		}
		$channel = isset( $context['channel'] ) ? sanitize_key( (string) $context['channel'] ) : self::CHANNEL_DEFAULT;
		$bucket  = isset( $context['rate_bucket'] ) ? sanitize_key( (string) $context['rate_bucket'] ) : '';
		return $who . '@' . ( '' !== $channel ? $channel : self::CHANNEL_DEFAULT ) . ( '' !== $bucket ? ':' . $bucket : '' );
	}

	/**
	 * Denial result.
	 *
	 * @param string $tool   Tool name.
	 * @param string $layer  Blocking layer.
	 * @param int    $status HTTP-like status.
	 * @return array
	 */
	private function deny( $tool, $layer, $status ) {
		return array(
			'success'      => false,
			'status'       => $status,
			'tool'         => $tool,
			'error'        => array(
				'code'    => 'not_found' === $layer ? 'tool_not_found' : 'access_denied',
				'message' => 'not_found' === $layer ? 'Tool not found' : 'Access denied (' . $layer . ')',
			),
			'denied_layer' => $layer,
		);
	}

	/**
	 * Fire the observation action and return the result.
	 *
	 * @param string $tool    Tool name.
	 * @param array  $context Context.
	 * @param int    $user_id User ID.
	 * @param array  $out     Result.
	 * @return array
	 */
	private function finish( $tool, array $context, $user_id, array $out ) {
		/**
		 * Fires after every in-process tool call (allowed or denied).
		 *
		 * @since 1.2.11
		 * @param string $tool    Full tool name.
		 * @param int    $user_id User the tool ran as (0 = guest).
		 * @param array  $out     Gateway result (success, status, denied_layer, …).
		 * @param array  $context channel / caller / allowed_tools / rate_bucket.
		 */
		do_action( 'goldtwmcp_tool_gateway_call', $tool, (int) $user_id, $out, $context );
		return $out;
	}

	/**
	 * Run $callback with $user_id as the current user and restore the previous one.
	 *
	 * @param int      $user_id User ID.
	 * @param callable $callback Callback.
	 * @return mixed
	 */
	private function as_user( $user_id, callable $callback ) {
		$previous = (int) \get_current_user_id();
		if ( $previous === $user_id ) {
			return $callback();
		}
		\wp_set_current_user( $user_id );
		try {
			return $callback();
		} finally {
			\wp_set_current_user( $previous );
		}
	}
}
