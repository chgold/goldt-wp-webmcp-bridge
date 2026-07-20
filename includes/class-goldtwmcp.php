<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Legacy filename kept for backwards compatibility.
/**
 * Main plugin class file.
 *
 * @package GoldtWebMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main GoldtWebMCP plugin class.
 *
 * @package GoldtWebMCP
 */
class GoldtWebMCP_Plugin {

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	private $version = GOLDTWMCP_VERSION;

	/**
	 * Manifest instance.
	 *
	 * @var \GoldtWebMCP\Core\Manifest
	 */
	private $manifest;

	/**
	 * Tools endpoint instance.
	 *
	 * @var \GoldtWebMCP\API\Tools_Endpoint
	 */
	private $tools_endpoint;

	/**
	 * Registered modules.
	 *
	 * @var array
	 */
	private $modules = array();

	/**
	 * Constructor.
	 *
	 * NOTE: register_modules() is DELIBERATELY not called here. It's deferred
	 * to plugins_loaded priority 9999 (see the init helper below) so that
	 * every third-party extension has a full opportunity to add its
	 * `goldtwmcp_register_modules` callback BEFORE we fire the action.
	 *
	 * History: the constructor used to call register_modules() directly, which
	 * meant do_action('goldtwmcp_register_modules') fired at whatever priority
	 * `goldtwmcp_init()` runs (currently plugins_loaded priority 10). Any
	 * extension that hooked in at a later priority (e.g. goldt-webmcp-woocommerce
	 * at priority 20) would register its callback AFTER the action had already
	 * fired, and its module would silently never load.
	 *
	 * Using priority 9999 gives extensions a huge headroom — they can hook in
	 * anywhere from priority 1 to priority 9998 and be guaranteed to run before
	 * modules are registered. New extensions never need to think about this.
	 */
	public function __construct() {
		$this->load_dependencies();
		$this->init_components();
		add_action( 'plugins_loaded', array( $this, 'register_modules' ), 9999 );
	}

	/**
	 * Load plugin dependencies.
	 *
	 * @return void
	 */
	private function load_dependencies() {
		if ( file_exists( GOLDTWMCP_PATH . 'vendor/autoload.php' ) ) {
			require_once GOLDTWMCP_PATH . 'vendor/autoload.php';
		}

		require_once GOLDTWMCP_PATH . 'includes/core/class-manifest.php';
		require_once GOLDTWMCP_PATH . 'includes/core/class-rate-limiter.php';
		require_once GOLDTWMCP_PATH . 'includes/modules/class-module-base.php';
		require_once GOLDTWMCP_PATH . 'includes/modules/class-core-module.php';
		require_once GOLDTWMCP_PATH . 'includes/modules/class-translation-module.php';
		require_once GOLDTWMCP_PATH . 'includes/api/class-tools-endpoint.php';

		// OAuth 2.0 components.
		require_once GOLDTWMCP_PATH . 'includes/oauth/class-database.php';
		require_once GOLDTWMCP_PATH . 'includes/oauth/class-token-registry.php';
		require_once GOLDTWMCP_PATH . 'includes/oauth/class-oauth-server.php';
		require_once GOLDTWMCP_PATH . 'includes/oauth/class-scopes.php';
		require_once GOLDTWMCP_PATH . 'includes/oauth/class-authorize-endpoint.php';
		require_once GOLDTWMCP_PATH . 'includes/oauth/class-token-endpoint.php';
		require_once GOLDTWMCP_PATH . 'includes/oauth/class-revoke-endpoint.php';
		require_once GOLDTWMCP_PATH . 'includes/oauth/class-bearer-auth.php';
		require_once GOLDTWMCP_PATH . 'includes/oauth/class-admin-ui.php';
		require_once GOLDTWMCP_PATH . 'includes/admin/class-token-registry-admin.php';
		require_once GOLDTWMCP_PATH . 'includes/user/class-my-tokens-page.php';
		require_once GOLDTWMCP_PATH . 'includes/core/class-info-page.php';
	}

	/**
	 * Initialize plugin components.
	 *
	 * @return void
	 */
	private function init_components() {
		$this->manifest       = new \GoldtWebMCP\Core\Manifest();
		$this->tools_endpoint = new \GoldtWebMCP\API\Tools_Endpoint();
	}

