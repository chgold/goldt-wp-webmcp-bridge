<?php
/**
 * Module base class file.
 *
 * @package GoldtWebMCP
 */

namespace GoldtWebMCP\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract base class for all GoldtWebMCP modules.
 *
 * @package GoldtWebMCP
 */
abstract class Module_Base {

	/**
	 * Module name identifier.
	 *
	 * @var string
	 */
	protected $module_name;

	/**
	 * Module version.
	 *
	 * @var string
	 */
	protected $module_version = '1.0.0';

	/**
	 * Registered tools.
	 *
	 * @var array
	 */
	protected $tools = array();

	/**
	 * Manifest instance.
	 *
	 * @var \GoldtWebMCP\Core\Manifest
	 */
	protected $manifest;

	/**
	 * Constructor.
	 *
	 * @param \GoldtWebMCP\Core\Manifest $manifest Manifest instance.
	 */
	public function __construct( $manifest ) {
		$this->manifest = $manifest;
		$this->register_tools();
	}

	/**
	 * Register module tools. Must be implemented by subclass.
	 *
	 * @return void
	 */
	abstract protected function register_tools();

	/**
	 * Register a tool with the module and manifest.
	 *
	 * @param string $name Tool name (without module prefix).
	 * @param array  $config Tool configuration.
	 * @return bool
	 */
	protected function register_tool( $name, $config ) {
		if ( ! isset( $config['description'] ) || ! isset( $config['input_schema'] ) ) {
			return false;
		}

		$full_name = $this->module_name . '.' . $name;

		$this->tools[ $name ] = array(
			'name'           => $full_name,
			'description'    => $config['description'],
			'input_schema'   => $config['input_schema'],
			'callback'       => $config['callback'] ?? array( $this, 'execute_' . $name ),
			'required_scope' => isset( $config['required_scope'] ) && is_string( $config['required_scope'] ) && '' !== $config['required_scope']
				? $config['required_scope']
				: $this->undeclared_scope( $name, $full_name ),
		);

		if ( $this->manifest ) {
			$this->manifest->register_tool(
				$full_name,
				array(
					'description'  => $config['description'],
					'input_schema' => $config['input_schema'],
				)
			);
		}

		return true;
	}

	/**
	 * Read-only verbs: a tool registered without required_scope whose name starts
	 * with one of these (followed by an upper-case letter or a digit) is a read tool.
	 * Same rule as the XenForo add-on (ToolRegistry::READ_PREFIX_PATTERN), plus the
	 * read-only verbs WordPress add-ons use (export, validate, analyze, audit).
	 */
	const READ_VERB_PATTERN = '/^(get|list|search|find|read|count|download|check|preview|explain|describe|lookup|fetch|show|view|is|has|export|validate|analyze|audit|compare)[A-Z0-9]/';

	/**
	 * Tools registered without required_scope (full name => scope applied), for
	 * diagnostics and tests.
	 *
	 * @var array<string,string>
	 */
	protected static $undeclared_scope_tools = array();

	/**
	 * Released add-on tools that act only on the caller's own session and were
	 * shipped without required_scope, relying on the pre-1.2.12 `read` default
	 * (WooCommerce AI Connect <= 1.4.4: the shopper's own cart). They keep `read`
	 * so shop customers — who no longer receive the write scope — can still use
	 * them. Every other undeclared tool fails closed. Filter:
	 * goldtwmcp_legacy_read_tools.
	 *
	 * @var string[]
	 */
	const LEGACY_READ_TOOLS = array( 'woocommerce.addToCart', 'woocommerce.applyCouponToCart' );

