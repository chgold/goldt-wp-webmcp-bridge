<?php
namespace GoldtWebMCP\API;

use GoldtWebMCP\Core\Rate_Limiter;

if (!defined('ABSPATH')) {
    exit;
}

class Tools_Endpoint {
    
    private $rate_limiter;
    private $modules = [];
    
    public function __construct() {
        $this->rate_limiter = new Rate_Limiter();
    }
    
    public function register_routes() {
        \register_rest_route('goldt-webmcp-bridge/v1', '/tools/(?P<tool>[a-zA-Z0-9._-]+)', [
            'methods' => 'POST',
            'callback' => [$this, 'execute_tool'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
        
        \register_rest_route('goldt-webmcp-bridge/v1', '/tools', [
            'methods' => 'GET',
            'callback' => [$this, 'list_tools'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
        
        // OAuth endpoints removed - now handled by dedicated OAuth classes
        // Direct username+password authentication DISABLED for security
        // Use OAuth 2.0 flow instead: /?goldtwmcp_oauth_authorize
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
    
    public function check_permission($request) {
        if (!\is_user_logged_in()) {
            return new \WP_Error('no_token', 'No authentication token provided. Use OAuth 2.0 flow.', ['status' => 401]);
        }
        
        $user_id = \get_current_user_id();
        
        $blacklisted_users = \get_option('goldtwmcp_blacklisted_users', []);
        if (in_array($user_id, $blacklisted_users, true)) {
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
