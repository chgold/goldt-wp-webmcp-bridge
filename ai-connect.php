<?php
/**
 * Plugin Name: AI Connect
 * Plugin URI: https://github.com/chgold/ai-connect
 * Description: Bridge WordPress & AI Agents with WebMCP Protocol
 * Version: 0.1.2
 * Author: chgold
 * Author URI: https://github.com/chgold
 * License: GPL v3
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: ai-connect
 */

if (!defined('ABSPATH')) {
    exit;
}

define('AI_CONNECT_VERSION', '0.1.2');
define('AI_CONNECT_PATH', plugin_dir_path(__FILE__));
define('AI_CONNECT_URL', plugin_dir_url(__FILE__));

// Load Composer dependencies
if (file_exists(AI_CONNECT_PATH . 'vendor/autoload.php')) {
    require_once AI_CONNECT_PATH . 'vendor/autoload.php';
}

register_activation_hook(__FILE__, 'ai_connect_activate');
function ai_connect_activate() {
    // Install composer dependencies if missing
    if (!file_exists(AI_CONNECT_PATH . 'vendor/autoload.php')) {
        ai_connect_install_dependencies();
    }
    
    // Create OAuth database tables
    require_once AI_CONNECT_PATH . 'includes/oauth/class-database.php';
    \AIConnect\OAuth\Database::create_tables();
    
    add_option('ai_connect_version', AI_CONNECT_VERSION);
    add_option('ai_connect_installed', time());
    flush_rewrite_rules();
}

function ai_connect_install_dependencies() {
    $composer_json = AI_CONNECT_PATH . 'composer.json';
    
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
    chdir(AI_CONNECT_PATH);
    exec($composer_cmd . ' install --no-dev --optimize-autoloader 2>&1', $output, $return_var);
    chdir($old_dir);
}

register_deactivation_hook(__FILE__, 'ai_connect_deactivate');
function ai_connect_deactivate() {
    flush_rewrite_rules();
}

add_action('plugins_loaded', 'ai_connect_init');
function ai_connect_init() {
    // Check if dependencies are loaded
    if (!file_exists(AI_CONNECT_PATH . 'vendor/autoload.php')) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error"><p>';
            echo esc_html__('AI Connect: Missing dependencies. Please run "composer install" in the plugin directory.', 'ai-connect');
            echo '</p></div>';
        });
        return;
    }
    
    // Run database upgrades if needed
    require_once AI_CONNECT_PATH . 'includes/oauth/class-database.php';
    \AIConnect\OAuth\Database::maybe_upgrade();
    
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-warning"><p>';
            echo esc_html__('AI Connect: WooCommerce is recommended for full functionality.', 'ai-connect');
            echo '</p></div>';
        });
    }
    
    require_once AI_CONNECT_PATH . 'includes/class-ai-connect.php';
    $plugin = new AI_Connect();
    $plugin->run();
}

add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'ai_connect_action_links');
function ai_connect_action_links($links) {
    $settings_link = '<a href="admin.php?page=ai-connect">' . esc_html__('Settings', 'ai-connect') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}
