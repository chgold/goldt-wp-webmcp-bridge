<?php
namespace AIConnect\OAuth;

if (!defined('ABSPATH')) {
    exit;
}

class Revoke_Endpoint {
    
    private $oauth_server;
    
    public function __construct() {
        $this->oauth_server = new OAuth_Server();
    }
    
    public function init() {
        add_action('rest_api_init', [$this, 'register_routes']);
    }
    
    public function register_routes() {
        register_rest_route('ai-connect/v1/oauth', '/revoke', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_revoke_request'],
            'permission_callback' => '__return_true',
        ]);
    }
    
    public function handle_revoke_request($request) {
        $token = $request->get_param('token');
        
        if (empty($token)) {
            return new \WP_Error(
                'invalid_request',
                'Missing token parameter',
                ['status' => 400]
            );
        }
        
        $revoked = $this->oauth_server->revoke_token($token);
        
        if (!$revoked) {
            return new \WP_Error(
                'invalid_token',
                'Token not found or already revoked',
                ['status' => 400]
            );
        }
        
        return rest_ensure_response([
            'success' => true,
            'message' => 'Token revoked successfully'
        ]);
    }
}
