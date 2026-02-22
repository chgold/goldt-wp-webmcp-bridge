<?php
namespace AIConnect\OAuth;

if (!defined('ABSPATH')) {
    exit;
}

class Admin_UI {
    
    public function init() {
        add_action('admin_menu', [$this, 'add_menu'], 20);
    }
    
    public function add_menu() {
        add_submenu_page(
            'ai-connect',
            __('OAuth Tokens', 'ai-connect'),
            __('OAuth Tokens', 'ai-connect'),
            'manage_options',
            'ai-connect-oauth',
            [$this, 'render_tokens_page']
        );
    }
    
    public function render_tokens_page() {
        if (isset($_POST['revoke_token'])) {
            check_admin_referer('ai_connect_revoke_token');
            $token_id = isset($_POST['token_id']) ? sanitize_text_field(wp_unslash($_POST['token_id'])) : '';
            $this->revoke_token($token_id);
        }
        
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- OAuth tokens admin listing
        $tokens = $wpdb->get_results("
            SELECT t.*, c.client_name, u.user_login
            FROM {$wpdb->prefix}ai_connect_oauth_tokens t
            LEFT JOIN {$wpdb->prefix}ai_connect_oauth_clients c ON t.client_id = c.client_id
            LEFT JOIN {$wpdb->users} u ON t.user_id = u.ID
            WHERE t.revoked_at IS NULL
            ORDER BY t.created_at DESC
        ");
        
        include AI_CONNECT_PATH . 'includes/oauth/views/admin-tokens.php';
    }
    
    private function revoke_token($token_id) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- OAuth token revocation
        $wpdb->update(
            "{$wpdb->prefix}ai_connect_oauth_tokens",
            ['revoked_at' => current_time('mysql')],
            ['id' => intval($token_id)],
            ['%s'],
            ['%d']
        );
        
        echo '<div class="notice notice-success"><p>' . esc_html__('Token revoked successfully.', 'ai-connect') . '</p></div>';
    }
}
