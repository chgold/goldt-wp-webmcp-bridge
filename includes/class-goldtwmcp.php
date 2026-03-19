<?php
if (!defined('ABSPATH')) {
    exit;
}

class GoldtWebMCP_Plugin {
    
    private $version = '0.3.0';
    
    private $manifest;
    private $tools_endpoint;
    private $modules = [];
    
    public function __construct() {
        $this->load_dependencies();
        $this->init_components();
        $this->register_modules();
    }
    
    private function load_dependencies() {
		if (file_exists(GOLDTWMCP_PATH . 'vendor/autoload.php')) {
			require_once GOLDTWMCP_PATH . 'vendor/autoload.php';
		}

		require_once GOLDTWMCP_PATH . 'includes/core/class-manifest.php';
		require_once GOLDTWMCP_PATH . 'includes/core/class-rate-limiter.php';
		require_once GOLDTWMCP_PATH . 'includes/modules/class-module-base.php';
		require_once GOLDTWMCP_PATH . 'includes/modules/class-core-module.php';
		require_once GOLDTWMCP_PATH . 'includes/modules/class-translation-module.php';
		require_once GOLDTWMCP_PATH . 'includes/api/class-tools-endpoint.php';

		// OAuth 2.0 components
		require_once GOLDTWMCP_PATH . 'includes/oauth/class-database.php';
		require_once GOLDTWMCP_PATH . 'includes/oauth/class-oauth-server.php';
		require_once GOLDTWMCP_PATH . 'includes/oauth/class-scopes.php';
		require_once GOLDTWMCP_PATH . 'includes/oauth/class-authorize-endpoint.php';
		require_once GOLDTWMCP_PATH . 'includes/oauth/class-token-endpoint.php';
		require_once GOLDTWMCP_PATH . 'includes/oauth/class-revoke-endpoint.php';
		require_once GOLDTWMCP_PATH . 'includes/oauth/class-bearer-auth.php';
		require_once GOLDTWMCP_PATH . 'includes/oauth/class-admin-ui.php';
    }
    
    private function init_components() {
        $this->manifest = new \GoldtWebMCP\Core\Manifest();
        $this->tools_endpoint = new \GoldtWebMCP\API\Tools_Endpoint();
    }
    
    private function register_modules() {
        // Register WordPress Core module (Free)
        $core_module = new \GoldtWebMCP\Modules\Core_Module($this->manifest);
        $this->modules['wordpress'] = $core_module;
        $this->tools_endpoint->register_module($core_module);

        // Register Translation module (active only when mymemory provider is selected)
        $translation_module = new \GoldtWebMCP\Modules\Translation_Module($this->manifest);
        $this->modules['translation'] = $translation_module;
        $this->tools_endpoint->register_module($translation_module);
        
        // Allow external plugins (Pro) to register additional modules
        // Pro plugin hooks here via: add_action('goldtwmcp_register_modules', ...)
        do_action('goldtwmcp_register_modules', $this);
    }
    
    /**
     * Register external module (used by Pro plugin)
     * 
     * @param string $key Module key
     * @param object $module Module instance
     */
    public function register_external_module($key, $module) {
        $this->modules[$key] = $module;
        $this->tools_endpoint->register_module($module);
    }
    
    /**
     * Get manifest instance (used by Pro plugin)
     */
    public function get_manifest_instance() {
        return $this->manifest;
    }
    
    /**
     * Get tools endpoint instance (used by Pro plugin)
     */
    public function get_tools_endpoint() {
        return $this->tools_endpoint;
    }
    
    public function run() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('rest_api_init', [$this, 'register_routes']);
        add_action('init', [$this, 'add_rewrite_rules']);
        add_filter('query_vars', [$this, 'register_query_vars']);
        
