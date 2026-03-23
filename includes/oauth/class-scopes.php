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
		);
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
