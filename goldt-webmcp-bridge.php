<?php
/**
 * Plugin Name: Goldnat AI Connect for WordPress
 * Plugin URI: https://plugins.goldnat.ai/wordpress/goldt-webmcp-bridge
 * Description: Bridge for 8 AI agents (Claude, ChatGPT, Grok, more) via the Servio Protocol with OAuth 2.0
 * Version: 1.2.3
 * Author: chagold
 * Author URI: https://github.com/chgold
 * License: GPL v3
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: goldt-webmcp-bridge
 *
 * @package GoldtWebMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GOLDTWMCP_VERSION', '1.2.3' );
define( 'GOLDTWMCP_PATH', plugin_dir_path( __FILE__ ) );
define( 'GOLDTWMCP_URL', plugin_dir_url( __FILE__ ) );

// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
// Load Composer dependencies.
if ( file_exists( GOLDTWMCP_PATH . 'vendor/autoload.php' ) ) {
	require_once GOLDTWMCP_PATH . 'vendor/autoload.php';
}

register_activation_hook( __FILE__, 'goldtwmcp_activate' );
/**
 * Activation hook callback.
 *
 * @return void
 */
function goldtwmcp_activate() {
	// Create OAuth database tables.
	require_once GOLDTWMCP_PATH . 'includes/oauth/class-database.php';
	\GoldtWebMCP\OAuth\Database::create_tables();

	add_option( 'goldtwmcp_version', GOLDTWMCP_VERSION );
	add_option( 'goldtwmcp_installed', time() );

	// Register rewrite rules BEFORE flushing so they work immediately after activation.
	add_rewrite_rule( '^ai-connect/?$', 'index.php?goldtwmcp_info_page=1', 'top' );
	add_rewrite_rule( '^api/aiconnect-manifest/?$', 'index.php?aiconnect_endpoint=manifest', 'top' );
	add_rewrite_rule( '^api/aiconnect-tools/?$', 'index.php?aiconnect_endpoint=tools', 'top' );
	add_rewrite_rule( '^api/aiconnect-oauth/?$', 'index.php?aiconnect_endpoint=oauth', 'top' );
	flush_rewrite_rules();
}


register_deactivation_hook( __FILE__, 'goldtwmcp_deactivate' );
/**
 * Deactivation hook callback.
 *
 * @return void
 */
function goldtwmcp_deactivate() {
	// Unschedule token cleanup cron.
	$timestamp = wp_next_scheduled( 'goldtwmcp_token_cleanup_cron' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'goldtwmcp_token_cleanup_cron' );
	}
	flush_rewrite_rules();
}

/**
 * Detect a plugin upgrade and force a rewrite-rules flush on next wp_loaded.
 *
 * WordPress caches the compiled rewrite-rules array in the `rewrite_rules`
 * option. The activation hook flushes that cache — but activation does NOT
 * run when a plugin is updated via wp-admin, wp-cli, or an auto-update.
 * Result: the site keeps serving old cache and any new/renamed route (like
 * /api/aiconnect-manifest) returns the homepage instead of our handler.
 *
 * Fix: compare the stored `goldtwmcp_version` option to the constant on
 * every init. If they differ (installed → new code loaded → new constant),
 * schedule a flush for the current request and persist the new version so
 * we don't loop. First request after an upgrade will be marginally slower
 * (rebuild of rewrite cache), all subsequent requests hit the fresh cache.
 *
 * @return void
 */
function goldtwmcp_maybe_flush_on_upgrade() {
	$stored = (string) get_option( 'goldtwmcp_version', '0.0.0' );
	if ( version_compare( $stored, GOLDTWMCP_VERSION, '<' ) ) {
		update_option( 'goldtwmcp_version', GOLDTWMCP_VERSION );
		// Run at wp_loaded so all init-time rules (ours + third-party) are in place.
		add_action(
			'wp_loaded',
			static function () {
				flush_rewrite_rules( false ); // false = don't rewrite .htaccess if we can't.
			},
			999
		);
	}
}
add_action( 'init', 'goldtwmcp_maybe_flush_on_upgrade', 20 );

add_action( 'plugins_loaded', 'goldtwmcp_init' );
/**
 * Initialize plugin.
 *
 * @return void
 */
