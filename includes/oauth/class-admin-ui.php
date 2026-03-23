<?php
/**
 * OAuth admin UI class file.
 *
 * @package GoldtWebMCP
 */

namespace GoldtWebMCP\OAuth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides the OAuth tokens admin interface.
 *
 * @package GoldtWebMCP
 */
class Admin_UI {

	/**
	 * Initialize WordPress hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 20 );
	}

	/**
	 * Add OAuth Tokens submenu item.
	 *
	 * @return void
	 */
	public function add_menu() {
		add_submenu_page(
			'goldt-webmcp-bridge',
			__( 'OAuth Tokens', 'goldt-webmcp-bridge' ),
			__( 'OAuth Tokens', 'goldt-webmcp-bridge' ),
			'manage_options',
			'ai-connect-oauth',
			array( $this, 'render_tokens_page' )
		);
	}

	/**
	 * Render the OAuth tokens admin page.
	 *
	 * @return void
	 */
	public function render_tokens_page() {
		if ( isset( $_POST['revoke_token'] ) ) {
			check_admin_referer( 'goldtwmcp_revoke_token' );
			$token_id = isset( $_POST['token_id'] ) ? sanitize_text_field( wp_unslash( $_POST['token_id'] ) ) : '';
			$this->revoke_token( $token_id );
		}

		global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- OAuth tokens admin listing
		$tokens = $wpdb->get_results(
			"
            SELECT t.*, c.client_name, u.user_login
            FROM {$wpdb->prefix}goldtwmcp_oauth_tokens t
            LEFT JOIN {$wpdb->prefix}goldtwmcp_oauth_clients c ON t.client_id = c.client_id
            LEFT JOIN {$wpdb->users} u ON t.user_id = u.ID
            WHERE t.revoked_at IS NULL
            ORDER BY t.created_at DESC
        "
		);

		include GOLDTWMCP_PATH . 'includes/oauth/views/admin-tokens.php';
	}

	/**
	 * Revoke a token by ID.
	 *
	 * @param string $token_id Token ID to revoke.
	 * @return void
	 */
	private function revoke_token( $token_id ) {
		global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- OAuth token revocation
		$wpdb->update(
			"{$wpdb->prefix}goldtwmcp_oauth_tokens",
			array( 'revoked_at' => current_time( 'mysql' ) ),
			array( 'id' => intval( $token_id ) ),
			array( '%s' ),
			array( '%d' )
		);

		echo '<div class="notice notice-success"><p>' . esc_html__( 'Token revoked successfully.', 'goldt-webmcp-bridge' ) . '</p></div>';
	}
}