	/**
	 * Scope for a tool that does not declare one — fail-closed (since 1.2.12).
	 *
	 * Before 1.2.12 every undeclared tool became `read`, so an add-on that forgot
	 * the declaration exposed its write and delete tools to read-only tokens.
	 * Now only a tool whose name is a read verb (getX, listX, searchX, …) is
	 * `read`; deleteX / removeX / purgeX / pruneX are `delete`; everything else is
	 * `write`. Add-ons should always declare required_scope explicitly — an
	 * undeclared tool fires the goldtwmcp_undeclared_tool_scope action, and the
	 * tools that got write / delete this way are written to the debug log once
	 * per request (WP_DEBUG + WP_DEBUG_LOG only).
	 *
	 * @param string $name      Tool name without module prefix.
	 * @param string $full_name Full tool name.
	 * @return string
	 */
	protected function undeclared_scope( $name, $full_name ) {
		$name = (string) $name;
		/**
		 * Filter the undeclared tools that keep the pre-1.2.12 `read` default.
		 *
		 * @since 1.2.12
		 * @param string[] $tools Full tool names.
		 */
		$legacy = (array) apply_filters( 'goldtwmcp_legacy_read_tools', self::LEGACY_READ_TOOLS );
		if ( preg_match( self::READ_VERB_PATTERN, $name ) || in_array( (string) $full_name, $legacy, true ) ) {
			$scope = 'read';
		} elseif ( preg_match( '/^(delete|remove|purge|prune)[A-Z0-9]/', $name ) ) {
			$scope = 'delete';
		} else {
			$scope = 'write';
		}
		self::$undeclared_scope_tools[ $full_name ] = $scope;

		// A non-read default is a behaviour change from before 1.2.12: note it once per
		// request in the debug log (never on screen — responses must stay clean JSON).
		if ( 'read' !== $scope && 1 === count( array_diff( self::$undeclared_scope_tools, array( 'read' ) ) ) ) {
			add_action( 'shutdown', array( __CLASS__, 'log_undeclared_scope_tools' ) );
		}

		/**
		 * Fires when a tool is registered without required_scope.
		 *
		 * @since 1.2.12
		 * @param string $full_name Full tool name.
		 * @param string $scope     Scope applied (read for read verbs, delete for delete verbs, otherwise write).
		 * @param string $module    Module class.
		 */
		do_action( 'goldtwmcp_undeclared_tool_scope', $full_name, $scope, get_class( $this ) );

		return $scope;
	}

