<?php
/**
 * Token registry admin REST endpoints + WP-Admin page.
 *
 * @package GoldtWebMCP
 */

namespace GoldtWebMCP\Admin;

use GoldtWebMCP\OAuth\Token_Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin surface for the token registry.
 *
 * Exposes:
 *  - WP-Admin page under the existing AI Connect menu.
 *  - REST endpoints under /wp-json/goldt-mcp/v1/admin/tokens (manage_options only).
 *
 * @package GoldtWebMCP
 */
class Token_Registry_Admin {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	const REST_NAMESPACE = 'goldt-mcp/v1';

	/**
	 * Admin sub-menu slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'ai-connect-token-registry';

	/**
	 * Allowed status filter values for admin view.
	 *
	 * @var string[]
	 */
	const VALID_STATUSES = array( 'active', 'unused', 'inactive', 'renewable', 'expired', 'revoked', 'all' );

	/**
	 * Initialize WordPress hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 30 );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the admin submenu page.
	 *
	 * @return void
	 */
	public function add_menu() {
		add_submenu_page(
			'goldt-webmcp-bridge',
			__( 'Token Registry', 'goldt-webmcp-bridge' ),
			__( 'Token Registry', 'goldt-webmcp-bridge' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the admin page (with inline revoke + bulk action handler).
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'goldt-webmcp-bridge' ) );
		}

		$notice      = '';
		$notice_type = 'success';

		// ── Single revoke ──────────────────────────────────────────────────────
		if ( isset( $_POST['goldtwmcp_revoke_registry_id'] ) ) {
			check_admin_referer( 'goldtwmcp_token_registry_revoke' );
			$row_id = absint( wp_unslash( $_POST['goldtwmcp_revoke_registry_id'] ) );
			if ( $row_id > 0 ) {
				$revoked = Token_Registry::revoke_by_id( $row_id, get_current_user_id() );
				if ( $revoked ) {
					$notice = __( 'Token revoked successfully.', 'goldt-webmcp-bridge' );
				} else {
					$notice      = __( 'Token could not be revoked (already revoked or not found).', 'goldt-webmcp-bridge' );
					$notice_type = 'error';
				}
			}
		}

		// ── Bulk: revoke unused (30 d) ─────────────────────────────────────────
		if ( isset( $_POST['goldtwmcp_bulk_revoke_unused'] ) ) {
			check_admin_referer( 'goldtwmcp_token_registry_bulk' );
			$count  = Token_Registry::revoke_unused( 30, get_current_user_id() );
			$notice = sprintf(
				/* translators: %d: number of tokens revoked */
				_n( '%d unused token revoked.', '%d unused tokens revoked.', $count, 'goldt-webmcp-bridge' ),
				$count
			);
		}

		// ── Bulk: revoke inactive (180 d) ──────────────────────────────────────
		if ( isset( $_POST['goldtwmcp_bulk_revoke_inactive'] ) ) {
			check_admin_referer( 'goldtwmcp_token_registry_bulk' );
			$count  = Token_Registry::revoke_inactive( 180, get_current_user_id() );
			$notice = sprintf(
				/* translators: %d: number of tokens revoked */
				_n( '%d inactive token revoked.', '%d inactive tokens revoked.', $count, 'goldt-webmcp-bridge' ),
				$count
			);
		}

		// ── Bulk: revoke ALL active ────────────────────────────────────────────
		if ( isset( $_POST['goldtwmcp_bulk_revoke_all'] ) ) {
			check_admin_referer( 'goldtwmcp_token_registry_bulk' );

			global $wpdb;
			$table = Token_Registry::table_name();
			$now   = time();
			$actor = get_current_user_id();

			// Collect prefixes for cascade. Table name comes from $wpdb->prefix — safe.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$prefixes = $wpdb->get_col(
				"SELECT token_prefix FROM `{$table}` WHERE revoked_at IS NULL"
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE `{$table}` SET revoked_at = %d, revoked_by = %d WHERE revoked_at IS NULL",
					$now,
					$actor
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$count = (int) $wpdb->rows_affected;

			if ( $count > 0 && ! empty( $prefixes ) ) {
				// Cascade into oauth_tokens via registry method.
				Token_Registry::revoke_by_ids( array(), $actor ); // no-op to initialise; cascade done below.
				// Direct cascade: call private-equivalent helper via public utility.
				$oauth_table = $wpdb->prefix . 'goldtwmcp_oauth_tokens';
				$now_mysql   = gmdate( 'Y-m-d H:i:s', $now );
				$safe        = array_filter(
					$prefixes,
					function ( $p ) {
						return strlen( $p ) >= 4 && strlen( $p ) <= 32;
					}
				);
				if ( ! empty( $safe ) ) {
					$placeholders = implode( ', ', array_fill( 0, count( $safe ), '%s' ) );
					// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
					$wpdb->query(
						$wpdb->prepare(
							"UPDATE `{$oauth_table}` SET revoked_at = %s WHERE revoked_at IS NULL AND LEFT(token, 16) IN ({$placeholders})",
							array_merge( array( $now_mysql ), $safe )
						)
					);
					// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
				}
			}

			$notice = sprintf(
				/* translators: %d: number of tokens revoked */
				_n( '%d token revoked.', '%d tokens revoked.', $count, 'goldt-webmcp-bridge' ),
				$count
			);
		}

