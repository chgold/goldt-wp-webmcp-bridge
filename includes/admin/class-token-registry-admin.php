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
	 * Render the admin page (with inline revoke handler).
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'goldt-webmcp-bridge' ) );
		}

		$notice = '';
		if ( isset( $_POST['goldtwmcp_revoke_registry_id'] ) ) {
			check_admin_referer( 'goldtwmcp_token_registry_revoke' );
			$row_id = absint( wp_unslash( $_POST['goldtwmcp_revoke_registry_id'] ) );
			if ( $row_id > 0 ) {
				$row     = Token_Registry::get( $row_id );
				$revoked = Token_Registry::revoke_by_id( $row_id, get_current_user_id() );
				if ( $revoked && is_array( $row ) ) {
					$this->revoke_full_token_by_prefix( $row['token_prefix'] );
					$notice = __( 'Token revoked successfully.', 'goldt-webmcp-bridge' );
				} else {
					$notice = __( 'Token could not be revoked (already revoked or not found).', 'goldt-webmcp-bridge' );
				}
			}
		}

		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'active';
		if ( ! in_array( $status, array( 'active', 'revoked', 'all' ), true ) ) {
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
	 * Revoke the matching OAuth token row(s) so subsequent auth fails.
	 *
	 * The registry only stores a 16-char prefix, so we update any token row
	 * whose access token starts with that prefix. Token prefixes are unique
	 * in practice (256-bit entropy) so a single match is expected.
	 *
	 * @param string $token_prefix The 16-char prefix.
	 * @return void
	 */
	private function revoke_full_token_by_prefix( $token_prefix ) {
		global $wpdb;

		if ( ! is_string( $token_prefix ) || '' === $token_prefix ) {
			return;
		}

		$like = $wpdb->esc_like( $token_prefix ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Mirror revoke into OAuth tokens table.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}goldtwmcp_oauth_tokens SET revoked_at = %s WHERE token LIKE %s AND revoked_at IS NULL",
				gmdate( 'Y-m-d H:i:s' ),
				$like
			)
		);
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
							'enum'    => array( 'active', 'revoked', 'all' ),
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
	 * DELETE /admin/tokens/{id} — soft-delete a registry row.
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

		if ( null !== $row['revoked_at'] ) {
			return rest_ensure_response(
				array(
					'success' => true,
					'message' => __( 'Token was already revoked.', 'goldt-webmcp-bridge' ),
					'token'   => $this->format_row( $row ),
				)
			);
		}

		$ok = Token_Registry::revoke_by_id( $id, get_current_user_id() );
		if ( $ok ) {
			$this->revoke_full_token_by_prefix( $row['token_prefix'] );
		}

		$refreshed = Token_Registry::get( $id );

		return rest_ensure_response(
			array(
				'success' => (bool) $ok,
				'token'   => null !== $refreshed ? $this->format_row( $refreshed ) : null,
			)
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
			'id'           => isset( $row['id'] ) ? (int) $row['id'] : 0,
			'token_prefix' => isset( $row['token_prefix'] ) ? (string) $row['token_prefix'] : '',
			'user_id'      => isset( $row['user_id'] ) ? (int) $row['user_id'] : 0,
			'user_login'   => $user ? $user->user_login : null,
			'client_id'    => isset( $row['client_id'] ) ? (string) $row['client_id'] : '',
			'scope'        => isset( $row['scope'] ) ? (string) $row['scope'] : '',
			'issued_at'    => isset( $row['issued_at'] ) ? (int) $row['issued_at'] : 0,
			'expires_at'   => isset( $row['expires_at'] ) ? (int) $row['expires_at'] : 0,
			'last_used_at' => isset( $row['last_used_at'] ) ? ( null === $row['last_used_at'] ? null : (int) $row['last_used_at'] ) : null,
			'revoked_at'   => isset( $row['revoked_at'] ) ? ( null === $row['revoked_at'] ? null : (int) $row['revoked_at'] ) : null,
			'revoked_by'   => isset( $row['revoked_by'] ) ? ( null === $row['revoked_by'] ? null : (int) $row['revoked_by'] ) : null,
			'source'       => isset( $row['source'] ) ? (string) $row['source'] : 'oauth',
			'ip_address'   => isset( $row['ip_address'] ) ? $row['ip_address'] : null,
		);
	}
}