	/**
	 * Register plugin modules.
	 *
	 * @return void
	 */
	public function register_modules() {
		// Idempotency guard: WordPress may fire plugins_loaded more than once
		// in unusual scenarios (test doubles, plugin reactivation on shutdown).
		if ( ! empty( $this->modules ) ) {
			return;
		}

		// Register WordPress Core module (Free).
		$core_module                = new \GoldtWebMCP\Modules\Core_Module( $this->manifest );
		$this->modules['wordpress'] = $core_module;
		$this->tools_endpoint->register_module( $core_module );

		// Register Translation module (active only when mymemory provider is selected).
		$translation_module           = new \GoldtWebMCP\Modules\Translation_Module( $this->manifest );
		$this->modules['translation'] = $translation_module;
		$this->tools_endpoint->register_module( $translation_module );

		// Allow external plugins (Pro) to register additional modules.
		// Pro plugin hooks here via: add_action('goldtwmcp_register_modules', ...).
		// This fires at plugins_loaded priority 9999, so extensions can safely
		// register their callback at any priority up to 9998.
		do_action( 'goldtwmcp_register_modules', $this );
	}

	/**
	 * Register external module (used by Pro plugin).
	 *
	 * @param string $key Module key.
	 * @param object $module Module instance.
	 * @return void
	 */
	public function register_external_module( $key, $module ) {
		$this->modules[ $key ] = $module;
		$this->tools_endpoint->register_module( $module );
	}

	/**
	 * Get manifest instance (used by Pro plugin).
	 *
	 * @return \GoldtWebMCP\Core\Manifest
	 */
	public function get_manifest_instance() {
		return $this->manifest;
	}

	/**
	 * Get tools endpoint instance (used by Pro plugin).
	 *
	 * @return \GoldtWebMCP\API\Tools_Endpoint
	 */
	public function get_tools_endpoint() {
		return $this->tools_endpoint;
	}

	/**
	 * Run the plugin.
	 *
	 * @return void
	 */
	public function run() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'init', array( $this, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
		add_action( 'template_redirect', array( $this, 'handle_aiconnect_api' ), 1 );

		// Initialize OAuth components.
		$authorize_endpoint = new \GoldtWebMCP\OAuth\Authorize_Endpoint();
		$authorize_endpoint->init();

		$token_endpoint = new \GoldtWebMCP\OAuth\Token_Endpoint();
		$token_endpoint->init();

		$revoke_endpoint = new \GoldtWebMCP\OAuth\Revoke_Endpoint();
		$revoke_endpoint->init();

		$bearer_auth = new \GoldtWebMCP\OAuth\Bearer_Auth();
		$bearer_auth->init();

		$admin_ui = new \GoldtWebMCP\OAuth\Admin_UI();
		$admin_ui->init();

		$token_registry_admin = new \GoldtWebMCP\Admin\Token_Registry_Admin();
		$token_registry_admin->init();

		$my_tokens_page = new \GoldtWebMCP\User\My_Tokens_Page();
		$my_tokens_page->init();

		$info_page = new \GoldtWebMCP\Core\Info_Page();
		$info_page->init();

		// Register daily cron for token cleanup.
		add_action( 'goldtwmcp_token_cleanup_cron', array( '\GoldtWebMCP\OAuth\Token_Registry', 'run_cleanup' ) );
		if ( ! wp_next_scheduled( 'goldtwmcp_token_cleanup_cron' ) ) {
			wp_schedule_event( strtotime( 'tomorrow 02:00:00' ), 'daily', 'goldtwmcp_token_cleanup_cron' );
		}
	}

	/**
	 * Add rewrite rules for OAuth.
	 *
	 * @return void
	 */
	public function add_rewrite_rules() {
		add_rewrite_tag( '%goldtwmcp_oauth_authorize%', '([^&]+)' );

		// Servio protocol endpoints — /api/aiconnect-*
		// These replicate the XenForo AI Connect URL structure so goldnat.ai's
		// detectWebMCPConnect regex recognizes the manifest, token, and tool URLs.
		add_rewrite_rule( '^api/aiconnect-manifest/?$', 'index.php?aiconnect_endpoint=manifest', 'top' );
		add_rewrite_rule( '^api/aiconnect-tools/?$', 'index.php?aiconnect_endpoint=tools', 'top' );
		add_rewrite_rule( '^api/aiconnect-oauth/?$', 'index.php?aiconnect_endpoint=oauth', 'top' );
	}

