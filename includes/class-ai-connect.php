<?php
if (!defined('ABSPATH')) {
    exit;
}

class AI_Connect {
    
    private $version = '0.1.0';
    
    private $manifest;
    private $tools_endpoint;
    private $modules = [];
    
    public function __construct() {
        $this->load_dependencies();
        $this->init_components();
        $this->register_modules();
    }
    
    private function load_dependencies() {
        if (file_exists(AI_CONNECT_PATH . 'vendor/autoload.php')) {
            require_once AI_CONNECT_PATH . 'vendor/autoload.php';
        }
        
        require_once AI_CONNECT_PATH . 'includes/core/class-manifest.php';
        require_once AI_CONNECT_PATH . 'includes/core/class-auth.php';
        require_once AI_CONNECT_PATH . 'includes/core/class-rate-limiter.php';
        require_once AI_CONNECT_PATH . 'includes/modules/class-module-base.php';
        require_once AI_CONNECT_PATH . 'includes/modules/class-core-module.php';
        require_once AI_CONNECT_PATH . 'includes/api/class-tools-endpoint.php';
    }
    
    private function init_components() {
        $this->manifest = new \AIConnect\Core\Manifest();
        $this->tools_endpoint = new \AIConnect\API\Tools_Endpoint();
    }
    
    private function register_modules() {
        // Register WordPress Core module (Free)
        $core_module = new \AIConnect\Modules\Core_Module($this->manifest);
        $this->modules['wordpress'] = $core_module;
        $this->tools_endpoint->register_module($core_module);
        
        // Allow external plugins (Pro) to register additional modules
        // Pro plugin hooks here via: add_action('ai_connect_register_modules', ...)
        do_action('ai_connect_register_modules', $this);
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
    }
    
