<?php
namespace GoldtWebMCP\OAuth;

if (!defined('ABSPATH')) {
    exit;
}

class Authorize_Endpoint {
    
    private $oauth_server;
    
    public function __construct() {
        $this->oauth_server = new OAuth_Server();
    }
    
    public function init() {
        add_action('template_redirect', [$this, 'handle_authorize_request']);
    }
    
    public function handle_authorize_request() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth flow uses state parameter for CSRF protection
        if (!isset($_GET['goldtwmcp_oauth_authorize'])) {
            return;
        }
        
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth parameters come from external client, validated via PKCE
        $client_id = isset($_GET['client_id']) ? sanitize_text_field(wp_unslash($_GET['client_id'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $redirect_uri = isset($_GET['redirect_uri']) ? esc_url_raw(wp_unslash($_GET['redirect_uri'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $response_type = isset($_GET['response_type']) ? sanitize_text_field(wp_unslash($_GET['response_type'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $scope = isset($_GET['scope']) ? sanitize_text_field(wp_unslash($_GET['scope'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $state = isset($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $code_challenge = isset($_GET['code_challenge']) ? sanitize_text_field(wp_unslash($_GET['code_challenge'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $code_challenge_method = isset($_GET['code_challenge_method']) ? sanitize_text_field(wp_unslash($_GET['code_challenge_method'])) : '';
        
        if ($response_type !== 'code') {
            $this->send_error('unsupported_response_type', 'Only authorization code flow is supported');
            return;
        }
        
        if (!$this->oauth_server->validate_client($client_id)) {
            $this->send_error('invalid_client', 'Invalid client_id');
            return;
        }
        
        if (!$this->oauth_server->validate_redirect_uri($client_id, $redirect_uri)) {
            $this->send_error('invalid_request', 'Invalid redirect_uri');
            return;
        }
        
        if (empty($code_challenge) || $code_challenge_method !== 'S256') {
            $this->send_error('invalid_request', 'PKCE required: code_challenge with S256 method');
            return;
        }
        
        $scopes = !empty($scope) ? explode(' ', $scope) : ['read'];
        
        if (!$this->oauth_server->validate_scopes($client_id, $scopes)) {
            $this->send_error('invalid_scope', 'Invalid scope requested');
            return;
        }
        
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified inside handle_approval/handle_denial
        if (isset($_POST['goldtwmcp_oauth_approve'])) {
            $this->handle_approval($client_id, $redirect_uri, $code_challenge, $code_challenge_method, $scopes, $state);
            return;
        }
        
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified inside handle_denial
        if (isset($_POST['goldtwmcp_oauth_deny'])) {
            $this->handle_denial($redirect_uri, $state);
            return;
        }
        
        $this->show_consent_screen($client_id, $redirect_uri, $response_type, $scope, $state, $code_challenge, $code_challenge_method, $scopes);
    }
    
    private function handle_approval($client_id, $redirect_uri, $code_challenge, $code_challenge_method, $scopes, $state) {
        if (!is_user_logged_in()) {
            $request_uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '';
            wp_safe_redirect(wp_login_url($request_uri));
            exit;
        }
        
        check_admin_referer('goldtwmcp_oauth_consent');
        
        $user_id = get_current_user_id();
        
        $code = $this->oauth_server->create_authorization_code(
            $client_id,
            $user_id,
            $redirect_uri,
            $code_challenge,
            $code_challenge_method,
            $scopes
        );
        
        if (is_wp_error($code)) {
            $this->send_error('server_error', $code->get_error_message());
            return;
        }
        
        if ($redirect_uri === 'urn:ietf:wg:oauth:2.0:oob') {
            $this->show_oob_code($code);
            return;
        }
        
        $redirect_url = add_query_arg([
            'code' => $code,
            'state' => $state
        ], $redirect_uri);
        
        wp_safe_redirect($redirect_url);
        exit;
    }
    
    private function handle_denial($redirect_uri, $state) {
        check_admin_referer('goldtwmcp_oauth_consent');
        
        if ($redirect_uri === 'urn:ietf:wg:oauth:2.0:oob') {
            wp_die('Authorization denied', 'Access Denied', ['response' => 403]);
        }
        
        $redirect_url = add_query_arg([
            'error' => 'access_denied',
            'error_description' => 'User denied authorization',
            'state' => $state
        ], $redirect_uri);
        
        wp_safe_redirect($redirect_url);
        exit;
    }
    
    private function show_consent_screen($client_id, $redirect_uri, $response_type, $scope, $state, $code_challenge, $code_challenge_method, $scopes) {
        if (!is_user_logged_in()) {
            $request_uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '';
            wp_safe_redirect(wp_login_url($request_uri));
            exit;
        }
        
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- OAuth client data, not cached
        $client = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}goldtwmcp_oauth_clients WHERE client_id = %s",
            $client_id
        ));
        
        include GOLDTWMCP_PATH . 'includes/oauth/views/consent-screen.php';
        exit;
    }
    
    private function show_oob_code($code) {
        include GOLDTWMCP_PATH . 'includes/oauth/views/oob-code.php';
        exit;
    }
    
    private function send_error($error, $description) {
        wp_die(
            esc_html($description),
            esc_html(ucfirst(str_replace('_', ' ', $error))),
            ['response' => 400]
        );
    }
}
