<?php
namespace AIConnect\OAuth;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * OAuth Database Schema Manager
 */
class Database {
    
    /**
     * Get current database version
     */
    public static function get_version() {
        return get_option('ai_connect_oauth_db_version', '0.0.0');
    }
    
    /**
     * Set database version
     */
    public static function set_version($version) {
        update_option('ai_connect_oauth_db_version', $version);
    }
    
    /**
     * Create OAuth tables
     */
    public static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        $sql_clients = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ai_connect_oauth_clients (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id VARCHAR(80) NOT NULL,
            client_name VARCHAR(255) NOT NULL,
            client_type VARCHAR(20) NOT NULL DEFAULT 'public',
            redirect_uris TEXT DEFAULT NULL,
            allowed_scopes TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY client_id (client_id)
        ) $charset_collate;";
        
        $sql_codes = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ai_connect_oauth_codes (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            code VARCHAR(128) NOT NULL,
            client_id VARCHAR(80) NOT NULL,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            redirect_uri VARCHAR(500) DEFAULT NULL,
            code_challenge VARCHAR(128) DEFAULT NULL,
            code_challenge_method VARCHAR(10) DEFAULT NULL,
            scopes TEXT DEFAULT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY code (code),
            KEY expires_at (expires_at),
            KEY client_id (client_id),
            KEY user_id (user_id)
        ) $charset_collate;";
        
        $sql_tokens = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ai_connect_oauth_tokens (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            token VARCHAR(255) NOT NULL,
            client_id VARCHAR(80) NOT NULL,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            scopes TEXT DEFAULT NULL,
            expires_at DATETIME NOT NULL,
            revoked_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY token (token),
            KEY user_id (user_id),
            KEY client_id (client_id),
            KEY expires_at (expires_at)
        ) $charset_collate;";
        
        dbDelta($sql_clients);
        dbDelta($sql_codes);
        dbDelta($sql_tokens);
        
        self::insert_default_clients();
        self::set_version('1.0.0');
    }
    
    /**
     * Insert default OAuth clients (Claude, ChatGPT, etc.)
     */
    private static function insert_default_clients() {
        global $wpdb;
        
        $clients = [
            [
                'client_id' => 'claude-ai',
                'client_name' => 'Claude AI (Anthropic)',
                'client_type' => 'public',
                'redirect_uris' => json_encode(['urn:ietf:wg:oauth:2.0:oob']),
                'allowed_scopes' => json_encode(['read', 'write'])
            ],
            [
                'client_id' => 'chatgpt',
                'client_name' => 'ChatGPT (OpenAI)',
                'client_type' => 'public',
                'redirect_uris' => json_encode(['urn:ietf:wg:oauth:2.0:oob']),
                'allowed_scopes' => json_encode(['read', 'write'])
            ],
            [
                'client_id' => 'gemini',
                'client_name' => 'Gemini (Google)',
                'client_type' => 'public',
                'redirect_uris' => json_encode(['urn:ietf:wg:oauth:2.0:oob']),
                'allowed_scopes' => json_encode(['read', 'write'])
            ]
        ];
        
        foreach ($clients as $client) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- OAuth setup, runs once on activation, no caching needed
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}ai_connect_oauth_clients WHERE client_id = %s",
                $client['client_id']
            ));
            
            if (!$exists) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- OAuth setup, inserting default clients
                $wpdb->insert(
                    "{$wpdb->prefix}ai_connect_oauth_clients",
                    $client
                );
            }
        }
    }
    
    /**
     * Drop all OAuth tables
     */
    public static function drop_tables() {
        global $wpdb;
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- OAuth cleanup on uninstall
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}ai_connect_oauth_tokens");
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- OAuth cleanup on uninstall
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}ai_connect_oauth_codes");
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- OAuth cleanup on uninstall
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}ai_connect_oauth_clients");
        
        delete_option('ai_connect_oauth_db_version');
    }
    
    /**
     * Clean expired codes and tokens
     */
    public static function cleanup() {
        global $wpdb;
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- OAuth cleanup cron job
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}ai_connect_oauth_codes 
             WHERE expires_at < %s",
            current_time('mysql')
        ));
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- OAuth cleanup cron job
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}ai_connect_oauth_tokens 
             WHERE expires_at < %s AND revoked_at IS NULL",
            current_time('mysql')
        ));
    }
}
