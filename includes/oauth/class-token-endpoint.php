<?php
namespace AIConnect\OAuth;

if (!defined('ABSPATH')) {
    exit;
}

class Token_Endpoint {
    
    private $oauth_server;
    
    public function __construct() {
        $this->oauth_server = new OAuth_Server();
    }
    
    public function init() {
        add_action('rest_api_init', [$this, 'register_routes']);
    }
    
    public function register_routes() {
        register_rest_route('ai-connect/v1/oauth', '/token', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_token_request'],
            'permission_callback' => '__return_true',
        ]);
    }
    
    public function handle_token_request($request) {
        $grant_type = $request->get_param('grant_type');
        
        if ($grant_type === 'authorization_code') {
            return $this->handle_authorization_code_grant($request);
        }
        
        if ($grant_type === 'refresh_token') {
            return $this->handle_refresh_token_grant($request);
        }
        
        return new \WP_Error(
            'unsupported_grant_type',
            'Grant type not supported',
            ['status' => 400]
        );
    }
    
    private function handle_authorization_code_grant($request) {
        $code = $request->get_param('code');
        $client_id = $request->get_param('client_id');
        $code_verifier = $request->get_param('code_verifier');
        $redirect_uri = $request->get_param('redirect_uri');
        
        if (empty($code) || empty($client_id) || empty($code_verifier)) {
            return new \WP_Error(
                'invalid_request',
                'Missing required parameters',
                ['status' => 400]
            );
        }
        
        $token = $this->oauth_server->exchange_code_for_token(
            $code,
            $client_id,
            $code_verifier,
            $redirect_uri
        );
        
        if (is_wp_error($token)) {
            return new \WP_Error(
                $token->get_error_code(),
                $token->get_error_message(),
                ['status' => 400]
            );
        }
        
        return rest_ensure_response($token);
    }
    
    private function handle_refresh_token_grant($request) {
        $refresh_token = $request->get_param('refresh_token');
        $client_id = $request->get_param('client_id');
        
        if (empty($refresh_token) || empty($client_id)) {
            return new \WP_Error(
                'invalid_request',
                'Missing required parameters',
                ['status' => 400]
            );
        }
        
        $token = $this->oauth_server->exchange_refresh_token(
            $refresh_token,
            $client_id
        );
        
        if (is_wp_error($token)) {
            return new \WP_Error(
                $token->get_error_code(),
                $token->get_error_message(),
                ['status' => 400]
            );
        }
        
        return rest_ensure_response($token);
    }
}