	/**
	 * Debug-log the undeclared tools that were registered write / delete (shutdown, once per request).
	 *
	 * @return void
	 */
	public static function log_undeclared_scope_tools() {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG || ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) {
			return;
		}
		$closed = array_diff( self::$undeclared_scope_tools, array( 'read' ) );
		if ( $closed ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-log only (guarded by WP_DEBUG + WP_DEBUG_LOG); tells add-on developers which tools to declare.
			error_log( 'GoldT WebMCP Bridge: ' . count( $closed ) . ' tool(s) do not declare required_scope and were registered fail-closed: ' . implode( ', ', array_map( static fn( $n, $sc ) => $n . '=' . $sc, array_keys( $closed ), $closed ) ) );
		}
	}

	/**
	 * Tools registered without required_scope in this request (full name => scope applied).
	 *
	 * @return array<string,string>
	 */
	public static function get_undeclared_scope_tools() {
		return self::$undeclared_scope_tools;
	}

	/**
	 * Execute a tool by name with the given parameters.
	 *
	 * @param string $tool_name Tool name (without module prefix).
	 * @param array  $params Tool parameters.
	 * @return array|\WP_Error
	 */
	public function execute_tool( $tool_name, $params = array() ) {
		if ( ! isset( $this->tools[ $tool_name ] ) ) {
			return new \WP_Error( 'tool_not_found', sprintf( 'Tool %s not found', $tool_name ) );
		}

		$tool = $this->tools[ $tool_name ];

		$validated = $this->validate_params( $params, $tool['input_schema'] );
		if ( \is_wp_error( $validated ) ) {
			return $validated;
		}

		if ( ! is_callable( $tool['callback'] ) ) {
			return new \WP_Error( 'tool_not_callable', sprintf( 'Tool %s is not callable', $tool_name ) );
		}

		try {
			return call_user_func( $tool['callback'], $validated );
		} catch ( \Exception $e ) {
			return new \WP_Error( 'tool_execution_error', $e->getMessage() );
		}
	}

	/**
	 * Validate and sanitize tool parameters against JSON schema.
	 *
	 * @param array $params Input parameters.
	 * @param array $schema JSON schema to validate against.
	 * @return array|\WP_Error Validated parameters or error.
	 */
	protected function validate_params( $params, $schema ) {
		if ( ! isset( $schema['properties'] ) ) {
			return $params;
		}

		$validated = array();

		foreach ( $schema['properties'] as $key => $prop ) {
			$required = isset( $schema['required'] ) && in_array( $key, $schema['required'], true );

			if ( $required && ! isset( $params[ $key ] ) ) {
				return new \WP_Error( 'missing_parameter', sprintf( 'Required parameter %s is missing', $key ), array( 'status' => 400 ) );
			}

			if ( isset( $params[ $key ] ) ) {
				$value = $params[ $key ];

				if ( isset( $prop['type'] ) ) {
					$type_valid = $this->validate_type( $value, $prop['type'] );
					if ( ! $type_valid ) {
						return new \WP_Error(
							'invalid_type',
							sprintf( 'Parameter %s must be of type %s', $key, $prop['type'] ),
							array( 'status' => 400 )
						);
					}
				}

				$validated[ $key ] = $value;
			} elseif ( isset( $prop['default'] ) ) {
				$validated[ $key ] = $prop['default'];
			}
		}

		return $validated;
	}

	/**
	 * Validate a value against a given JSON schema type.
	 *
	 * @param mixed  $value Value to validate.
	 * @param string $type Expected type string.
	 * @return bool
	 */
	protected function validate_type( $value, $type ) {
		switch ( $type ) {
			case 'string':
				return is_string( $value );
			case 'integer':
				return is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) );
			case 'number':
				return is_numeric( $value );
			case 'boolean':
				return is_bool( $value ) || in_array( $value, array( 'true', 'false', 0, 1 ), true );
			case 'array':
				return is_array( $value );
			case 'object':
				return is_object( $value ) || is_array( $value );
			default:
				return true;
		}
	}

	/**
	 * Get all registered tools.
	 *
	 * @return array
	 */
	public function get_tools() {
		return $this->tools;
	}

	/**
	 * Get the module name.
	 *
	 * @return string
	 */
	public function get_module_name() {
		return $this->module_name;
	}

	/**
	 * Verify that the current request satisfies the given OAuth scope.
	 *
	 * Defense in depth: the router already validates scope before dispatching,
	 * but individual tool handlers (especially write/delete ops) SHOULD call
	 * this too to keep the security check local and auditable per-handler.
	 *
	 * The scope name is mapped to a WordPress capability so the check honors
	 * the actual user role, not just an OAuth scope string.
	 *
	 * @param string $scope Required scope: 'read' | 'write' | 'delete' | 'admin' | 'manage_users'.
	 * @return bool True if the current user has the capability for this scope.
	 */
	protected function check_scope( $scope ) {
		static $scope_to_cap = array(
			'read'         => 'read',
			'write'        => 'edit_posts',
			'delete'       => 'delete_posts',
			'admin'        => 'manage_options',
			'manage_users' => 'list_users',
		);

		if ( ! isset( $scope_to_cap[ $scope ] ) ) {
			return true;
		}

		return \current_user_can( $scope_to_cap[ $scope ] );
	}

	/**
	 * Build a success response array.
	 *
	 * @param mixed  $data Response data.
	 * @param string $message Optional message.
	 * @return array
	 */
	protected function success_response( $data, $message = null ) {
		return array(
			'success' => true,
			'data'    => $data,
			'message' => $message,
		);
	}

	/**
	 * Build an error response.
	 *
	 * @param string $message Error message.
	 * @param string $code Error code.
	 * @param mixed  $data Optional data.
	 * @return \WP_Error
	 */
	protected function error_response( $message, $code = 'error', $data = null ) {
		if ( ! is_array( $data ) ) {
			$data = array();
		}
		if ( ! isset( $data['status'] ) ) {
			$data['status'] = 400;
		}
		return new \WP_Error( $code, $message, $data );
	}
}
