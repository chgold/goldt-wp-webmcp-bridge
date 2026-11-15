<?php
/**
 * The user's own confirm page for AI actions that need confirmation.
 *
 * @package GoldtWebMCP
 */

namespace GoldtWebMCP\User;

use GoldtWebMCP\Core\Confirmation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Users › AI actions (Profile › AI actions for users without list_users).
 *
 * Here — and only here — a pending action is confirmed or rejected. A decision
 * needs the user's own browser session: a valid logged-in auth cookie for the
 * same user, a per-action nonce, a POST, and no Authorization header (an agent
 * holding the user's Bearer token cannot confirm its own request).
 *
 * The page also lists the user's autonomy grants (revocable) and the recent audit
 * trail, and every admin screen shows a notice while actions are waiting.
 *
 * @since 1.2.13
 */
class Confirmation_Page {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 50 );
		add_action( 'admin_notices', array( $this, 'pending_notice' ) );
	}

	/**
	 * Parent menu for the current user.
	 *
	 * @return string
	 */
	private function parent() {
		return current_user_can( 'list_users' ) ? 'users.php' : 'profile.php';
	}

	/**
	 * Add the submenu and the POST handler (runs before any output).
	 *
	 * @return void
	 */
	public function add_menu() {
		$hook = add_submenu_page(
			$this->parent(),
			__( 'AI actions awaiting your confirmation', 'goldt-webmcp-bridge' ),
			__( 'AI actions', 'goldt-webmcp-bridge' ),
			'read',
			Confirmation::PAGE_SLUG,
			array( $this, 'render' )
		);
		if ( $hook ) {
			add_action( 'load-' . $hook, array( $this, 'handle_post' ) );
		}
	}

	/**
	 * Is this a decision made by the user in their own browser session?
	 *
	 * @return bool
	 */
	public static function is_human_session() {
		if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) || ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			return false;
		}
		$uid = (int) get_current_user_id();
		return $uid > 0 && (int) wp_validate_auth_cookie( '', 'logged_in' ) === $uid;
	}

	/**
	 * Handle confirm / reject / revoke (POST, nonce, own session), then redirect.
	 *
	 * @return void
	 */
	public function handle_post() {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( 'POST' !== $method || ! isset( $_POST['goldtwmcp_decision'] ) ) {
			return;
		}
		if ( ! self::is_human_session() ) {
			wp_die( esc_html__( 'Confirmations must be made by you in your own signed-in browser session.', 'goldt-webmcp-bridge' ), 403 );
		}
		$decision = sanitize_key( wp_unslash( $_POST['goldtwmcp_decision'] ) );
		$base     = admin_url( $this->parent() . '?page=' . Confirmation::PAGE_SLUG );

		if ( 'revoke_grant' === $decision ) {
			$grant_id = isset( $_POST['grant_id'] ) ? absint( wp_unslash( $_POST['grant_id'] ) ) : 0;
			check_admin_referer( 'goldtwmcp_revoke_grant_' . $grant_id );
			$ok = Confirmation::revoke_grant( $grant_id, get_current_user_id() );
			wp_safe_redirect( add_query_arg( 'goldtwmcp_msg', $ok ? 'revoked' : 'revoke_failed', $base ) );
			exit;
		}

		$id = isset( $_POST['confirmation_id'] ) ? sanitize_text_field( wp_unslash( $_POST['confirmation_id'] ) ) : '';
		check_admin_referer( 'goldtwmcp_confirm_' . $id );
		$action = Confirmation::get_action( $id );
		if ( ! $action || (int) get_current_user_id() !== (int) $action['user_id'] ) {
			wp_die( esc_html__( 'This action does not belong to you.', 'goldt-webmcp-bridge' ), 403 );
		}
		$back = add_query_arg( 'confirmation_id', rawurlencode( $id ), $base );
		if ( 'reject' === $decision ) {
			$ok = Confirmation::reject( $id );
			wp_safe_redirect( add_query_arg( 'goldtwmcp_msg', $ok ? 'rejected' : 'not_pending', $back ) );
			exit;
		}
		if ( 'confirm' === $decision ) {
			$autonomy = ! empty( $_POST['grant_autonomy'] );
			$out      = Confirmation::confirm( $id, $autonomy );
			$msg      = $out['ok'] ? 'executed' : ( 'failed' === $out['status'] ? 'failed' : 'not_pending' );
			wp_safe_redirect( add_query_arg( 'goldtwmcp_msg', $msg, $back ) );
			exit;
		}
	}

	/**
	 * Notice on every admin screen while actions are waiting.
	 *
	 * @return void
	 */
	public function pending_notice() {
		if ( ! is_user_logged_in() || ! Confirmation::installed() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		if ( isset( $_GET['page'] ) && Confirmation::PAGE_SLUG === $_GET['page'] ) {
			return;
		}
		$pending = Confirmation::list_actions( get_current_user_id(), 'pending', 20 );
		if ( empty( $pending ) ) {
			return;
		}
		$url = admin_url( $this->parent() . '?page=' . Confirmation::PAGE_SLUG );
		echo '<div class="notice notice-warning"><p><strong>'
			. esc_html(
				sprintf(
					/* translators: %d: number of actions */
					_n( '%d AI action is waiting for your confirmation.', '%d AI actions are waiting for your confirmation.', count( $pending ), 'goldt-webmcp-bridge' ),
					count( $pending )
				)
			)
			. '</strong> <a href="' . esc_url( $url ) . '">' . esc_html__( 'Review', 'goldt-webmcp-bridge' ) . '</a></p></div>';
	}

	/**
	 * Render the page.
	 *
	 * @return void
	 */
	public function render() {
		$user_id = get_current_user_id();
		echo '<div class="wrap"><h1>' . esc_html__( 'AI actions awaiting your confirmation', 'goldt-webmcp-bridge' ) . '</h1>';
		$this->render_message();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view selection.
		$id = isset( $_GET['confirmation_id'] ) ? sanitize_text_field( wp_unslash( $_GET['confirmation_id'] ) ) : '';
		if ( '' !== $id ) {
			$action = Confirmation::get_action( $id );
			if ( ! $action || (int) $action['user_id'] !== (int) $user_id ) {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'Unknown action, or it belongs to another account.', 'goldt-webmcp-bridge' ) . '</p></div></div>';
				return;
			}
			$this->render_action( $action );
			echo '</div>';
			return;
		}

		echo '<p>' . esc_html__( 'An AI agent connected to your account asked to run these actions. Nothing has happened yet: each one runs only if you confirm it here.', 'goldt-webmcp-bridge' ) . '</p>';
		$pending = Confirmation::list_actions( $user_id, 'pending', 50 );
		if ( empty( $pending ) ) {
			echo '<p><em>' . esc_html__( 'Nothing is waiting.', 'goldt-webmcp-bridge' ) . '</em></p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Action', 'goldt-webmcp-bridge' ) . '</th><th>' . esc_html__( 'Risk', 'goldt-webmcp-bridge' ) . '</th><th>' . esc_html__( 'Agent', 'goldt-webmcp-bridge' ) . '</th><th>' . esc_html__( 'Expires (UTC)', 'goldt-webmcp-bridge' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( $pending as $a ) {
				$link = add_query_arg( 'confirmation_id', rawurlencode( $a['id'] ), admin_url( $this->parent() . '?page=' . Confirmation::PAGE_SLUG ) );
				echo '<tr><td><code>' . esc_html( $a['tool'] ) . '</code><br>' . esc_html( $a['summary'] ) . '</td><td>' . esc_html( $a['risk'] ) . '</td><td>' . esc_html( $a['client_key'] ) . '</td><td>' . esc_html( $a['expires_at'] ) . '</td><td><a class="button button-primary" href="' . esc_url( $link ) . '">' . esc_html__( 'Review', 'goldt-webmcp-bridge' ) . '</a></td></tr>';
			}
			echo '</tbody></table>';
		}

		$this->render_grants( $user_id );
		$this->render_log( $user_id );
		echo '</div>';
	}

	/**
	 * Result message after a redirect.
	 *
	 * @return void
	 */
	private function render_message() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Message key after our own redirect.
		$msg      = isset( $_GET['goldtwmcp_msg'] ) ? sanitize_key( wp_unslash( $_GET['goldtwmcp_msg'] ) ) : '';
		$messages = array(
			'executed'      => array( 'success', __( 'Confirmed. The action ran once.', 'goldt-webmcp-bridge' ) ),
			'failed'        => array( 'error', __( 'Confirmed, but the action failed. See the result below.', 'goldt-webmcp-bridge' ) ),
			'rejected'      => array( 'success', __( 'Rejected. The action will not run.', 'goldt-webmcp-bridge' ) ),
			'not_pending'   => array( 'error', __( 'This action was already decided or has expired.', 'goldt-webmcp-bridge' ) ),
			'revoked'       => array( 'success', __( 'Permission removed. The agent must ask you again.', 'goldt-webmcp-bridge' ) ),
			'revoke_failed' => array( 'error', __( 'That permission was not found.', 'goldt-webmcp-bridge' ) ),
		);
		if ( isset( $messages[ $msg ] ) ) {
			echo '<div class="notice notice-' . esc_attr( $messages[ $msg ][0] ) . '"><p>' . esc_html( $messages[ $msg ][1] ) . '</p></div>';
		}
	}

	/**
	 * One action: preview + decision form.
	 *
	 * @param array $action Action row.
	 * @return void
	 */
	private function render_action( array $action ) {
		$preview = json_decode( (string) $action['preview_json'], true );
		$risk    = 'financial' === $action['risk'] ? __( 'Financial', 'goldt-webmcp-bridge' ) : __( 'Destructive', 'goldt-webmcp-bridge' );
		echo '<table class="form-table"><tbody>';
		echo '<tr><th>' . esc_html__( 'Action', 'goldt-webmcp-bridge' ) . '</th><td><code>' . esc_html( $action['tool'] ) . '</code> ' . esc_html( $action['summary'] ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Risk', 'goldt-webmcp-bridge' ) . '</th><td><strong>' . esc_html( $risk ) . '</strong></td></tr>';
		echo '<tr><th>' . esc_html__( 'Requested by', 'goldt-webmcp-bridge' ) . '</th><td>' . esc_html( $action['client_key'] ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Requested (UTC)', 'goldt-webmcp-bridge' ) . '</th><td>' . esc_html( $action['created_at'] ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Expires (UTC)', 'goldt-webmcp-bridge' ) . '</th><td>' . esc_html( $action['expires_at'] ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Status', 'goldt-webmcp-bridge' ) . '</th><td>' . esc_html( $action['status'] ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'What will happen', 'goldt-webmcp-bridge' ) . '</th><td><pre style="white-space:pre-wrap;max-width:900px">' . esc_html( (string) wp_json_encode( $preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) . '</pre></td></tr>';
		if ( in_array( $action['status'], array( 'executed', 'failed' ), true ) ) {
			$result = json_decode( (string) $action['result_json'], true );
			echo '<tr><th>' . esc_html__( 'Result', 'goldt-webmcp-bridge' ) . '</th><td><pre style="white-space:pre-wrap;max-width:900px">' . esc_html( (string) wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) . '</pre></td></tr>';
		}
		echo '</tbody></table>';

		if ( 'pending' !== $action['status'] ) {
			return;
		}
		$form_url = admin_url( $this->parent() . '?page=' . Confirmation::PAGE_SLUG );
		echo '<form method="post" action="' . esc_url( $form_url ) . '">';
		wp_nonce_field( 'goldtwmcp_confirm_' . $action['id'] );
		echo '<input type="hidden" name="confirmation_id" value="' . esc_attr( $action['id'] ) . '">';
		if ( Confirmation::autonomy_allowed( $action['tool'], array( 'risk' => $action['risk'] ) ) ) {
			echo '<p><label><input type="checkbox" name="grant_autonomy" value="1"> '
				. esc_html(
					sprintf(
						/* translators: 1: agent, 2: tool */
						__( 'From now on, let %1$s run %2$s without asking me (you can remove this below at any time).', 'goldt-webmcp-bridge' ),
						$action['client_key'],
						$action['tool']
					)
				)
				. '</label></p>';
		}
		echo '<p><button type="submit" name="goldtwmcp_decision" value="confirm" class="button button-primary">' . esc_html__( 'Confirm and run', 'goldt-webmcp-bridge' ) . '</button> ';
		echo '<button type="submit" name="goldtwmcp_decision" value="reject" class="button">' . esc_html__( 'Reject', 'goldt-webmcp-bridge' ) . '</button></p>';
		echo '</form>';
	}

	/**
	 * Autonomy grants with revoke buttons.
	 *
	 * @param int $user_id User.
	 * @return void
	 */
	private function render_grants( $user_id ) {
		echo '<h2>' . esc_html__( 'Agents you let act without asking', 'goldt-webmcp-bridge' ) . '</h2>';
		$grants = Confirmation::list_grants( $user_id );
		if ( empty( $grants ) ) {
			echo '<p><em>' . esc_html__( 'None. Every action above needs your confirmation.', 'goldt-webmcp-bridge' ) . '</em></p>';
			return;
		}
		$form_url = admin_url( $this->parent() . '?page=' . Confirmation::PAGE_SLUG );
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Tool', 'goldt-webmcp-bridge' ) . '</th><th>' . esc_html__( 'Agent', 'goldt-webmcp-bridge' ) . '</th><th>' . esc_html__( 'Since (UTC)', 'goldt-webmcp-bridge' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $grants as $g ) {
			echo '<tr><td><code>' . esc_html( $g['tool'] ) . '</code></td><td>' . esc_html( $g['client_key'] ) . '</td><td>' . esc_html( $g['granted_at'] ) . '</td><td>';
			echo '<form method="post" action="' . esc_url( $form_url ) . '">';
			wp_nonce_field( 'goldtwmcp_revoke_grant_' . (int) $g['id'] );
			echo '<input type="hidden" name="grant_id" value="' . esc_attr( (string) (int) $g['id'] ) . '">';
			echo '<button type="submit" name="goldtwmcp_decision" value="revoke_grant" class="button">' . esc_html__( 'Remove', 'goldt-webmcp-bridge' ) . '</button></form></td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Recent audit events.
	 *
	 * @param int $user_id User.
	 * @return void
	 */
	private function render_log( $user_id ) {
		$rows = Confirmation::list_log( $user_id, 30 );
		if ( empty( $rows ) ) {
			return;
		}
		echo '<h2>' . esc_html__( 'Recent activity', 'goldt-webmcp-bridge' ) . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__( 'When (UTC)', 'goldt-webmcp-bridge' ) . '</th><th>' . esc_html__( 'Event', 'goldt-webmcp-bridge' ) . '</th><th>' . esc_html__( 'Tool', 'goldt-webmcp-bridge' ) . '</th><th>' . esc_html__( 'Agent', 'goldt-webmcp-bridge' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			echo '<tr><td>' . esc_html( $r['created_at'] ) . '</td><td>' . esc_html( $r['event'] ) . '</td><td><code>' . esc_html( $r['tool'] ) . '</code></td><td>' . esc_html( $r['client_key'] ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}
}