        // Initialize OAuth components
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
    }
    
    public function add_rewrite_rules() {
        add_rewrite_tag('%goldtwmcp_oauth_authorize%', '([^&]+)');
    }
    
    public function register_query_vars($vars) {
        $vars[] = 'goldtwmcp_oauth_authorize';
        return $vars;
    }
    
    public function add_admin_menu() {
        add_menu_page(
            esc_html__('AI Connect', 'goldt-webmcp-bridge'),
            esc_html__('AI Connect', 'goldt-webmcp-bridge'),
            'manage_options',
            'goldt-webmcp-bridge',
            [$this, 'admin_page'],
            'dashicons-admin-plugins',
            100
        );
        
        add_submenu_page(
            'goldt-webmcp-bridge',
            esc_html__('Settings', 'goldt-webmcp-bridge'),
            esc_html__('Settings', 'goldt-webmcp-bridge'),
            'manage_options',
            'ai-connect-settings',
            [$this, 'settings_page']
        );
    }
    
    public function admin_page() {
        ?>
        <div class="wrap">
            <h1>🚀 <?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <div class="notice notice-success inline">
                <p><strong><?php
                /* translators: %s: version number */
                printf(esc_html__('✅ AI Connect v%s is active and ready!', 'goldt-webmcp-bridge'), esc_html($this->version)); ?></strong></p>
            </div>
            
            <div class="card" style="max-width: 800px;">
                <h2><?php esc_html_e('Environment Status', 'goldt-webmcp-bridge'); ?></h2>
                <table class="widefat striped">
                    <tbody>
                        <tr>
                            <td style="width: 200px;"><strong>WordPress:</strong></td>
                            <td><?php echo esc_html(get_bloginfo('version')); ?></td>
                        </tr>
                        <tr>
                            <td><strong>PHP:</strong></td>
                            <td><?php echo esc_html(PHP_VERSION); ?></td>
                        </tr>
                        <tr>
                            <td><strong>MySQL:</strong></td>
                            <td><?php 
                                global $wpdb;
                                echo $wpdb->db_server_info() ? '✓ Connected' : '✗ Not connected';
                            ?></td>
                        </tr>
                        <tr>
                            <td><strong>WooCommerce:</strong></td>
                            <td><?php 
                                if (class_exists('WooCommerce')) {
                                    $wc_version = defined('WC_VERSION') ? WC_VERSION : 'Unknown';
                                    echo '✓ Active (v' . esc_html($wc_version) . ')';
                                } else {
                                    echo '✗ Not installed';
                                }
                            ?></td>
                        </tr>
                        <tr>
                            <td><strong>Redis:</strong></td>
                            <td><?php echo extension_loaded('redis') ? '✓ Available' : '○ Not installed (optional)'; ?></td>
                        </tr>
                        <tr>
                            <td><strong>Composer Dependencies:</strong></td>
                            <td><?php 
                                if (file_exists(GOLDTWMCP_PATH . 'vendor/autoload.php')) {
                                    echo '<span style="color: green;">✓ Installed</span>';
                                } else {
                                    echo '<span style="color: red;">✗ Missing</span> - ';
                                    $activation_error = get_option('goldtwmcp_activation_error', '');
                                    if ($activation_error) {
                                        echo '<em>' . esc_html($activation_error) . '</em>';
                                    } else {
                                        echo '<em>Run composer install</em>';
                                    }
                                }
                            ?></td>
                        </tr>
                        <tr>
                            <td><strong>exec() Function:</strong></td>
                            <td><?php 
                                $disabled_functions = explode(',', ini_get('disable_functions'));
                                $disabled_functions = array_map('trim', $disabled_functions);
                                if (in_array('exec', $disabled_functions)) {
                                    echo '<span style="color: orange;">✗ Disabled</span>';
                                } else {
                                    echo '✓ Enabled';
                                }
                            ?></td>
                        </tr>
                        <tr>
                            <td><strong>Composer Command:</strong></td>
                            <td><?php 
                                $disabled_functions = explode(',', ini_get('disable_functions'));
                                $disabled_functions = array_map('trim', $disabled_functions);
                                if (!in_array('exec', $disabled_functions)) {
                                    exec('which composer 2>/dev/null', $output, $return_var);
                                    if ($return_var === 0) {
                                        echo '✓ Available in PATH';
                                    } else {
                                        exec('which composer.phar 2>/dev/null', $output, $return_var);
                                        if ($return_var === 0) {
                                            echo '✓ composer.phar found';
                                        } else {
                                            echo '<span style="color: orange;">✗ Not found</span>';
                                        }
                                    }
                                } else {
                                    echo '<span style="color: #999;">N/A (exec disabled)</span>';
                                }
                            ?></td>
                        </tr>
                        <tr>
                            <td><strong>OAuth Tables:</strong></td>
                            <td><?php 
                                if (\GoldtWebMCP\OAuth\Database::tables_exist()) {
                                    echo '<span style="color: green;">✓ Created</span>';
                                } else {
                                    echo '<span style="color: red;">✗ Missing</span> - Deactivate & reactivate plugin';
                                }
                            ?></td>
                        </tr>

                    </tbody>
                </table>
            </div>
            
			<?php if (!file_exists(GOLDTWMCP_PATH . 'vendor/autoload.php')): ?>
				<div class="notice notice-warning">
					<p><strong><?php esc_html_e('Dependencies Missing', 'goldt-webmcp-bridge'); ?></strong></p>
					<p><?php esc_html_e('Run the following command in the plugin directory:', 'goldt-webmcp-bridge'); ?></p>
					<pre style="background: #f5f5f5; padding: 10px; border-radius: 4px;"><code>cd <?php echo esc_html(dirname(GOLDTWMCP_PATH)); ?> && composer install</code></pre>
				</div>
			<?php endif; ?>
        </div>
        <?php
    }
    
    public function register_routes() {
        // Public endpoint - OAuth 2.0 discovery, must be publicly accessible
		register_rest_route('goldt-webmcp-bridge/v1', '/manifest', [
            'methods' => 'GET',
            'callback' => [$this, 'get_manifest'],
            'permission_callback' => '__return_true' // Intentionally public for OAuth discovery
        ]);
        
        // Public endpoint - WebMCP protocol discovery (standard location)
        register_rest_route('.well-known', '/ai-plugin.json', [
            'methods' => 'GET',
            'callback' => [$this, 'get_manifest'],
            'permission_callback' => '__return_true' // Intentionally public per WebMCP spec
        ]);
        
        $this->tools_endpoint->register_routes();
    }
    
    public function get_manifest() {
        $manifest = $this->manifest->generate();
        
        return rest_ensure_response($manifest);
    }
    
    public function settings_page() {
        // Handle user blacklist changes
        if (isset($_POST['goldtwmcp_blacklist_user'])) {
            check_admin_referer('goldtwmcp_blacklist');
            
            $user_id = absint($_POST['user_id'] ?? 0);
            if ($user_id > 0) {
                $blacklisted_users = get_option('goldtwmcp_blacklisted_users', []);
                if (!in_array($user_id, $blacklisted_users)) {
                    $blacklisted_users[] = $user_id;
                    update_option('goldtwmcp_blacklisted_users', $blacklisted_users);
                    echo '<div class="notice notice-success"><p>' . esc_html__('User access revoked successfully.', 'goldt-webmcp-bridge') . '</p></div>';
                }
            }
        }
        
        if (isset($_POST['goldtwmcp_unblacklist_user'])) {
            check_admin_referer('goldtwmcp_blacklist');
            
            $user_id = absint($_POST['user_id'] ?? 0);
            if ($user_id > 0) {
                $blacklisted_users = get_option('goldtwmcp_blacklisted_users', []);
                $key = array_search($user_id, $blacklisted_users);
                if ($key !== false) {
                    unset($blacklisted_users[$key]);
                    update_option('goldtwmcp_blacklisted_users', array_values($blacklisted_users));
                    echo '<div class="notice notice-success"><p>' . esc_html__('User access restored successfully.', 'goldt-webmcp-bridge') . '</p></div>';
                }
            }
        }
        
        // Handle JWT Secret rotation
        if (isset($_POST['goldtwmcp_rotate_jwt_secret'])) {
            check_admin_referer('goldtwmcp_rotate_jwt_secret');
            
            $new_secret = wp_generate_password(64, true, true);
            update_option('goldtwmcp_jwt_secret', $new_secret);
            
            echo '<div class="notice notice-success"><p>' . esc_html__('JWT Secret rotated successfully! All existing tokens have been invalidated.', 'goldt-webmcp-bridge') . '</p></div>';
        }
        
        if (isset($_POST['goldtwmcp_save_settings'])) {
            check_admin_referer('goldtwmcp_settings');
            
            $rate_limit_per_minute = absint($_POST['rate_limit_per_minute'] ?? 50);
            $rate_limit_per_hour = absint($_POST['rate_limit_per_hour'] ?? 1000);
            $delete_on_uninstall = isset($_POST['delete_on_uninstall']) ? 1 : 0;
            $translation_provider_raw = sanitize_text_field(wp_unslash($_POST['translation_provider'] ?? 'ai_self'));
            $translation_provider = in_array($translation_provider_raw, ['ai_self', 'mymemory', 'disabled'], true) ? $translation_provider_raw : 'ai_self';
            
            update_option('goldtwmcp_rate_limit_per_minute', $rate_limit_per_minute);
            update_option('goldtwmcp_rate_limit_per_hour', $rate_limit_per_hour);
            update_option('goldtwmcp_delete_on_uninstall', $delete_on_uninstall);
            update_option('goldtwmcp_translation_provider', $translation_provider);
            
            echo '<div class="notice notice-success"><p>' . esc_html__('Settings saved!', 'goldt-webmcp-bridge') . '</p></div>';
        }
        
        $rate_limit_per_minute = get_option('goldtwmcp_rate_limit_per_minute', 50);
        $rate_limit_per_hour = get_option('goldtwmcp_rate_limit_per_hour', 1000);
        $delete_on_uninstall = get_option('goldtwmcp_delete_on_uninstall', 0);
        $translation_provider = get_option('goldtwmcp_translation_provider', 'ai_self');
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <form method="post">
                <?php wp_nonce_field('goldtwmcp_settings'); ?>
                
                <h2><?php esc_html_e('API Rate Limiting', 'goldt-webmcp-bridge'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e('Rate Limit (per minute)', 'goldt-webmcp-bridge'); ?></th>
                        <td>
                            <input type="number" name="rate_limit_per_minute" value="<?php echo esc_attr($rate_limit_per_minute); ?>" class="regular-text">
                            <p class="description"><?php esc_html_e('Maximum API requests per minute per user', 'goldt-webmcp-bridge'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Rate Limit (per hour)', 'goldt-webmcp-bridge'); ?></th>
                        <td>
                            <input type="number" name="rate_limit_per_hour" value="<?php echo esc_attr($rate_limit_per_hour); ?>" class="regular-text">
                            <p class="description"><?php esc_html_e('Maximum API requests per hour per user', 'goldt-webmcp-bridge'); ?></p>
                        </td>
                    </tr>
                </table>
                
                <h2><?php esc_html_e('Translation', 'goldt-webmcp-bridge'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e('Translation Provider', 'goldt-webmcp-bridge'); ?></th>
                        <td>
                            <fieldset>
                                <label style="display: block; margin-bottom: 8px;">
                                    <input type="radio" name="translation_provider" value="ai_self" <?php checked($translation_provider, 'ai_self'); ?>>
                                    <strong><?php esc_html_e('AI Self-Translate', 'goldt-webmcp-bridge'); ?></strong>
                                    &mdash; <?php esc_html_e('AI agent translates using its own built-in language abilities (no external API, no limits)', 'goldt-webmcp-bridge'); ?>
                                </label>
                                <label style="display: block; margin-bottom: 8px;">
                                    <input type="radio" name="translation_provider" value="mymemory" <?php checked($translation_provider, 'mymemory'); ?>>
                                    <strong><?php esc_html_e('MyMemory API', 'goldt-webmcp-bridge'); ?></strong>
                                    &mdash; <?php esc_html_e('Uses MyMemory free translation API (~5,000 chars/day limit)', 'goldt-webmcp-bridge'); ?>
                                </label>
                                <label style="display: block;">
                                    <input type="radio" name="translation_provider" value="disabled" <?php checked($translation_provider, 'disabled'); ?>>
                                    <strong><?php esc_html_e('Disabled', 'goldt-webmcp-bridge'); ?></strong>
                                    &mdash; <?php esc_html_e('No translation capability exposed to AI agents', 'goldt-webmcp-bridge'); ?>
                                </label>
                            </fieldset>
                        </td>
                    </tr>
                </table>
                
                <h2><?php esc_html_e('Data Management', 'goldt-webmcp-bridge'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e('Uninstall Cleanup', 'goldt-webmcp-bridge'); ?></th>
                        <td>
                            <fieldset>
                                <label>
                                    <input type="checkbox" name="delete_on_uninstall" value="1" <?php checked($delete_on_uninstall, 1); ?>>
                                    <?php esc_html_e('Delete all plugin data when uninstalling', 'goldt-webmcp-bridge'); ?>
                                </label>
                                <p class="description">
                                    <?php esc_html_e('When enabled, all OAuth clients, tokens, and settings will be permanently deleted when you uninstall this plugin. Leave unchecked to preserve data for reinstallation.', 'goldt-webmcp-bridge'); ?>
                                    <br>
                                    <strong><?php esc_html_e('Note:', 'goldt-webmcp-bridge'); ?></strong> <?php esc_html_e('Sensitive security data (JWT secrets, refresh tokens) will always be deleted regardless of this setting.', 'goldt-webmcp-bridge'); ?>
                                </p>
                            </fieldset>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(esc_html__('Save Settings', 'goldt-webmcp-bridge'), 'primary', 'goldtwmcp_save_settings'); ?>
            </form>
            
            <hr style="margin: 40px 0;">
            
            <h2><?php esc_html_e('Security', 'goldt-webmcp-bridge'); ?></h2>
            <div class="card" style="max-width: 800px;">
                <h3><?php esc_html_e('Rotate JWT Secret Key', 'goldt-webmcp-bridge'); ?></h3>
                <p><?php esc_html_e('This will generate a new JWT secret key and <strong>immediately invalidate all existing access tokens</strong>.', 'goldt-webmcp-bridge'); ?></p>
                <p><?php esc_html_e('All users will need to authenticate again to get new tokens.', 'goldt-webmcp-bridge'); ?></p>
                
                <div class="notice notice-warning inline">
                    <p><strong>⚠️ <?php esc_html_e('Warning:', 'goldt-webmcp-bridge'); ?></strong> <?php esc_html_e('This action cannot be undone. All AI agents currently connected will be disconnected.', 'goldt-webmcp-bridge'); ?></p>
                </div>
                
                <form method="post" onsubmit="return confirm('<?php echo esc_js(esc_html__('Are you sure you want to rotate the JWT secret? This will disconnect all connected AI agents and users will need to re-authenticate.', 'goldt-webmcp-bridge')); ?>');">
                    <?php wp_nonce_field('goldtwmcp_rotate_jwt_secret'); ?>
                    <?php submit_button(esc_html__('Rotate JWT Secret', 'goldt-webmcp-bridge'), 'delete', 'goldtwmcp_rotate_jwt_secret', false); ?>
                </form>
            </div>
            
            <div class="card" style="max-width: 800px; margin-top: 20px;">
                <h3><?php esc_html_e('Manage User Access', 'goldt-webmcp-bridge'); ?></h3>
                <p><?php esc_html_e('Block specific users from accessing AI Connect. Blocked users cannot authenticate or use existing tokens.', 'goldt-webmcp-bridge'); ?></p>
                
                <?php
                $blacklisted_users = get_option('goldtwmcp_blacklisted_users', []);
                if (!empty($blacklisted_users)) {
                    echo '<h4>' . esc_html__('Blocked Users', 'goldt-webmcp-bridge') . '</h4>';
                    echo '<table class="widefat striped">';
                    echo '<thead><tr><th>' . esc_html__('User ID', 'goldt-webmcp-bridge') . '</th><th>' . esc_html__('Username', 'goldt-webmcp-bridge') . '</th><th>' . esc_html__('Email', 'goldt-webmcp-bridge') . '</th><th>' . esc_html__('Action', 'goldt-webmcp-bridge') . '</th></tr></thead>';
                    echo '<tbody>';
                    foreach ($blacklisted_users as $user_id) {
                        $user = get_userdata($user_id);
                        if ($user) {
                            echo '<tr>';
                            echo '<td>' . esc_html($user_id) . '</td>';
                            echo '<td>' . esc_html($user->user_login) . '</td>';
                            echo '<td>' . esc_html($user->user_email) . '</td>';
                            echo '<td>';
                            echo '<form method="post" style="display: inline;">';
                            wp_nonce_field('goldtwmcp_blacklist');
                            echo '<input type="hidden" name="user_id" value="' . esc_attr($user_id) . '">';
                            submit_button(esc_html__('Restore Access', 'goldt-webmcp-bridge'), 'small', 'goldtwmcp_unblacklist_user', false);
                            echo '</form>';
                            echo '</td>';
                            echo '</tr>';
                        }
                    }
                    echo '</tbody></table>';
                } else {
                    echo '<p><em>' . esc_html__('No users are currently blocked.', 'goldt-webmcp-bridge') . '</em></p>';
                }
                ?>
                
                <hr style="margin: 20px 0;">
                
                <h4><?php esc_html_e('Block a User', 'goldt-webmcp-bridge'); ?></h4>
                <form method="post">
                    <?php wp_nonce_field('goldtwmcp_blacklist'); ?>
                    <p>
                        <label for="user_id"><?php esc_html_e('User ID:', 'goldt-webmcp-bridge'); ?></label>
                        <input type="number" name="user_id" id="user_id" min="1" required class="regular-text">
                        <span class="description"><?php esc_html_e('Enter the WordPress user ID to block', 'goldt-webmcp-bridge'); ?></span>
                    </p>
                    <?php submit_button(esc_html__('Block User', 'goldt-webmcp-bridge'), 'secondary', 'goldtwmcp_blacklist_user', false); ?>
                </form>
            </div>
        </div>
        <?php
    }
}
