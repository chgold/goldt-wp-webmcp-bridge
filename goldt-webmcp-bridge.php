<?php
/**
 * Plugin Name: GoldT WebMCP Bridge
 * Plugin URI: 
 * Description: Bridge for 8 AI agents (Claude, ChatGPT, Grok, more) via WebMCP with OAuth 2.0
 * Version: 0.2.1
 * Author: chagold
 * Author URI: https://github.com/chgold
 * License: GPL v3
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: goldt-webmcp-bridge
 */

if (!defined('ABSPATH')) {
    exit;
}

define('GOLDTWMCP_VERSION', '0.2.1');
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
    $error_log = [];
    
    if (!file_exists($composer_json)) {
        $error_log[] = 'composer.json not found';
        update_option('goldtwmcp_activation_error', implode('; ', $error_log));
        return false;
    }
    
    // Check if exec() is disabled
    $disabled_functions = explode(',', ini_get('disable_functions'));
    $disabled_functions = array_map('trim', $disabled_functions);
    if (in_array('exec', $disabled_functions)) {
        $error_log[] = 'exec() function is disabled by server configuration';
        update_option('goldtwmcp_activation_error', implode('; ', $error_log));
        return false;
    }
    
    // Check if composer is available
    $composer_cmd = 'composer';
    $output = [];
    exec('which composer 2>/dev/null', $output, $return_var);
    
    if ($return_var !== 0) {
        // Try composer.phar
        exec('which composer.phar 2>/dev/null', $output, $return_var);
        if ($return_var === 0) {
            $composer_cmd = 'composer.phar';
        } else {
            $error_log[] = 'composer not found in PATH';
            update_option('goldtwmcp_activation_error', implode('; ', $error_log));
            return false;
        }
    }
    
    // Run composer install
    $old_dir = getcwd();
    chdir(GOLDTWMCP_PATH);
    $output = [];
    exec($composer_cmd . ' install --no-dev --optimize-autoloader 2>&1', $output, $return_var);
    chdir($old_dir);
    
    if ($return_var !== 0) {
        $error_log[] = 'composer install failed: ' . implode(' ', array_slice($output, -3));
        update_option('goldtwmcp_activation_error', implode('; ', $error_log));
        return false;
    }
    
    delete_option('goldtwmcp_activation_error');
    return true;
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
            $activation_error = get_option('goldtwmcp_activation_error', '');
            $github_url = 'https://github.com/chgold/goldt-wp-webmcp-bridge/releases';
            
            echo '<div class="notice notice-error" style="padding: 15px;">';
            echo '<h3 style="margin-top: 0;">' . esc_html__('GoldT WebMCP Bridge: Missing Dependencies', 'goldt-webmcp-bridge') . '</h3>';
            echo '<p><strong>' . esc_html__('The plugin cannot run because vendor/ directory is missing.', 'goldt-webmcp-bridge') . '</strong></p>';
            
            if ($activation_error) {
                echo '<p><strong>' . esc_html__('Error during activation:', 'goldt-webmcp-bridge') . '</strong> ' . esc_html($activation_error) . '</p>';
            }
            
            echo '<h4>' . esc_html__('How to fix this:', 'goldt-webmcp-bridge') . '</h4>';
            echo '<ol style="margin-left: 20px;">';
            echo '<li><strong>' . esc_html__('Option 1 (Recommended):', 'goldt-webmcp-bridge') . '</strong> ';
            /* translators: %s: GitHub Releases link */
            echo sprintf(
                /* translators: %s: GitHub Releases link */
                esc_html__('Download the complete plugin with vendor/ included from %s', 'goldt-webmcp-bridge'),
                '<a href="' . esc_url($github_url) . '" target="_blank">' . esc_html__('GitHub Releases', 'goldt-webmcp-bridge') . '</a>'
            );
            echo '</li>';
            echo '<li><strong>' . esc_html__('Option 2 (Advanced):', 'goldt-webmcp-bridge') . '</strong> ';
            echo esc_html__('Run this command in the plugin directory:', 'goldt-webmcp-bridge');
            echo '<br><code style="background: #f0f0f0; padding: 5px; display: inline-block; margin-top: 5px;">cd ' . esc_html(GOLDTWMCP_PATH) . ' && composer install --no-dev</code>';
            echo '</li>';
            echo '</ol>';
            
            echo '<p><em>' . esc_html__('After fixing, deactivate and reactivate the plugin.', 'goldt-webmcp-bridge') . '</em></p>';
            echo '</div>';
        });
        return;
    }
    
    // Run database upgrades if needed
    require_once GOLDTWMCP_PATH . 'includes/oauth/class-database.php';
    \GoldtWebMCP\OAuth\Database::maybe_upgrade();
    
    // Check if OAuth tables exist
    if (!\GoldtWebMCP\OAuth\Database::tables_exist()) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error" style="padding: 15px;">';
            echo '<h3 style="margin-top: 0;">' . esc_html__('GoldT WebMCP Bridge: Database Tables Missing', 'goldt-webmcp-bridge') . '</h3>';
            echo '<p>' . esc_html__('The OAuth database tables were not created during activation.', 'goldt-webmcp-bridge') . '</p>';
            echo '<p><strong>' . esc_html__('To fix this:', 'goldt-webmcp-bridge') . '</strong></p>';
            echo '<ol style="margin-left: 20px;">';
            echo '<li>' . esc_html__('Deactivate the plugin', 'goldt-webmcp-bridge') . '</li>';
            echo '<li>' . esc_html__('Reactivate the plugin', 'goldt-webmcp-bridge') . '</li>';
            echo '<li>' . esc_html__('If the problem persists, check that your database user has CREATE TABLE permissions', 'goldt-webmcp-bridge') . '</li>';
            echo '</ol>';
            echo '</div>';
        });
        return;
    }
    
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
