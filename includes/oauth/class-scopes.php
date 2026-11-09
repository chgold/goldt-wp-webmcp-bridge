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
	 * WordPress capabilities that gate the privileged scopes.
	 *
	 * A user is entitled to a gated scope only while holding AT LEAST ONE of
	 * the listed capabilities. The same map is used at grant time (consent /
	 * authorization code) and again on every tool call, so an OAuth client —
	 * whichever AI product it is — can never act beyond the WordPress user's
	 * own permissions. `read` and `write` are not gated here (individual
	 * tools check their own capabilities, e.g. moderate_comments).
	 *
	 * @return array<string,array<int,string>> Scope => any-of capability list.
	 */
	public static function get_scope_capabilities() {
		$map = array(
			'delete'       => array( 'delete_posts', 'delete_pages' ),
			'manage_users' => array( 'list_users', 'edit_users' ),
		);

		/**
		 * Filter the any-of capability lists that gate the `delete` and
		 * `manage_users` OAuth scopes. The `admin` scope is controlled by the
		 * separate goldtwmcp_admin_scope_capability filter.
		 *
		 * @param array $map Scope => array of capabilities (any-of).
		 */
		$map = apply_filters( 'goldtwmcp_scope_capabilities', $map );
		$map = is_array( $map ) ? $map : array();

		$map['admin'] = array( self::get_admin_capability() );

		return $map;
	}

	/**
	 * Whether a user currently holds the WordPress capability behind a scope.
	 *
	 * Ungated scopes (read, write, unknown) always return true.
	 *
	 * @param string $scope   Scope identifier.
	 * @param int    $user_id WordPress user ID.
	 * @return bool
	 */
	public static function user_can_use_scope( $scope, $user_id ) {
		$map = self::get_scope_capabilities();

		if ( ! isset( $map[ $scope ] ) ) {
			return true;
		}

		foreach ( (array) $map[ $scope ] as $cap ) {
			if ( is_string( $cap ) && '' !== $cap && user_can( (int) $user_id, $cap ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Drop scopes the given user is not entitled to receive.
	 *
	 * `delete`, `manage_users` and `admin` are removed for users who lack the
	 * matching WordPress capability (see get_scope_capabilities()), so a user
	 * who approves a consent request asking for more than they can do simply
	 * receives a token capped at their own permissions.
	 *
	 * @param array $scopes  Requested scopes.
	 * @param int   $user_id WordPress user ID.
	 * @return array
	 */
	public static function filter_for_user( $scopes, $user_id ) {
		$scopes = array_values( array_unique( array_map( 'strval', (array) $scopes ) ) );

		return array_values(
			array_filter(
				$scopes,
				static function ( $scope ) use ( $user_id ) {
					return self::user_can_use_scope( $scope, $user_id );
				}
			)
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
