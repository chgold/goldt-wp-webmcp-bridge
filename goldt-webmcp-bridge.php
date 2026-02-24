<?php
/**
 * Plugin Name: GoldT WebMCP Bridge
 * Plugin URI: 
 * Description: Bridge for 8 AI agents (Claude, ChatGPT, Grok, more) via WebMCP with OAuth 2.0
 * Version: 0.2.0
 * Author: chgold
 * Author URI: https://github.com/chgold
 * License: GPL v3
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: goldt-webmcp-bridge
 */

if (!defined('ABSPATH')) {
    exit;
}

define('GOLDTWMCP_VERSION', '0.2.0');
define('GOLDTWMCP_PATH', plugin_dir_path(__FILE__));
define('GOLDTWMCP_URL', plugin_dir_url(__FILE__));

// Load Composer dependencies
if (file_exists(GOLDTWMCP_PATH . 'vendor/autoload.php')) {
	require_once GOLDTWMCP_PATH . 'vendor/autoload.php';
}

register_activation_hook(__FILE__, 'goldtwmcp_activate');
function goldtwmcp_activate() {
    // Install composer dependencies if missing
    if (!file_exists(GOLDTWMCP_PATH . 'vendor/autoload.php')) {
        goldtwmcp_install_dependencies();
    }
    
    // Create OAuth database tables
    require_once GOLDTWMCP_PATH . 'includes/oauth/class-database.php';
    \GoldtWebMCP\OAuth\Database::create_tables();
    
    add_option('goldtwmcp_version', GOLDTWMCP_VERSION);
    add_option('goldtwmcp_installed', time());
    flush_rewrite_rules();
}

function goldtwmcp_install_dependencies() {
    $composer_json = GOLDTWMCP_PATH . 'composer.json';
    
    if (!file_exists($composer_json)) {
        return;
    }
    
    // Check if composer is available
    $composer_cmd = 'composer';
    exec('which composer 2>/dev/null', $output, $return_var);
    
    if ($return_var !== 0) {
        // Try composer.phar
        exec('which composer.phar 2>/dev/null', $output, $return_var);
        if ($return_var === 0) {
            $composer_cmd = 'composer.phar';
        } else {
            return;
        }
    }
    
    // Run composer install
    $old_dir = getcwd();
    chdir(GOLDTWMCP_PATH);
    exec($composer_cmd . ' install --no-dev --optimize-autoloader 2>&1', $output, $return_var);
    chdir($old_dir);
}

register_deactivation_hook(__FILE__, 'goldtwmcp_deactivate');
function goldtwmcp_deactivate() {
    flush_rewrite_rules();
}

add_action('plugins_loaded', 'goldtwmcp_init');
function goldtwmcp_init() {
    // Check if dependencies are loaded
    if (!file_exists(GOLDTWMCP_PATH . 'vendor/autoload.php')) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error"><p>';
            echo esc_html__('GoldT WebMCP Bridge: Missing dependencies. Please run "composer install" in the plugin directory.', 'goldt-webmcp-bridge');
            echo '</p></div>';
        });
        return;
    }
    
    // Run database upgrades if needed
    require_once GOLDTWMCP_PATH . 'includes/oauth/class-database.php';
    \GoldtWebMCP\OAuth\Database::maybe_upgrade();
    
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-warning"><p>';
            echo esc_html__('GoldT WebMCP Bridge: WooCommerce is recommended for full functionality.', 'goldt-webmcp-bridge');
            echo '</p></div>';
        });
    }
    
    require_once GOLDTWMCP_PATH . 'includes/class-goldtwmcp.php';
    $plugin = new GoldtWebMCP_Plugin();
    $plugin->run();
}

add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'goldtwmcp_action_links');
function goldtwmcp_action_links($links) {
    $settings_link = '<a href="admin.php?page=goldtwmcp">' . esc_html__('Settings', 'goldt-webmcp-bridge') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}
