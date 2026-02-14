<?php
namespace AIConnect\API;

use AIConnect\Core\Auth;
use AIConnect\Core\Rate_Limiter;

if (!defined('ABSPATH')) {
    exit;
}

class Tools_Endpoint {
    
    private $auth;
    private $rate_limiter;
    private $modules = [];
    
    public function __construct() {
        $this->auth = new Auth();
        $this->rate_limiter = new Rate_Limiter();
    }
    
    public function register_routes() {
        \register_rest_route('ai-connect/v1', '/tools/(?P<tool>[a-zA-Z0-9._-]+)', [
            'methods' => 'POST',
            'callback' => [$this, 'execute_tool'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
        
        \register_rest_route('ai-connect/v1', '/tools', [
            'methods' => 'GET',
            'callback' => [$this, 'list_tools'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
        
        \register_rest_route('ai-connect/v1', '/oauth/authorize', [
            'methods' => 'GET',
            'callback' => [$this, 'authorize'],
            'permission_callback' => '__return_true',
        ]);
        
        \register_rest_route('ai-connect/v1', '/oauth/token', [
            'methods' => 'POST',
            'callback' => [$this, 'token'],
            'permission_callback' => '__return_true',
        ]);
        
        \register_rest_route('ai-connect/v1', '/oauth/refresh', [
            'methods' => 'POST',
            'callback' => [$this, 'refresh_token'],
            'permission_callback' => '__return_true',
        ]);
        
        // Direct authentication endpoint (no OAuth Client required)
        \register_rest_route('ai-connect/v1', '/auth/login', [
            'methods' => 'POST',
            'callback' => [$this, 'direct_login'],
            'permission_callback' => '__return_true',
        ]);
    }
    
    public function register_module($module) {
        $module_name = $module->get_module_name();
        $this->modules[$module_name] = $module;
    }
    
    public function execute_tool($request) {
        $tool_name = $request->get_param('tool');
        $params = $request->get_json_params() ?: [];
        
        list($module_name, $tool_method) = $this->parse_tool_name($tool_name);
        
        if (!isset($this->modules[$module_name])) {
            return new \WP_Error('module_not_found', sprintf('Module %s not found', $module_name), ['status' => 404]);
        }
        
        $module = $this->modules[$module_name];
        $result = $module->execute_tool($tool_method, $params);
        
        if (\is_wp_error($result)) {
            return $result;
        }
        
        return \rest_ensure_response($result);
    }
    
    public function list_tools($request) {
        $tools = [];
        
        foreach ($this->modules as $module_name => $module) {
            $module_tools = $module->get_tools();
            foreach ($module_tools as $tool) {
                $tools[] = [
                    'name' => $tool['name'],
                    'description' => $tool['description'],
                    'input_schema' => $tool['input_schema'],
                ];
            }
        }
        
        return \rest_ensure_response(['tools' => $tools]);
    }
    
    public function authorize($request) {
        $client_id = $request->get_param('client_id');
        $redirect_uri = $request->get_param('redirect_uri');
        $response_type = $request->get_param('response_type');
        $scopes = $request->get_param('scope') ? explode(' ', $request->get_param('scope')) : ['read'];
        $state = $request->get_param('state');
        
        if (empty($client_id) || empty($redirect_uri) || $response_type !== 'code') {
            return new \WP_Error('invalid_request', 'Missing or invalid parameters', ['status' => 400]);
        }
        
        $client = $this->auth->validate_client($client_id);
        if (\is_wp_error($client)) {
            return $client;
        }
        
        $redirect_check = $this->auth->validate_redirect_uri($client_id, $redirect_uri);
        if (\is_wp_error($redirect_check)) {
            return $redirect_check;
        }
        
        if (!\is_user_logged_in()) {
            $request_uri = '';
            if (isset($_SERVER['REQUEST_URI'])) {
                $request_uri = \wp_unslash($_SERVER['REQUEST_URI']); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            }
            $request_uri = \esc_url_raw($request_uri);
            \wp_safe_redirect(\wp_login_url($request_uri));
            exit;
        }
        
        $code = $this->auth->generate_authorization_code($client_id, \get_current_user_id(), $scopes);
        
        $redirect_params = http_build_query([
            'code' => $code,
            'state' => $state,
        ]);
        
        \wp_safe_redirect($redirect_uri . '?' . $redirect_params);
        exit;
    }
    
    public function token($request) {
        $grant_type = $request->get_param('grant_type');
        $client_id = $request->get_param('client_id');
        $client_secret = $request->get_param('client_secret');
        
        if ($grant_type !== 'authorization_code') {
            return new \WP_Error('unsupported_grant_type', 'Grant type not supported', ['status' => 400]);
        }
        
        $client = $this->auth->validate_client($client_id, $client_secret);
        if (\is_wp_error($client)) {
            return $client;
        }
        
        $code = $request->get_param('code');
        $auth_data = $this->auth->validate_authorization_code($code, $client_id);
        
        if (\is_wp_error($auth_data)) {
            return $auth_data;
        }
        
        $access_token = $this->auth->generate_access_token(
            $auth_data['user_id'],
            $client_id,
            $auth_data['scopes']
        );
        
        $refresh_token = $this->auth->generate_refresh_token(
            $auth_data['user_id'],
            $client_id,
            $auth_data['scopes']
        );
        
        return \rest_ensure_response([
            'access_token' => $access_token,
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'refresh_token' => $refresh_token,
            'scope' => implode(' ', $auth_data['scopes']),
        ]);
    }
    
    public function refresh_token($request) {
        $refresh_token = $request->get_param('refresh_token');
        $client_id = $request->get_param('client_id');
        $client_secret = $request->get_param('client_secret');
        
        if (empty($refresh_token)) {
            return new \WP_Error('invalid_request', 'Missing refresh_token', ['status' => 400]);
        }
        
        // For direct_auth, client_id and client_secret are optional
        if (empty($client_id)) {
            $client_id = 'direct_auth';
        }
        
        // Validate client only if not direct_auth
        if ($client_id !== 'direct_auth') {
            if (empty($client_secret)) {
                return new \WP_Error('invalid_request', 'Missing client_secret', ['status' => 400]);
            }
            
            $client = $this->auth->validate_client($client_id, $client_secret);
            if (\is_wp_error($client)) {
                return $client;
            }
        }
        
        $result = $this->auth->refresh_access_token($refresh_token, $client_id);
        
        if (\is_wp_error($result)) {
            return $result;
        }
        
        // Check blacklist before returning new token
        if (isset($result['user_id'])) {
            $blacklisted_users = \get_option('ai_connect_blacklisted_users', []);
            if (in_array($result['user_id'], $blacklisted_users)) {
                return new \WP_Error('access_denied', 'Your access to AI Connect has been revoked', ['status' => 403]);
            }
        }
        
        return \rest_ensure_response($result);
    }
    
    /**
     * Direct login endpoint - authenticate with username/password, no OAuth Client needed
     */
    public function direct_login($request) {
        $username = $request->get_param('username');
        $password = $request->get_param('password');
        
        if (empty($username) || empty($password)) {
            return new \WP_Error('invalid_request', 'Username and password are required', ['status' => 400]);
        }
        
        // Authenticate user
        $user = \wp_authenticate($username, $password);
        
        if (\is_wp_error($user)) {
            return new \WP_Error('authentication_failed', 'Invalid username or password', ['status' => 401]);
        }
        
        // Check if user is blacklisted
        $blacklisted_users = \get_option('ai_connect_blacklisted_users', []);
        if (in_array($user->ID, $blacklisted_users)) {
            return new \WP_Error('access_denied', 'Your access to AI Connect has been revoked', ['status' => 403]);
        }
        
        // Generate tokens with a virtual client_id for direct auth
        $client_id = 'direct_auth';
        $scopes = ['read', 'write'];
        
        $access_token = $this->auth->generate_access_token($user->ID, $client_id, $scopes);
        $refresh_token = $this->auth->generate_refresh_token($user->ID, $client_id, $scopes);
        
        return \rest_ensure_response([
            'access_token' => $access_token,
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'refresh_token' => $refresh_token,
            'scope' => implode(' ', $scopes),
            'user_id' => $user->ID,
            'user_login' => $user->user_login,
            'user_email' => $user->user_email,
        ]);
    }
    
    public function check_permission($request) {
        $token = $this->auth->get_token_from_request();
        
        if (empty($token)) {
            return new \WP_Error('no_token', 'No authentication token provided', ['status' => 401]);
        }
        
        $token_data = $this->auth->validate_access_token($token);
        
        if (\is_wp_error($token_data)) {
            return $token_data;
        }
        
        $user_id = $token_data['user_id'];
        
        // Check if user is blacklisted
        $blacklisted_users = \get_option('ai_connect_blacklisted_users', []);
        if (in_array($user_id, $blacklisted_users)) {
            return new \WP_Error('access_denied', 'Your access to AI Connect has been revoked', ['status' => 403]);
        }
        
        $identifier = 'user_' . $user_id;
        
        $rate_check = $this->rate_limiter->is_rate_limited($identifier);
        
        if ($rate_check['limited']) {
            return new \WP_Error(
                'rate_limit_exceeded',
                sprintf('Rate limit exceeded: %s', $rate_check['reason']),
                [
                    'status' => 429,
                    'retry_after' => $rate_check['retry_after'],
                    'limit' => $rate_check['limit'],
                    'current' => $rate_check['current'],
                ]
            );
        }
        
        $this->rate_limiter->record_request($identifier);
        
        $request->set_param('token_data', $token_data);
        
        // Set WordPress user context from JWT token
        \wp_set_current_user($token_data['user_id']);
        
        return true;
    }
    
    private function parse_tool_name($tool_name) {
        $parts = explode('.', $tool_name, 2);
        
        if (count($parts) === 2) {
            return $parts;
        }
        
        return ['wordpress', $tool_name];
    }
}
