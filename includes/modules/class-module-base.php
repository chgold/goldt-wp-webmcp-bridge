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
			'required_scope' => $config['required_scope'] ?? 'read',
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
				return new \WP_Error( 'missing_parameter', sprintf( 'Required parameter %s is missing', $key ) );
			}

			if ( isset( $params[ $key ] ) ) {
				$value = $params[ $key ];

				if ( isset( $prop['type'] ) ) {
					$type_valid = $this->validate_type( $value, $prop['type'] );
					if ( ! $type_valid ) {
						return new \WP_Error(
							'invalid_type',
							sprintf( 'Parameter %s must be of type %s', $key, $prop['type'] )
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
		return new \WP_Error( $code, $message, $data );
	}
}
