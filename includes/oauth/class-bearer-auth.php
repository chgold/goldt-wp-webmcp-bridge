<?php
namespace GoldtWebMCP\OAuth;

if (!defined('ABSPATH')) {
    exit;
}

class Bearer_Auth {
    
    private $oauth_server;
    
    public function __construct() {
        $this->oauth_server = new OAuth_Server();
    }
    
    public function init() {
        add_filter('determine_current_user', [$this, 'determine_user_from_bearer_token'], 20);
        add_filter('rest_authentication_errors', [$this, 'rest_auth_check']);
    }
    
    public function determine_user_from_bearer_token($user_id) {
        if ($user_id) {
            return $user_id;
        }
        
        $token = $this->get_bearer_token();
        
        if (!$token) {
            return $user_id;
        }
        
        $token_data = $this->oauth_server->validate_token($token);
        
        if (is_wp_error($token_data)) {
            return $user_id;
        }
        
        return $token_data['user_id'];
    }
    
    public function rest_auth_check($result) {
        if (!empty($result)) {
            return $result;
        }
        
        if (!$this->is_goldtwmcp_request()) {
            return $result;
        }
        
        // Public endpoints that don't require authentication
        if ($this->is_public_endpoint()) {
            return $result;
        }
        
        $token = $this->get_bearer_token();
        
        if (!$token) {
            return new \WP_Error(
                'rest_not_logged_in',
                'You are not currently logged in.',
                ['status' => 401]
            );
        }
        
        $token_data = $this->oauth_server->validate_token($token);
        
        if (is_wp_error($token_data)) {
            return new \WP_Error(
                'rest_invalid_token',
                $token_data->get_error_message(),
                ['status' => 401]
            );
        }
        
        return true;
    }
    
    private function get_bearer_token() {
        $auth_header = $this->get_authorization_header();
        
        if (!$auth_header) {
            return null;
        }
        
        if (strpos($auth_header, 'Bearer ') === 0) {
            return substr($auth_header, 7);
        }
        
        return null;
    }
    
    private function get_authorization_header() {
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            return sanitize_text_field(wp_unslash($_SERVER['HTTP_AUTHORIZATION']));
        }
        
        if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            return sanitize_text_field(wp_unslash($_SERVER['REDIRECT_HTTP_AUTHORIZATION']));
        }
        
        if (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            if (isset($headers['Authorization'])) {
                return sanitize_text_field($headers['Authorization']);
            }
        }
        
        return null;
    }
    
    private function is_goldtwmcp_request() {
        $request_uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        return strpos($request_uri, '/wp-json/ai-connect/') !== false;
    }
    
    private function is_public_endpoint() {
        $request_uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        
        // Public endpoints that don't require authentication
        $public_endpoints = [
            '/wp-json/ai-connect/v1/oauth/token',
            '/wp-json/ai-connect/v1/oauth/revoke',
            '/wp-json/ai-connect/v1/manifest',
            '/wp-json/ai-connect/v1/status',
        ];
        
        foreach ($public_endpoints as $endpoint) {
            if (strpos($request_uri, $endpoint) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    public function get_current_token_data() {
        $token = $this->get_bearer_token();
        
        if (!$token) {
            return null;
        }
        
        return $this->oauth_server->validate_token($token);
    }
    
    public function check_scope($required_scope) {
        $token_data = $this->get_current_token_data();
        
        if (!$token_data || is_wp_error($token_data)) {
            return false;
        }
        
        return in_array($required_scope, $token_data['scopes'], true);
    }
}
