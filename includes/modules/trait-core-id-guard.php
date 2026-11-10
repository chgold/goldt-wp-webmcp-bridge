<?php
/**
 * Central ID-parameter guard for the core tools.
 *
 * @package GoldtWebMCP
 */

namespace GoldtWebMCP\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rejects negative / zero object IDs before any tool handler runs.
 *
 * The core handlers pass agent-supplied IDs through absint(), which silently
 * turns -1 into 1 (for example listComments with post_id -1 listed the
 * comments of post 1). This trait overrides Module_Base::validate_params() so
 * that every integer parameter that names an object (id, *_id, *_ids, parent,
 * author, ...) must be a positive integer. A required ID must be >= 1; an
 * optional one may also be 0 (its documented "none / top level" value). A
 * schema "minimum" overrides the default. Counters (limit, offset, per_page,
 * number) may not be negative. Anything else returns 400 invalid_param.
 */
trait Core_Id_Guard {

	/**
	 * Validate parameters with the base rules, then enforce positive IDs.
	 *
	 * @param array $params Input parameters.
	 * @param array $schema JSON schema.
	 * @return array|\WP_Error
	 */
	protected function validate_params( $params, $schema ) {
		$validated = parent::validate_params( $params, $schema );
		if ( \is_wp_error( $validated ) || empty( $schema['properties'] ) ) {
			return $validated;
		}
		$required = isset( $schema['required'] ) ? (array) $schema['required'] : array();

		foreach ( $schema['properties'] as $key => $prop ) {
			if ( ! array_key_exists( $key, (array) $params ) ) {
				continue;
			}
			$value   = $params[ $key ];
			$type    = $prop['type'] ?? '';
			$is_req  = in_array( $key, $required, true );
			$default = $is_req ? 1 : 0;

			if ( 'integer' === $type && $this->goldtwmcp_is_id_key( $key ) ) {
				$min = isset( $prop['minimum'] ) ? (int) $prop['minimum'] : $default;
				if ( ! $this->goldtwmcp_int_at_least( $value, $min ) ) {
					return $this->goldtwmcp_invalid_id( $key, $min );
				}
			} elseif ( 'integer' === $type && $this->goldtwmcp_is_counter_key( $key ) ) {
				if ( ! $this->goldtwmcp_int_at_least( $value, 0 ) ) {
					return new \WP_Error( 'invalid_param', sprintf( 'Parameter %s must be 0 or a positive integer.', $key ), array( 'status' => 400 ) );
				}
			} elseif ( 'array' === $type && 'integer' === ( $prop['items']['type'] ?? '' ) && $this->goldtwmcp_is_id_key( $key ) ) {
				foreach ( (array) $value as $item ) {
					if ( ! $this->goldtwmcp_int_at_least( $item, 1 ) ) {
						return new \WP_Error( 'invalid_param', sprintf( 'Every item of %s must be a positive integer ID.', $key ), array( 'status' => 400 ) );
					}
				}
			}
		}
		return $validated;
	}

	/**
	 * Whether a parameter name refers to an object ID.
	 *
	 * @param string $key Parameter name.
	 * @return bool
	 */
	private function goldtwmcp_is_id_key( $key ) {
		return (bool) preg_match( '/^(id|parent|author|reassign|featured_media|attached_to|categories|default_category)$|_ids?$/', (string) $key );
	}

	/**
	 * Whether a parameter name is a non-negative counter.
	 *
	 * @param string $key Parameter name.
	 * @return bool
	 */
	private function goldtwmcp_is_counter_key( $key ) {
		return in_array( (string) $key, array( 'number', 'offset', 'per_page', 'limit', 'position', 'posts_per_page', 'start_of_week' ), true );
	}

	/**
	 * Whether a value is an integer (or digit string) >= $min.
	 *
	 * @param mixed $value Value.
	 * @param int   $min   Minimum.
	 * @return bool
	 */
	private function goldtwmcp_int_at_least( $value, $min ) {
		if ( is_int( $value ) ) {
			return $value >= $min;
		}
		if ( is_string( $value ) && '' !== $value && ctype_digit( $value ) ) {
			return (int) $value >= $min;
		}
		return false;
	}

	/**
	 * Build the 400 error for a bad ID.
	 *
	 * @param string $key Parameter name.
	 * @param int    $min Minimum accepted value.
	 * @return \WP_Error
	 */
	private function goldtwmcp_invalid_id( $key, $min ) {
		return new \WP_Error(
			'invalid_param',
			0 === $min
				? sprintf( 'Parameter %s must be a positive integer ID (or 0 for none).', $key )
				: sprintf( 'Parameter %s must be a positive integer ID.', $key ),
			array( 'status' => 400 )
		);
	}
}