    public function add_admin_menu() {
        add_menu_page(
            esc_html__('AI Connect', 'ai-connect'),
            esc_html__('AI Connect', 'ai-connect'),
            'manage_options',
            'ai-connect',
            [$this, 'admin_page'],
            'dashicons-admin-plugins',
            100
        );
        
        add_submenu_page(
            'ai-connect',
            esc_html__('Settings', 'ai-connect'),
            esc_html__('Settings', 'ai-connect'),
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
                printf(esc_html__('✅ AI Connect v%s is active and ready!', 'ai-connect'), esc_html($this->version)); ?></strong></p>
            </div>
            
            <div class="card" style="max-width: 800px;">
                <h2><?php esc_html_e('Environment Status', 'ai-connect'); ?></h2>
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
                            <td><strong>Composer:</strong></td>
                            <td><?php echo file_exists(AI_CONNECT_PATH . 'vendor') ? '✓ Installed' : '○ Run composer install'; ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <?php if (!file_exists(AI_CONNECT_PATH . 'vendor/autoload.php')): ?>
            <div class="card" style="max-width: 800px; margin-top: 20px;">
                <h2><?php esc_html_e('⚠️ Action Required', 'ai-connect'); ?></h2>
                <p><?php esc_html_e('Composer dependencies are not installed. Please run:', 'ai-connect'); ?></p>
                <pre style="background: #f5f5f5; padding: 10px; border-radius: 4px;"><code>cd <?php echo esc_html(dirname(AI_CONNECT_PATH)); ?> && composer install</code></pre>
            </div>
            <?php else: ?>
            <div class="card" style="max-width: 800px; margin-top: 20px;">
                <h2><?php esc_html_e('🎯 Quick Start', 'ai-connect'); ?></h2>
                <ol>
                    <li><strong><?php esc_html_e('Test the API', 'ai-connect'); ?></strong> - <a href="<?php echo esc_url(rest_url('ai-connect/v1/manifest')); ?>" target="_blank"><?php esc_html_e('View Manifest', 'ai-connect'); ?></a></li>
                    <li><strong><?php esc_html_e('Authentication', 'ai-connect'); ?></strong> - <?php esc_html_e('Use WordPress username & password to login via', 'ai-connect'); ?> <code>/ai-connect/v1/auth/login</code></li>
                    <li><strong><?php esc_html_e('Available Tools', 'ai-connect'); ?></strong> - <?php esc_html_e('5 WordPress core tools (searchPosts, getPost, searchPages, getPage, getCurrentUser)', 'ai-connect'); ?></li>
                    <li><strong><?php esc_html_e('Documentation', 'ai-connect'); ?></strong> - <a href="https://github.com/chgold/ai-connect#readme" target="_blank"><?php esc_html_e('GitHub README', 'ai-connect'); ?></a></li>
                </ol>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    public function register_routes() {
        register_rest_route('ai-connect/v1', '/status', [
            'methods' => 'GET',
            'callback' => [$this, 'get_status'],
            'permission_callback' => '__return_true'
        ]);
        
        register_rest_route('ai-connect/v1', '/manifest', [
            'methods' => 'GET',
            'callback' => [$this, 'get_manifest'],
            'permission_callback' => '__return_true'
        ]);
        
        register_rest_route('.well-known', '/ai-plugin.json', [
            'methods' => 'GET',
            'callback' => [$this, 'get_manifest'],
            'permission_callback' => '__return_true'
        ]);
        
        $this->tools_endpoint->register_routes();
    }
    
    public function get_manifest() {
        $manifest = $this->manifest->generate();
        
        return rest_ensure_response($manifest);
    }
    
    public function get_status() {
        return rest_ensure_response([
            'status' => 'ok',
            'version' => $this->version,
            'wordpress' => get_bloginfo('version'),
            'php' => PHP_VERSION
        ]);
    }
    
    public function settings_page() {
        // Handle user blacklist changes
        if (isset($_POST['ai_connect_blacklist_user'])) {
            check_admin_referer('ai_connect_blacklist');
            
            $user_id = absint($_POST['user_id'] ?? 0);
            if ($user_id > 0) {
                $blacklisted_users = get_option('ai_connect_blacklisted_users', []);
                if (!in_array($user_id, $blacklisted_users)) {
                    $blacklisted_users[] = $user_id;
                    update_option('ai_connect_blacklisted_users', $blacklisted_users);
                    echo '<div class="notice notice-success"><p>' . esc_html__('User access revoked successfully.', 'ai-connect') . '</p></div>';
                }
            }
        }
        
        if (isset($_POST['ai_connect_unblacklist_user'])) {
            check_admin_referer('ai_connect_blacklist');
            
            $user_id = absint($_POST['user_id'] ?? 0);
            if ($user_id > 0) {
                $blacklisted_users = get_option('ai_connect_blacklisted_users', []);
                $key = array_search($user_id, $blacklisted_users);
                if ($key !== false) {
                    unset($blacklisted_users[$key]);
                    update_option('ai_connect_blacklisted_users', array_values($blacklisted_users));
                    echo '<div class="notice notice-success"><p>' . esc_html__('User access restored successfully.', 'ai-connect') . '</p></div>';
                }
            }
        }
        
        // Handle JWT Secret rotation
        if (isset($_POST['ai_connect_rotate_jwt_secret'])) {
            check_admin_referer('ai_connect_rotate_jwt_secret');
            
            $new_secret = wp_generate_password(64, true, true);
            update_option('ai_connect_jwt_secret', $new_secret);
            
            echo '<div class="notice notice-success"><p>' . esc_html__('JWT Secret rotated successfully! All existing tokens have been invalidated.', 'ai-connect') . '</p></div>';
        }
        
        if (isset($_POST['ai_connect_save_settings'])) {
            check_admin_referer('ai_connect_settings');
            
            $rate_limit_per_minute = absint($_POST['rate_limit_per_minute'] ?? 50);
            $rate_limit_per_hour = absint($_POST['rate_limit_per_hour'] ?? 1000);
            $delete_on_uninstall = isset($_POST['delete_on_uninstall']) ? 1 : 0;
            
            update_option('ai_connect_rate_limit_per_minute', $rate_limit_per_minute);
            update_option('ai_connect_rate_limit_per_hour', $rate_limit_per_hour);
            update_option('ai_connect_delete_on_uninstall', $delete_on_uninstall);
            
            echo '<div class="notice notice-success"><p>' . esc_html__('Settings saved!', 'ai-connect') . '</p></div>';
        }
        
        $rate_limit_per_minute = get_option('ai_connect_rate_limit_per_minute', 50);
        $rate_limit_per_hour = get_option('ai_connect_rate_limit_per_hour', 1000);
        $delete_on_uninstall = get_option('ai_connect_delete_on_uninstall', 0);
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <form method="post">
                <?php wp_nonce_field('ai_connect_settings'); ?>
                
                <h2><?php esc_html_e('API Rate Limiting', 'ai-connect'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e('Rate Limit (per minute)', 'ai-connect'); ?></th>
                        <td>
                            <input type="number" name="rate_limit_per_minute" value="<?php echo esc_attr($rate_limit_per_minute); ?>" class="regular-text">
                            <p class="description"><?php esc_html_e('Maximum API requests per minute per user', 'ai-connect'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Rate Limit (per hour)', 'ai-connect'); ?></th>
                        <td>
                            <input type="number" name="rate_limit_per_hour" value="<?php echo esc_attr($rate_limit_per_hour); ?>" class="regular-text">
                            <p class="description"><?php esc_html_e('Maximum API requests per hour per user', 'ai-connect'); ?></p>
                        </td>
                    </tr>
                </table>
                
                <h2><?php esc_html_e('Data Management', 'ai-connect'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e('Uninstall Cleanup', 'ai-connect'); ?></th>
                        <td>
                            <fieldset>
                                <label>
                                    <input type="checkbox" name="delete_on_uninstall" value="1" <?php checked($delete_on_uninstall, 1); ?>>
                                    <?php esc_html_e('Delete all plugin data when uninstalling', 'ai-connect'); ?>
                                </label>
                                <p class="description">
                                    <?php esc_html_e('When enabled, all OAuth clients, tokens, and settings will be permanently deleted when you uninstall this plugin. Leave unchecked to preserve data for reinstallation.', 'ai-connect'); ?>
                                    <br>
                                    <strong><?php esc_html_e('Note:', 'ai-connect'); ?></strong> <?php esc_html_e('Sensitive security data (JWT secrets, refresh tokens) will always be deleted regardless of this setting.', 'ai-connect'); ?>
                                </p>
                            </fieldset>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(esc_html__('Save Settings', 'ai-connect'), 'primary', 'ai_connect_save_settings'); ?>
            </form>
            
            <hr style="margin: 40px 0;">
            
            <h2><?php esc_html_e('Security', 'ai-connect'); ?></h2>
            <div class="card" style="max-width: 800px;">
                <h3><?php esc_html_e('Rotate JWT Secret Key', 'ai-connect'); ?></h3>
                <p><?php esc_html_e('This will generate a new JWT secret key and <strong>immediately invalidate all existing access tokens</strong>.', 'ai-connect'); ?></p>
                <p><?php esc_html_e('All users will need to authenticate again to get new tokens.', 'ai-connect'); ?></p>
                
                <div class="notice notice-warning inline">
                    <p><strong>⚠️ <?php esc_html_e('Warning:', 'ai-connect'); ?></strong> <?php esc_html_e('This action cannot be undone. All AI agents currently connected will be disconnected.', 'ai-connect'); ?></p>
                </div>
                
                <form method="post" onsubmit="return confirm('<?php echo esc_js(esc_html__('Are you sure you want to rotate the JWT secret? This will disconnect all connected AI agents and users will need to re-authenticate.', 'ai-connect')); ?>');">
                    <?php wp_nonce_field('ai_connect_rotate_jwt_secret'); ?>
                    <?php submit_button(esc_html__('Rotate JWT Secret', 'ai-connect'), 'delete', 'ai_connect_rotate_jwt_secret', false); ?>
                </form>
            </div>
            
            <div class="card" style="max-width: 800px; margin-top: 20px;">
                <h3><?php esc_html_e('Manage User Access', 'ai-connect'); ?></h3>
                <p><?php esc_html_e('Block specific users from accessing AI Connect. Blocked users cannot authenticate or use existing tokens.', 'ai-connect'); ?></p>
                
                <?php
                $blacklisted_users = get_option('ai_connect_blacklisted_users', []);
                if (!empty($blacklisted_users)) {
                    echo '<h4>' . esc_html__('Blocked Users', 'ai-connect') . '</h4>';
                    echo '<table class="widefat striped">';
                    echo '<thead><tr><th>' . esc_html__('User ID', 'ai-connect') . '</th><th>' . esc_html__('Username', 'ai-connect') . '</th><th>' . esc_html__('Email', 'ai-connect') . '</th><th>' . esc_html__('Action', 'ai-connect') . '</th></tr></thead>';
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
                            wp_nonce_field('ai_connect_blacklist');
                            echo '<input type="hidden" name="user_id" value="' . esc_attr($user_id) . '">';
                            submit_button(esc_html__('Restore Access', 'ai-connect'), 'small', 'ai_connect_unblacklist_user', false);
                            echo '</form>';
                            echo '</td>';
                            echo '</tr>';
                        }
                    }
                    echo '</tbody></table>';
                } else {
                    echo '<p><em>' . esc_html__('No users are currently blocked.', 'ai-connect') . '</em></p>';
                }
                ?>
                
                <hr style="margin: 20px 0;">
                
                <h4><?php esc_html_e('Block a User', 'ai-connect'); ?></h4>
                <form method="post">
                    <?php wp_nonce_field('ai_connect_blacklist'); ?>
                    <p>
                        <label for="user_id"><?php esc_html_e('User ID:', 'ai-connect'); ?></label>
                        <input type="number" name="user_id" id="user_id" min="1" required class="regular-text">
                        <span class="description"><?php esc_html_e('Enter the WordPress user ID to block', 'ai-connect'); ?></span>
                    </p>
                    <?php submit_button(esc_html__('Block User', 'ai-connect'), 'secondary', 'ai_connect_blacklist_user', false); ?>
                </form>
            </div>
        </div>
        <?php
    }
}
