<?php
/**
 * OAuth scopes class file.
 *
 * @package GoldtWebMCP
 */

namespace GoldtWebMCP\OAuth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages available OAuth scopes and their descriptions.
 *
 * @package GoldtWebMCP
 */
class Scopes {

	/**
	 * Get all available OAuth scopes with labels and descriptions.
	 *
	 * @return array
	 */
	public static function get_all_scopes() {
		return array(
			'read'         => array(
				'label'       => __( 'Read content', 'goldt-webmcp-bridge' ),
				'description' => __( 'Read posts, pages, and other content', 'goldt-webmcp-bridge' ),
			),
			'write'        => array(
				'label'       => __( 'Write content', 'goldt-webmcp-bridge' ),
				'description' => __( 'Create and update posts and pages', 'goldt-webmcp-bridge' ),
			),
			'delete'       => array(
				'label'       => __( 'Delete content', 'goldt-webmcp-bridge' ),
				'description' => __( 'Delete posts and pages', 'goldt-webmcp-bridge' ),
			),
			'manage_users' => array(
				'label'       => __( 'Manage users', 'goldt-webmcp-bridge' ),
				'description' => __( 'View and manage user accounts', 'goldt-webmcp-bridge' ),
			),
			'admin'        => array(
				'label'       => __( 'Site administration', 'goldt-webmcp-bridge' ),
				'description' => __( 'Use administrator-only tools: change site-wide settings, create users and change user roles. Only granted to users who can manage the site (manage_options), and every tool still checks the matching WordPress capability on each call.', 'goldt-webmcp-bridge' ),
			),
		);
	}

	/**
	 * Capability a WordPress user must hold to be granted, and to use, the
	 * `admin` scope.
	 *
	 * @return string
	 */
	public static function get_admin_capability() {
		/**
		 * Filter the WordPress capability required for the `admin` OAuth scope.
		 *
		 * @param string $capability Default 'manage_options'.
		 */
		$cap = apply_filters( 'goldtwmcp_admin_scope_capability', 'manage_options' );
		return is_string( $cap ) && '' !== $cap ? $cap : 'manage_options';
	}

	/**
	 * Drop scopes the given user is not entitled to receive.
	 *
	 * Today this only concerns `admin`: it is removed for users who lack the
	 * admin capability, so a non-administrator who approves a consent request
	 * that asks for `admin` simply receives a token without it.
	 *
	 * @param array $scopes  Requested scopes.
	 * @param int   $user_id WordPress user ID.
	 * @return array
	 */
	public static function filter_for_user( $scopes, $user_id ) {
		$scopes = array_values( array_unique( array_map( 'strval', (array) $scopes ) ) );

		if ( in_array( 'admin', $scopes, true ) && ! user_can( (int) $user_id, self::get_admin_capability() ) ) {
			$scopes = array_values( array_diff( $scopes, array( 'admin' ) ) );
		}

		return $scopes;
	}

	/**
	 * Get human-readable label for a scope.
	 *
	 * @param string $scope Scope identifier.
	 * @return string
	 */
	public static function get_scope_label( $scope ) {
		$scopes = self::get_all_scopes();
		return isset( $scopes[ $scope ]['label'] ) ? $scopes[ $scope ]['label'] : ucfirst( $scope );
	}

	/**
	 * Get description for a scope.
	 *
	 * @param string $scope Scope identifier.
	 * @return string
	 */
	public static function get_scope_description( $scope ) {
		$scopes = self::get_all_scopes();
		return isset( $scopes[ $scope ]['description'] ) ? $scopes[ $scope ]['description'] : '';
	}

	/**
	 * Validate whether a scope is recognized.
	 *
	 * @param string $scope Scope identifier to validate.
	 * @return bool
	 */
	public static function validate_scope( $scope ) {
		$scopes = self::get_all_scopes();
		return isset( $scopes[ $scope ] );
	}
}
