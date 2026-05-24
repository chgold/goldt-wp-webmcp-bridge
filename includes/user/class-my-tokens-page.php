<?php
/**
 * User-facing "My AI Tokens" admin page.
 *
 * Registered under the Users menu — visible only when the current user has at
 * least one active or renewable token. Every revoke action cascades into the
 * oauth_tokens table so the refresh_token is also invalidated.
 *
 * @package GoldtWebMCP
 */

namespace GoldtWebMCP\User;

use GoldtWebMCP\OAuth\Token_Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * My AI Tokens — user-facing token manager.
 *
 * @package GoldtWebMCP
 */
class My_Tokens_Page {

	/**
	 * Menu/page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'goldt-my-ai-tokens';

	/**
	 * Allowed status filters for this page.
	 *
	 * @var string[]
	 */
	const VALID_STATUSES = array( 'active', 'unused', 'inactive', 'renewable', 'expired', 'revoked', 'all' );

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'maybe_add_menu' ), 50 );
	}

	/**
	 * Register the submenu only when the current user has active/renewable tokens.
	 *
	 * @return void
	 */
	public function maybe_add_menu() {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$user_id = get_current_user_id();

		if ( ! Token_Registry::user_has_active_tokens( $user_id ) ) {
			return;
		}

		add_submenu_page(
			'users.php',
			__( 'My AI Tokens', 'goldt-webmcp-bridge' ),
			__( 'My AI Tokens', 'goldt-webmcp-bridge' ),
			'read', // Any logged-in user.
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the page, handling all POST actions before output.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'You must be logged in to view this page.', 'goldt-webmcp-bridge' ) );
		}

		$user_id     = get_current_user_id();
		$notice      = '';
		$notice_type = 'success';

		// ── Confirm-page for single revoke ─────────────────────────────────────
		if ( isset( $_GET['goldtwmcp_revoke_id'] ) && ! isset( $_POST['goldtwmcp_confirm_revoke'] ) ) {
			$row_id = absint( wp_unslash( $_GET['goldtwmcp_revoke_id'] ) );
			$row    = $row_id > 0 ? Token_Registry::get( $row_id ) : null;
			if ( $row && (int) $row['user_id'] === $user_id && empty( $row['revoked_at'] ) ) {
				include GOLDTWMCP_PATH . 'includes/user/views/my-tokens-revoke.php';
				return;
			}
		}

		// ── Confirm-page for revoke-all ────────────────────────────────────────
		if ( isset( $_GET['goldtwmcp_revoke_all'] ) && ! isset( $_POST['goldtwmcp_confirm_revoke_all'] ) ) {
			$filter_status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'all';
			include GOLDTWMCP_PATH . 'includes/user/views/my-tokens-revoke-all.php';
			return;
		}

		// ── Execute single revoke ──────────────────────────────────────────────
		if ( isset( $_POST['goldtwmcp_confirm_revoke'] ) ) {
			check_admin_referer( 'goldtwmcp_my_tokens_revoke_' . get_current_user_id() );
			$row_id = absint( wp_unslash( $_POST['goldtwmcp_revoke_id'] ?? '0' ) );
			if ( $row_id > 0 ) {
				$row = Token_Registry::get( $row_id );
				// Security: only allow revoking own tokens.
				if ( $row && (int) $row['user_id'] === $user_id ) {
					$ok = Token_Registry::revoke_by_id( $row_id, $user_id );
					if ( $ok ) {
						$notice = __( 'Token revoked. The AI agent using it has been disconnected.', 'goldt-webmcp-bridge' );
					} else {
						$notice      = __( 'Token could not be revoked (already revoked or not found).', 'goldt-webmcp-bridge' );
						$notice_type = 'error';
					}
				} else {
					$notice      = __( 'Permission denied.', 'goldt-webmcp-bridge' );
					$notice_type = 'error';
				}
			}
		}

		// ── Execute revoke-all (filter-aware) ─────────────────────────────────
		if ( isset( $_POST['goldtwmcp_confirm_revoke_all'] ) ) {
			check_admin_referer( 'goldtwmcp_my_tokens_revoke_all_' . get_current_user_id() );
			$filter_status = isset( $_POST['goldtwmcp_filter_status'] ) ? sanitize_key( wp_unslash( $_POST['goldtwmcp_filter_status'] ) ) : 'all';

			if ( 'all' === $filter_status ) {
				// Revoke everything for this user.
				$count = Token_Registry::revoke_all_for_user( $user_id, $user_id );
			} else {
				// Revoke only IDs matching the current filter.
				$rows  = Token_Registry::list(
					array(
						'status'  => $filter_status,
						'user_id' => $user_id,
						'limit'   => 500,
					)
				);
				$ids   = array_map(
					function ( $r ) {
						return (int) $r['id'];
					},
					$rows
				);
				$count = Token_Registry::revoke_by_ids( $ids, $user_id );
			}

			$notice = sprintf(
				/* translators: %d: number of tokens revoked */
				_n( '%d token revoked.', '%d tokens revoked.', $count, 'goldt-webmcp-bridge' ),
				$count
			);
		}

		// ── Build token list ──────────────────────────────────────────────────
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'active';
		if ( ! in_array( $status, self::VALID_STATUSES, true ) ) {
			$status = 'active';
		}

		$rows = Token_Registry::list(
			array(
				'status'  => $status,
				'user_id' => $user_id,
				'limit'   => 200,
			)
		);

		include GOLDTWMCP_PATH . 'includes/user/views/my-tokens-list.php';
	}
}