		// ── Filter ─────────────────────────────────────────────────────────────
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'active';
		if ( ! in_array( $status, self::VALID_STATUSES, true ) ) {
			$status = 'active';
		}

		$rows = Token_Registry::list(
			array(
				'status' => $status,
				'limit'  => 200,
			)
		);

		include GOLDTWMCP_PATH . 'includes/admin/views/admin-token-registry.php';
	}

	/**
	 * Register the admin REST routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/admin/tokens',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'rest_list' ),
					'permission_callback' => array( $this, 'permission_check' ),
					'args'                => array(
						'status'  => array(
							'type'    => 'string',
							'enum'    => self::VALID_STATUSES,
							'default' => 'active',
						),
						'user_id' => array(
							'type'    => 'integer',
							'default' => 0,
						),
						'limit'   => array(
							'type'    => 'integer',
							'default' => 100,
							'minimum' => 1,
							'maximum' => 500,
						),
						'offset'  => array(
							'type'    => 'integer',
							'default' => 0,
							'minimum' => 0,
						),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/admin/tokens/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'rest_revoke' ),
					'permission_callback' => array( $this, 'permission_check' ),
					'args'                => array(
						'id' => array(
							'type'     => 'integer',
							'required' => true,
							'minimum'  => 1,
						),
					),
				),
			)
		);
	}

	/**
	 * REST permission callback — admin-only.
	 *
	 * @return bool|\WP_Error
	 */
	public function permission_check() {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		return new \WP_Error(
			'rest_forbidden',
			__( 'Sorry, you are not allowed to manage AI Connect tokens.', 'goldt-webmcp-bridge' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * GET /admin/tokens — list registry rows.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function rest_list( \WP_REST_Request $request ) {
		$rows = Token_Registry::list(
			array(
				'status'  => (string) $request->get_param( 'status' ),
				'user_id' => (int) $request->get_param( 'user_id' ),
				'limit'   => (int) $request->get_param( 'limit' ),
				'offset'  => (int) $request->get_param( 'offset' ),
			)
		);

		$data = array_map( array( $this, 'format_row' ), $rows );

		return rest_ensure_response(
			array(
				'success' => true,
				'count'   => count( $data ),
				'tokens'  => $data,
			)
		);
	}

	/**
	 * DELETE /admin/tokens/{id} — soft-delete a registry row (cascade-aware).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_revoke( \WP_REST_Request $request ) {
		$id  = (int) $request->get_param( 'id' );
		$row = Token_Registry::get( $id );

		if ( null === $row ) {
			return new \WP_Error(
				'not_found',
				__( 'Token registry row not found.', 'goldt-webmcp-bridge' ),
				array( 'status' => 404 )
			);
		}

		if ( null !== $row['revoked_at'] && $row['revoked_at'] > 0 ) {
			return rest_ensure_response(
				array(
					'success' => true,
					'message' => __( 'Token was already revoked.', 'goldt-webmcp-bridge' ),
					'token'   => $this->format_row( $row ),
				)
			);
		}

		// revoke_by_id now also cascades into oauth_tokens.
		$ok        = Token_Registry::revoke_by_id( $id, get_current_user_id() );
		$refreshed = Token_Registry::get( $id );

		return rest_ensure_response(
			array(
				'success' => (bool) $ok,
				'token'   => null !== $refreshed ? $this->format_row( $refreshed ) : null,
			)
		);
	}

	/**
	 * Return an HTML badge for a registry row's computed state.
	 *
	 * Every text string in the badge goes through esc_html__() so no
	 * user-supplied data reaches the HTML output unescaped.
	 *
	 * @param array $row Registry row.
	 * @return string Pre-escaped HTML badge (wrap with wp_kses before echo).
	 */
	public static function state_badge( array $row ): string {
		$now = time();
		if ( ! empty( $row['revoked_at'] ) ) {
			return '<span style="background:#c00;color:#fff;padding:2px 6px;border-radius:3px;font-size:11px;">'
				. '🔴 ' . esc_html__( 'Revoked', 'goldt-webmcp-bridge' ) . '</span>';
		}
		if ( (int) $row['expires_at'] <= $now ) {
			$has_refresh = ! empty( $row['refresh_expires_at'] ) && (int) $row['refresh_expires_at'] > $now;
			if ( $has_refresh ) {
				return '<span style="background:#0073aa;color:#fff;padding:2px 6px;border-radius:3px;font-size:11px;">'
					. '🔵 ' . esc_html__( 'Renewable', 'goldt-webmcp-bridge' ) . '</span>';
			}
			return '<span style="background:#555;color:#fff;padding:2px 6px;border-radius:3px;font-size:11px;">'
				. '⚫ ' . esc_html__( 'Expired', 'goldt-webmcp-bridge' ) . '</span>';
		}
		if ( empty( $row['last_used_at'] ) ) {
			return '<span style="background:#f0ad00;color:#fff;padding:2px 6px;border-radius:3px;font-size:11px;">'
				. '🟡 ' . esc_html__( 'Unused', 'goldt-webmcp-bridge' ) . '</span>';
		}
		if ( (int) $row['last_used_at'] < ( $now - 30 * DAY_IN_SECONDS ) ) {
			return '<span style="background:#d46b08;color:#fff;padding:2px 6px;border-radius:3px;font-size:11px;">'
				. '🟠 ' . esc_html__( 'Inactive', 'goldt-webmcp-bridge' ) . '</span>';
		}
		return '<span style="background:#46b450;color:#fff;padding:2px 6px;border-radius:3px;font-size:11px;">'
			. '🟢 ' . esc_html__( 'Active', 'goldt-webmcp-bridge' ) . '</span>';
	}

	/**
	 * Allowed HTML for wp_kses() when outputting state_badge().
	 *
	 * @return array<string, array<string, array<mixed>>>
	 */
	public static function badge_kses_rules(): array {
		return array(
			'span' => array(
				'style' => array(),
			),
		);
	}

	/**
	 * Serialize a registry row for REST output.
	 *
	 * @param array $row Raw DB row.
	 * @return array
	 */
	private function format_row( $row ) {
		$user = isset( $row['user_id'] ) ? get_userdata( (int) $row['user_id'] ) : false;

		return array(
			'id'                 => isset( $row['id'] ) ? (int) $row['id'] : 0,
			'token_prefix'       => isset( $row['token_prefix'] ) ? (string) $row['token_prefix'] : '',
			'user_id'            => isset( $row['user_id'] ) ? (int) $row['user_id'] : 0,
			'user_login'         => $user ? $user->user_login : null,
			'client_id'          => isset( $row['client_id'] ) ? (string) $row['client_id'] : '',
			'scope'              => isset( $row['scope'] ) ? (string) $row['scope'] : '',
			'issued_at'          => isset( $row['issued_at'] ) ? (int) $row['issued_at'] : 0,
			'expires_at'         => isset( $row['expires_at'] ) ? (int) $row['expires_at'] : 0,
			'refresh_expires_at' => isset( $row['refresh_expires_at'] ) ? ( null === $row['refresh_expires_at'] ? null : (int) $row['refresh_expires_at'] ) : null,
			'last_used_at'       => isset( $row['last_used_at'] ) ? ( null === $row['last_used_at'] ? null : (int) $row['last_used_at'] ) : null,
			'last_used_ip'       => isset( $row['last_used_ip'] ) ? $row['last_used_ip'] : null,
			'last_used_ua'       => isset( $row['last_used_ua'] ) ? $row['last_used_ua'] : null,
			'revoked_at'         => isset( $row['revoked_at'] ) ? ( null === $row['revoked_at'] ? null : (int) $row['revoked_at'] ) : null,
			'revoked_by'         => isset( $row['revoked_by'] ) ? ( null === $row['revoked_by'] ? null : (int) $row['revoked_by'] ) : null,
			'source'             => isset( $row['source'] ) ? (string) $row['source'] : 'oauth',
			'ip_address'         => isset( $row['ip_address'] ) ? $row['ip_address'] : null,
		);
	}
}