	/**
	 * Register query vars.
	 *
	 * @param array $vars Existing query vars.
	 * @return array
	 */
	public function register_query_vars( $vars ) {
		$vars[] = 'goldtwmcp_oauth_authorize';
		$vars[] = 'aiconnect_endpoint';
		return $vars;
	}

	/**
	 * Handle /api/aiconnect-* requests.
	 *
	 * Routes Servio protocol requests to the existing handler logic without
	 * going through the WP REST infrastructure. This makes the URL structure
	 * identical to the XenForo AI Connect addon, so goldnat.ai's
	 * detectWebMCPConnect middleware recognizes the prompts.
	 *
	 * @return void
	 */
	public function handle_aiconnect_api() {
		$endpoint = get_query_var( 'aiconnect_endpoint', '' );
		if ( '' === $endpoint ) {
			return;
		}

		// Set JSON content type for all API responses.
		header( 'Content-Type: application/json; charset=utf-8' );

		// CORS headers for cross-origin tool calls.
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
		header( 'Access-Control-Allow-Headers: Authorization, Content-Type' );

		// Handle preflight.
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'OPTIONS' === $_SERVER['REQUEST_METHOD'] ) {
			status_header( 204 );
			exit;
		}

		switch ( $endpoint ) {
			case 'manifest':
				$this->serve_manifest();
				break;
			case 'tools':
				$this->serve_tools();
				break;
			case 'oauth':
				$this->serve_oauth();
				break;
			default:
				status_header( 404 );
				echo wp_json_encode( array( 'error' => 'Unknown endpoint' ) );
				break;
		}
		exit;
	}

	/**
	 * Serve the Servio manifest (GET /api/aiconnect-manifest).
	 *
	 * @return void
	 */
	private function serve_manifest() {
		status_header( 200 );
		$manifest_data = $this->manifest->generate();
		echo wp_json_encode( $manifest_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
	}

	/**
	 * Serve tool calls (POST /api/aiconnect-tools).
	 *
	 * Accepts either:
	 *   - GET with ?name=toolName&param1=val1... (read-only tools)
	 *   - POST with JSON body {"name":"toolName", ...params} or ?name=toolName + JSON body
	 *
	 * @return void
	 */
	private function serve_tools() {
		// Authenticate via Bearer token.
		$bearer_auth = new \GoldtWebMCP\OAuth\Bearer_Auth();
		$auth_result = $bearer_auth->authenticate_request();
		if ( \is_wp_error( $auth_result ) ) {
			status_header( 401 );
			echo wp_json_encode(
				array(
					'code'    => $auth_result->get_error_code(),
					'message' => $auth_result->get_error_message(),
					'data'    => array( 'status' => 401 ),
				)
			);
			return;
		}

		// Resolve tool name from query string or POST body.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Bearer token auth, not cookie.
		$tool_name = isset( $_GET['name'] ) ? sanitize_text_field( wp_unslash( $_GET['name'] ) ) : '';
		$method    = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';

		// Parse parameters from GET query string or POST JSON body.
		if ( 'POST' === $method ) {
			$raw_body = file_get_contents( 'php://input' );
			$json     = json_decode( $raw_body, true );
			if ( is_array( $json ) ) {
				if ( '' === $tool_name && isset( $json['name'] ) ) {
					$tool_name = sanitize_text_field( $json['name'] );
					unset( $json['name'] );
				}
				// Also extract token from POST body if provided (for non-header auth).
				if ( isset( $json['token'] ) ) {
					unset( $json['token'] );
				}
				$params = $json;
			} else {
				$params = array();
			}
		} else {
			// GET: all query params except name/token are tool arguments.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Bearer token auth.
			$params = array_map( 'sanitize_text_field', wp_unslash( $_GET ) );
			unset( $params['name'], $params['token'], $params['aiconnect_endpoint'] );
		}

		if ( '' === $tool_name ) {
			status_header( 400 );
			echo wp_json_encode( array( 'error' => 'Missing tool name. Use ?name=toolName' ) );
			return;
		}

		// Execute via the tools endpoint.
		// Must set the raw JSON body + Content-Type so that execute_tool()'s
		// $request->get_json_params() returns the tool arguments correctly.
		$request = new \WP_REST_Request( 'POST', '/goldt-webmcp-bridge/v1/tools/' . $tool_name );
		$request->set_body( wp_json_encode( $params ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_param( 'tool', $tool_name );

		$result = $this->tools_endpoint->execute_tool( $request );

		if ( \is_wp_error( $result ) ) {
			$status = $result->get_error_data()['status'] ?? 500;
			status_header( $status );
			echo wp_json_encode(
				array(
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
					'data'    => $result->get_error_data(),
				)
			);
			return;
		}

		$data = ( $result instanceof \WP_REST_Response ) ? $result->get_data() : $result;
		status_header( 200 );
		echo wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Serve OAuth operations (POST /api/aiconnect-oauth).
	 *
	 * Handles token exchange (grant_type=authorization_code / refresh_token)
	 * and token revocation.
	 *
	 * @return void
	 */
	private function serve_oauth() {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';

		if ( 'POST' !== $method ) {
			status_header( 405 );
			echo wp_json_encode( array( 'error' => 'Method not allowed. Use POST.' ) );
			return;
		}

		$raw_body   = file_get_contents( 'php://input' );
		$json_body  = json_decode( $raw_body, true );
		$grant_type = '';

		// Accept both JSON body and form-encoded (standard OAuth).
		if ( is_array( $json_body ) && isset( $json_body['grant_type'] ) ) {
			$grant_type = sanitize_text_field( $json_body['grant_type'] );
			$params     = $json_body;
		} else {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- OAuth endpoint, no nonce.
			$grant_type = isset( $_POST['grant_type'] ) ? sanitize_text_field( wp_unslash( $_POST['grant_type'] ) ) : '';
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- OAuth endpoint.
			$params = array_map( 'sanitize_text_field', wp_unslash( $_POST ) );
		}

		if ( '' === $grant_type ) {
			status_header( 400 );
			echo wp_json_encode( array( 'error' => 'Missing grant_type' ) );
			return;
		}

		// Route to the existing OAuth server.
		$oauth = new \GoldtWebMCP\OAuth\OAuth_Server();

		if ( 'refresh_token' === $grant_type ) {
			$refresh_token = $params['refresh_token'] ?? '';
			$client_id     = $params['client_id'] ?? '';
			$result        = $oauth->exchange_refresh_token( $refresh_token, $client_id );
		} elseif ( 'authorization_code' === $grant_type ) {
			$code          = $params['code'] ?? '';
			$client_id     = $params['client_id'] ?? '';
			$redirect_uri  = $params['redirect_uri'] ?? '';
			$code_verifier = $params['code_verifier'] ?? '';
			$result        = $oauth->exchange_code_for_token( $code, $client_id, $code_verifier, $redirect_uri );
		} else {
			status_header( 400 );
			echo wp_json_encode( array( 'error' => 'Unsupported grant_type: ' . $grant_type ) );
			return;
		}

		if ( \is_wp_error( $result ) ) {
			$status = $result->get_error_data()['status'] ?? 400;
			status_header( $status );
			echo wp_json_encode(
				array(
					'error'             => $result->get_error_code(),
					'error_description' => $result->get_error_message(),
				)
			);
			return;
		}

		status_header( 200 );
		echo wp_json_encode( $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Add admin menu items.
	 *
	 * @return void
	 */
	public function add_admin_menu() {
		add_menu_page(
			esc_html__( 'AI Connect', 'goldt-webmcp-bridge' ),
			esc_html__( 'AI Connect', 'goldt-webmcp-bridge' ),
			'manage_options',
			'goldt-webmcp-bridge',
			array( $this, 'admin_page' ),
			'dashicons-admin-plugins',
			100
		);

		add_submenu_page(
			'goldt-webmcp-bridge',
			esc_html__( 'Settings', 'goldt-webmcp-bridge' ),
			esc_html__( 'Settings', 'goldt-webmcp-bridge' ),
			'manage_options',
			'ai-connect-settings',
			array( $this, 'settings_page' )
		);
	}

	/**
	 * Render the admin page.
	 *
	 * @return void
	 */
	public function admin_page() {
		?>
		<div class="wrap">
			<h1>🚀 <?php echo esc_html( get_admin_page_title() ); ?></h1>
			
			<div class="notice notice-success inline">
				<p><strong>
				<?php
				/* translators: %s: version number */
				printf( esc_html__( '✅ AI Connect v%s is active and ready!', 'goldt-webmcp-bridge' ), esc_html( $this->version ) );
				?>
				</strong></p>
			</div>
			
			<div class="card" style="max-width: 800px;">
				<h2><?php esc_html_e( 'Environment Status', 'goldt-webmcp-bridge' ); ?></h2>
				<table class="widefat striped">
					<tbody>
						<tr>
							<td style="width: 200px;"><strong>WordPress:</strong></td>
							<td><?php echo esc_html( get_bloginfo( 'version' ) ); ?></td>
						</tr>
						<tr>
							<td><strong>PHP:</strong></td>
							<td><?php echo esc_html( PHP_VERSION ); ?></td>
						</tr>
						<tr>
							<td><strong>MySQL:</strong></td>
							<td>
							<?php
								global $wpdb;
								echo esc_html( $wpdb->db_server_info() ? '✓ Connected' : '✗ Not connected' );
							?>
							</td>
						</tr>

						<tr>
							<td><strong>Redis:</strong></td>
							<td><?php echo esc_html( extension_loaded( 'redis' ) ? '✓ Available' : '○ Not installed (optional)' ); ?></td>
						</tr>
						<tr>
							<td><strong>Composer Dependencies:</strong></td>
							<td>
							<?php
							if ( file_exists( GOLDTWMCP_PATH . 'vendor/autoload.php' ) ) {
								echo '<span style="color: green;">✓ Installed</span>';
							} else {
								echo '<span style="color: red;">✗ Missing</span> - ';
								$activation_error = get_option( 'goldtwmcp_activation_error', '' );
								if ( $activation_error ) {
									echo '<em>' . esc_html( $activation_error ) . '</em>';
								} else {
									echo '<em>Run composer install</em>';
								}
							}
							?>
							</td>
						</tr>

						<tr>
							<td><strong>OAuth Tables:</strong></td>
							<td>
							<?php
							if ( \GoldtWebMCP\OAuth\Database::tables_exist() ) {
								echo '<span style="color: green;">✓ Created</span>';
							} else {
								echo '<span style="color: red;">✗ Missing</span> - Deactivate & reactivate plugin';
							}
							?>
							</td>
						</tr>

					</tbody>
				</table>
			</div>
			
			<?php if ( ! file_exists( GOLDTWMCP_PATH . 'vendor/autoload.php' ) ) : ?>
				<div class="notice notice-warning">
					<p><strong><?php esc_html_e( 'Dependencies Missing', 'goldt-webmcp-bridge' ); ?></strong></p>
					<p><?php esc_html_e( 'Run the following command in the plugin directory:', 'goldt-webmcp-bridge' ); ?></p>
					<pre style="background: #f5f5f5; padding: 10px; border-radius: 4px;"><code>cd <?php echo esc_html( dirname( GOLDTWMCP_PATH ) ); ?> && composer install</code></pre>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Register REST API routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		// Public endpoint - OAuth 2.0 discovery, must be publicly accessible.
		register_rest_route(
			'goldt-webmcp-bridge/v1',
			'/manifest',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_manifest' ),
				'permission_callback' => '__return_true', // Intentionally public for OAuth discovery.
			)
		);

		// Public endpoint - WebMCP protocol discovery (standard location).
		register_rest_route(
			'.well-known',
			'/ai-plugin.json',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_manifest' ),
				'permission_callback' => '__return_true', // Intentionally public per WebMCP spec.
			)
		);

		$this->tools_endpoint->register_routes();
	}

	/**
	 * Get plugin manifest.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_manifest() {
		$manifest = $this->manifest->generate();

		return rest_ensure_response( $manifest );
	}

	/**
	 * Render settings page.
	 *
	 * @return void
	 */
	public function settings_page() {
		// Handle user blacklist changes.
		if ( isset( $_POST['goldtwmcp_blacklist_user'] ) ) {
			check_admin_referer( 'goldtwmcp_blacklist' );

			$user_id = absint( $_POST['user_id'] ?? 0 );
			if ( $user_id > 0 ) {
				$blacklisted_users = get_option( 'goldtwmcp_blacklisted_users', array() );
				if ( ! in_array( $user_id, $blacklisted_users, true ) ) {
					$blacklisted_users[] = $user_id;
					update_option( 'goldtwmcp_blacklisted_users', $blacklisted_users );
					echo '<div class="notice notice-success"><p>' . esc_html__( 'User access revoked successfully.', 'goldt-webmcp-bridge' ) . '</p></div>';
				}
			}
		}

		if ( isset( $_POST['goldtwmcp_unblacklist_user'] ) ) {
			check_admin_referer( 'goldtwmcp_blacklist' );

			$user_id = absint( $_POST['user_id'] ?? 0 );
			if ( $user_id > 0 ) {
				$blacklisted_users = get_option( 'goldtwmcp_blacklisted_users', array() );
				$key               = array_search( $user_id, $blacklisted_users, true );
				if ( false !== $key ) {
					unset( $blacklisted_users[ $key ] );
					update_option( 'goldtwmcp_blacklisted_users', array_values( $blacklisted_users ) );
					echo '<div class="notice notice-success"><p>' . esc_html__( 'User access restored successfully.', 'goldt-webmcp-bridge' ) . '</p></div>';
				}
			}
		}

		// Handle Revoke All Tokens.
		if ( isset( $_POST['goldtwmcp_revoke_all_tokens'] ) ) {
			check_admin_referer( 'goldtwmcp_revoke_all_tokens' );

			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk token revocation via admin action
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->prefix}goldtwmcp_oauth_tokens SET revoked_at = %s WHERE revoked_at IS NULL",
					gmdate( 'Y-m-d H:i:s' )
				)
			);

			echo '<div class="notice notice-success"><p>' . esc_html__( 'All active tokens have been revoked. Connected AI agents will need to re-authorize.', 'goldt-webmcp-bridge' ) . '</p></div>';
		}

		if ( isset( $_POST['goldtwmcp_save_settings'] ) ) {
			check_admin_referer( 'goldtwmcp_settings' );

			$rate_limit_per_minute    = absint( $_POST['rate_limit_per_minute'] ?? 50 );
			$rate_limit_per_hour      = absint( $_POST['rate_limit_per_hour'] ?? 1000 );
			$delete_on_uninstall      = isset( $_POST['delete_on_uninstall'] ) ? 1 : 0;
			$translation_provider_raw = sanitize_text_field( wp_unslash( $_POST['translation_provider'] ?? 'ai_self' ) );
			$translation_provider     = in_array( $translation_provider_raw, array( 'ai_self', 'mymemory', 'disabled' ), true ) ? $translation_provider_raw : 'ai_self';

			update_option( 'goldtwmcp_rate_limit_per_minute', $rate_limit_per_minute );
			update_option( 'goldtwmcp_rate_limit_per_hour', $rate_limit_per_hour );
			update_option( 'goldtwmcp_delete_on_uninstall', $delete_on_uninstall );
			update_option( 'goldtwmcp_translation_provider', $translation_provider );

			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved!', 'goldt-webmcp-bridge' ) . '</p></div>';
		}

		$rate_limit_per_minute = get_option( 'goldtwmcp_rate_limit_per_minute', 50 );
		$rate_limit_per_hour   = get_option( 'goldtwmcp_rate_limit_per_hour', 1000 );
		$delete_on_uninstall   = get_option( 'goldtwmcp_delete_on_uninstall', 0 );
		$translation_provider  = get_option( 'goldtwmcp_translation_provider', 'ai_self' );
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			
			<form method="post">
				<?php wp_nonce_field( 'goldtwmcp_settings' ); ?>
				
				<h2><?php esc_html_e( 'API Rate Limiting', 'goldt-webmcp-bridge' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'Rate Limit (per minute)', 'goldt-webmcp-bridge' ); ?></th>
						<td>
							<input type="number" name="rate_limit_per_minute" value="<?php echo esc_attr( $rate_limit_per_minute ); ?>" class="regular-text">
							<p class="description"><?php esc_html_e( 'Maximum API requests per minute per user', 'goldt-webmcp-bridge' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Rate Limit (per hour)', 'goldt-webmcp-bridge' ); ?></th>
						<td>
							<input type="number" name="rate_limit_per_hour" value="<?php echo esc_attr( $rate_limit_per_hour ); ?>" class="regular-text">
							<p class="description"><?php esc_html_e( 'Maximum API requests per hour per user', 'goldt-webmcp-bridge' ); ?></p>
						</td>
					</tr>
				</table>
				
				<h2><?php esc_html_e( 'Translation', 'goldt-webmcp-bridge' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'Translation Provider', 'goldt-webmcp-bridge' ); ?></th>
						<td>
							<fieldset>
								<label style="display: block; margin-bottom: 8px;">
									<input type="radio" name="translation_provider" value="ai_self" <?php checked( $translation_provider, 'ai_self' ); ?>>
									<strong><?php esc_html_e( 'AI Self-Translate', 'goldt-webmcp-bridge' ); ?></strong>
									&mdash; <?php esc_html_e( 'AI agent translates using its own built-in language abilities (no external API, no limits)', 'goldt-webmcp-bridge' ); ?>
								</label>
								<label style="display: block; margin-bottom: 8px;">
									<input type="radio" name="translation_provider" value="mymemory" <?php checked( $translation_provider, 'mymemory' ); ?>>
									<strong><?php esc_html_e( 'MyMemory API', 'goldt-webmcp-bridge' ); ?></strong>
									&mdash; <?php esc_html_e( 'Uses MyMemory free translation API (~5,000 chars/day limit)', 'goldt-webmcp-bridge' ); ?>
								</label>
								<label style="display: block;">
									<input type="radio" name="translation_provider" value="disabled" <?php checked( $translation_provider, 'disabled' ); ?>>
									<strong><?php esc_html_e( 'Disabled', 'goldt-webmcp-bridge' ); ?></strong>
									&mdash; <?php esc_html_e( 'No translation capability exposed to AI agents', 'goldt-webmcp-bridge' ); ?>
								</label>
							</fieldset>
						</td>
					</tr>
				</table>
				
				<h2><?php esc_html_e( 'Data Management', 'goldt-webmcp-bridge' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'Uninstall Cleanup', 'goldt-webmcp-bridge' ); ?></th>
						<td>
							<fieldset>
								<label>
									<input type="checkbox" name="delete_on_uninstall" value="1" <?php checked( $delete_on_uninstall, 1 ); ?>>
									<?php esc_html_e( 'Delete all plugin data when uninstalling', 'goldt-webmcp-bridge' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( 'When enabled, all OAuth clients, tokens, and settings will be permanently deleted when you uninstall this plugin. Leave unchecked to preserve data for reinstallation.', 'goldt-webmcp-bridge' ); ?>
									<br>
									<strong><?php esc_html_e( 'Note:', 'goldt-webmcp-bridge' ); ?></strong> <?php esc_html_e( 'Sensitive security data (OAuth tokens, refresh tokens) will always be deleted regardless of this setting.', 'goldt-webmcp-bridge' ); ?>
								</p>
							</fieldset>
						</td>
					</tr>
				</table>
				
				<?php submit_button( esc_html__( 'Save Settings', 'goldt-webmcp-bridge' ), 'primary', 'goldtwmcp_save_settings' ); ?>
			</form>
			
			<hr style="margin: 40px 0;">
			
			<h2><?php esc_html_e( 'Security', 'goldt-webmcp-bridge' ); ?></h2>
			<div class="card" style="max-width: 800px;">
				<h3><?php esc_html_e( 'Revoke All Active Tokens', 'goldt-webmcp-bridge' ); ?></h3>
				<p><?php esc_html_e( 'This will immediately revoke all active access tokens and refresh tokens.', 'goldt-webmcp-bridge' ); ?></p>
				<p><?php esc_html_e( 'All AI agents currently connected will be disconnected. Users will need to authorize again to get new tokens.', 'goldt-webmcp-bridge' ); ?></p>

				<div class="notice notice-warning inline">
					<p><strong>⚠️ <?php esc_html_e( 'Warning:', 'goldt-webmcp-bridge' ); ?></strong> <?php esc_html_e( 'This action cannot be undone.', 'goldt-webmcp-bridge' ); ?></p>
				</div>

				<form method="post" onsubmit="return confirm('<?php echo esc_js( esc_html__( 'Are you sure you want to revoke all active tokens? All connected AI agents will be disconnected.', 'goldt-webmcp-bridge' ) ); ?>');">
					<?php wp_nonce_field( 'goldtwmcp_revoke_all_tokens' ); ?>
					<?php submit_button( esc_html__( 'Revoke All Tokens', 'goldt-webmcp-bridge' ), 'delete', 'goldtwmcp_revoke_all_tokens', false ); ?>
				</form>
			</div>
			
			<div class="card" style="max-width: 800px; margin-top: 20px;">
				<h3><?php esc_html_e( 'Manage User Access', 'goldt-webmcp-bridge' ); ?></h3>
				<p><?php esc_html_e( 'Block specific users from accessing AI Connect. Blocked users cannot authenticate or use existing tokens.', 'goldt-webmcp-bridge' ); ?></p>
				
				<?php
				$blacklisted_users = get_option( 'goldtwmcp_blacklisted_users', array() );
				if ( ! empty( $blacklisted_users ) ) {
					echo '<h4>' . esc_html__( 'Blocked Users', 'goldt-webmcp-bridge' ) . '</h4>';
					echo '<table class="widefat striped">';
					echo '<thead><tr><th>' . esc_html__( 'User ID', 'goldt-webmcp-bridge' ) . '</th><th>' . esc_html__( 'Username', 'goldt-webmcp-bridge' ) . '</th><th>' . esc_html__( 'Email', 'goldt-webmcp-bridge' ) . '</th><th>' . esc_html__( 'Action', 'goldt-webmcp-bridge' ) . '</th></tr></thead>';
					echo '<tbody>';
					foreach ( $blacklisted_users as $user_id ) {
						$user = get_userdata( $user_id );
						if ( $user ) {
							echo '<tr>';
							echo '<td>' . esc_html( $user_id ) . '</td>';
							echo '<td>' . esc_html( $user->user_login ) . '</td>';
							echo '<td>' . esc_html( $user->user_email ) . '</td>';
							echo '<td>';
							echo '<form method="post" style="display: inline;">';
							wp_nonce_field( 'goldtwmcp_blacklist' );
							echo '<input type="hidden" name="user_id" value="' . esc_attr( $user_id ) . '">';
							submit_button( esc_html__( 'Restore Access', 'goldt-webmcp-bridge' ), 'small', 'goldtwmcp_unblacklist_user', false );
							echo '</form>';
							echo '</td>';
							echo '</tr>';
						}
					}
					echo '</tbody></table>';
				} else {
					echo '<p><em>' . esc_html__( 'No users are currently blocked.', 'goldt-webmcp-bridge' ) . '</em></p>';
				}
				?>
				
				<hr style="margin: 20px 0;">
				
				<h4><?php esc_html_e( 'Block a User', 'goldt-webmcp-bridge' ); ?></h4>
				<form method="post">
					<?php wp_nonce_field( 'goldtwmcp_blacklist' ); ?>
					<p>
						<label for="user_id"><?php esc_html_e( 'User ID:', 'goldt-webmcp-bridge' ); ?></label>
						<input type="number" name="user_id" id="user_id" min="1" required class="regular-text">
						<span class="description"><?php esc_html_e( 'Enter the WordPress user ID to block', 'goldt-webmcp-bridge' ); ?></span>
					</p>
					<?php submit_button( esc_html__( 'Block User', 'goldt-webmcp-bridge' ), 'secondary', 'goldtwmcp_blacklist_user', false ); ?>
				</form>
			</div>
		</div>
		<?php
	}
}