function goldtwmcp_init() {
	// Check if dependencies are loaded.
	if ( ! file_exists( GOLDTWMCP_PATH . 'vendor/autoload.php' ) ) {
		add_action(
			'admin_notices',
			function () {
				$activation_error = get_option( 'goldtwmcp_activation_error', '' );
				$github_url       = 'https://github.com/chgold/goldt-wp-webmcp-bridge/releases';

				echo '<div class="notice notice-error" style="padding: 15px;">';
				echo '<h3 style="margin-top: 0;">' . esc_html__( 'Goldnat AI Connect for WordPress: Missing Dependencies', 'goldt-webmcp-bridge' ) . '</h3>';
				echo '<p><strong>' . esc_html__( 'The plugin cannot run because vendor/ directory is missing.', 'goldt-webmcp-bridge' ) . '</strong></p>';

				if ( $activation_error ) {
					echo '<p><strong>' . esc_html__( 'Error during activation:', 'goldt-webmcp-bridge' ) . '</strong> ' . esc_html( $activation_error ) . '</p>';
				}

				echo '<h4>' . esc_html__( 'How to fix this:', 'goldt-webmcp-bridge' ) . '</h4>';
				echo '<ol style="margin-left: 20px;">';
				echo '<li><strong>' . esc_html__( 'Option 1 (Recommended):', 'goldt-webmcp-bridge' ) . '</strong> ';
				/* translators: %s: GitHub Releases link */
				printf(
				/* translators: %s: GitHub Releases link */
					esc_html__( 'Download the complete plugin with vendor/ included from %s', 'goldt-webmcp-bridge' ),
					'<a href="' . esc_url( $github_url ) . '" target="_blank">' . esc_html__( 'GitHub Releases', 'goldt-webmcp-bridge' ) . '</a>'
				);
				echo '</li>';
				echo '<li><strong>' . esc_html__( 'Option 2 (Advanced):', 'goldt-webmcp-bridge' ) . '</strong> ';
				echo esc_html__( 'Run this command in the plugin directory:', 'goldt-webmcp-bridge' );
				echo '<br><code style="background: #f0f0f0; padding: 5px; display: inline-block; margin-top: 5px;">cd ' . esc_html( GOLDTWMCP_PATH ) . ' && composer install --no-dev</code>';
				echo '</li>';
				echo '</ol>';

				echo '<p><em>' . esc_html__( 'After fixing, deactivate and reactivate the plugin.', 'goldt-webmcp-bridge' ) . '</em></p>';
				echo '</div>';
			}
		);
		return;
	}

	// Run database upgrades if needed.
	require_once GOLDTWMCP_PATH . 'includes/oauth/class-database.php';
	\GoldtWebMCP\OAuth\Database::maybe_upgrade();

	// Check if OAuth tables exist.
	if ( ! \GoldtWebMCP\OAuth\Database::tables_exist() ) {
		add_action(
			'admin_notices',
			function () {
				echo '<div class="notice notice-error" style="padding: 15px;">';
				echo '<h3 style="margin-top: 0;">' . esc_html__( 'Goldnat AI Connect for WordPress: Database Tables Missing', 'goldt-webmcp-bridge' ) . '</h3>';
				echo '<p>' . esc_html__( 'The OAuth database tables were not created during activation.', 'goldt-webmcp-bridge' ) . '</p>';
				echo '<p><strong>' . esc_html__( 'To fix this:', 'goldt-webmcp-bridge' ) . '</strong></p>';
				echo '<ol style="margin-left: 20px;">';
				echo '<li>' . esc_html__( 'Deactivate the plugin', 'goldt-webmcp-bridge' ) . '</li>';
				echo '<li>' . esc_html__( 'Reactivate the plugin', 'goldt-webmcp-bridge' ) . '</li>';
				echo '<li>' . esc_html__( 'If the problem persists, check that your database user has CREATE TABLE permissions', 'goldt-webmcp-bridge' ) . '</li>';
				echo '</ol>';
				echo '</div>';
			}
		);
		return;
	}

	require_once GOLDTWMCP_PATH . 'includes/class-goldtwmcp.php';
	$plugin = new GoldtWebMCP_Plugin();
	$plugin->run();

	// Load the shared Plugin Updater — each Pro plugin registers itself
	// with it during its own plugins_loaded (priority > 10 so this file
	// runs first). Cheap: hooks only wire on first register() call.
	require_once GOLDTWMCP_PATH . 'includes/core/class-plugin-updater.php';
}

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'goldtwmcp_action_links' );
/**
 * Add action links to plugin.
 *
 * @param array $links Plugin action links.
 * @return array
 */
function goldtwmcp_action_links( $links ) {
	$settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=goldt-webmcp-bridge' ) ) . '">' . esc_html__( 'Settings', 'goldt-webmcp-bridge' ) . '</a>';
	array_unshift( $links, $settings_link );
	return $links;
}
