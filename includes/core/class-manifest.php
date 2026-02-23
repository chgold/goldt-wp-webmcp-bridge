<?php
/**
 * WebMCP Manifest Generator
 * 
 * Generates WebMCP-compliant manifest for AI agent integration
 * 
 * @package AIConnect
 * @since 0.1.0
 */

namespace GoldtWebMCP\Core;

if (!defined('ABSPATH')) {
    exit;
}

class Manifest {
    
    /**
     * Manifest metadata
     * @var array
     */
    private $manifest_data;
    
    /**
     * Registered tools
     * @var array
     */
    private $tools = [];
    
    /**
     * Registered resources
     * @var array
     */
    private $resources = [];
    
    /**
     * Registered prompts
     * @var array
     */
    private $prompts = [];
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->manifest_data = [
            'schema_version' => '1.0',
            'name' => 'goldt-webmcp-bridge',
            'version' => GOLDTWMCP_VERSION,
            'description' => 'WebMCP bridge for WordPress - manage content, users, and e-commerce',
            'api_version' => 'v1',
            'capabilities' => [
                'tools' => true,
                'resources' => true,
                'prompts' => false,
            ],
        ];
    }
    
    /**
     * Register a tool
     * 
     * @param string $name Tool identifier
     * @param array $config Tool configuration
     * @return bool
     */
    public function register_tool($name, $config) {
        if (empty($name) || empty($config)) {
            return false;
        }
        
        // Validate required fields
        $required_fields = ['description', 'input_schema'];
        foreach ($required_fields as $field) {
            if (!isset($config[$field])) {
                return false;
            }
        }
        
        // Set defaults
        $tool = [
            'name' => $name,
            'description' => $config['description'],
            'input_schema' => $config['input_schema'],
        ];
        
        // Optional fields
        if (isset($config['examples'])) {
            $tool['examples'] = $config['examples'];
        }
        
        if (isset($config['dangerous'])) {
            $tool['dangerous'] = (bool) $config['dangerous'];
        }
        
        $this->tools[$name] = $tool;
        
        return true;
    }
    
    /**
     * Register a resource
     * 
     * @param string $uri Resource URI
     * @param array $config Resource configuration
     * @return bool
     */
    public function register_resource($uri, $config) {
        if (empty($uri) || empty($config)) {
            return false;
        }
        
        // Validate required fields
        if (!isset($config['name']) || !isset($config['description'])) {
            return false;
        }
        
        $resource = [
            'uri' => $uri,
            'name' => $config['name'],
            'description' => $config['description'],
        ];
        
        // Optional fields
        if (isset($config['mime_type'])) {
            $resource['mime_type'] = $config['mime_type'];
        }
        
        $this->resources[$uri] = $resource;
        
        return true;
    }
    
    /**
     * Register a prompt
     * 
     * @param string $name Prompt identifier
     * @param array $config Prompt configuration
     * @return bool
     */
    public function register_prompt($name, $config) {
        if (empty($name) || empty($config)) {
            return false;
        }
        
        // Validate required fields
        if (!isset($config['description'])) {
            return false;
        }
        
        $prompt = [
            'name' => $name,
            'description' => $config['description'],
        ];
        
        // Optional fields
        if (isset($config['arguments'])) {
            $prompt['arguments'] = $config['arguments'];
        }
        
        $this->prompts[$name] = $prompt;
        
        return true;
    }
    
    /**
     * Get all registered tools
     * 
     * @return array
     */
    public function get_tools() {
        return array_values($this->tools);
    }
    
    /**
     * Get all registered resources
     * 
     * @return array
     */
    public function get_resources() {
        return array_values($this->resources);
    }
    
    /**
     * Get all registered prompts
     * 
     * @return array
     */
    public function get_prompts() {
        return array_values($this->prompts);
    }
    
    /**
     * Generate complete WebMCP manifest
     * 
     * @return array
     */
    public function generate() {
        $manifest = $this->manifest_data;
        
        // Add tools if registered
        if (!empty($this->tools)) {
            $manifest['tools'] = $this->get_tools();
        }
        
        // Add resources if registered
        if (!empty($this->resources)) {
            $manifest['resources'] = $this->get_resources();
        }
        
        // Add prompts if registered
        if (!empty($this->prompts)) {
            $manifest['prompts'] = $this->get_prompts();
            $manifest['capabilities']['prompts'] = true;
        }
        
        // Add server info
        $manifest['server'] = [
            'url' => \rest_url('goldt-webmcp-bridge/v1'),
            'description' => 'GoldT WebMCP Bridge API',
        ];
        
        // Add authentication info
        $site_url = \get_site_url();
        $scopes_data = \GoldtWebMCP\OAuth\Scopes::get_all_scopes();
        $scopes = [];
        foreach ($scopes_data as $scope => $data) {
            $scopes[$scope] = $data['description'];
        }
        
        $manifest['auth'] = [
            'type' => 'oauth2',
            'flow' => 'authorization_code',
            'authorization_url' => $site_url . '/?goldtwmcp_oauth_authorize',
            'token_url' => \rest_url('goldt-webmcp-bridge/v1/oauth/token'),
            'revoke_url' => \rest_url('goldt-webmcp-bridge/v1/oauth/revoke'),
            'pkce_required' => true,
            'code_challenge_method' => 'S256',
            'redirect_uri' => 'urn:ietf:wg:oauth:2.0:oob',
            'scopes' => $scopes,
        ];
        
        // Add usage instructions
        $manifest['usage'] = [
            'tools_endpoint' => \rest_url('goldt-webmcp-bridge/v1/tools/{tool_name}'),
            'example' => \rest_url('goldt-webmcp-bridge/v1/tools/wordpress.searchPosts'),
            'method' => 'POST',
            'headers' => [
                'Authorization' => 'Bearer {access_token}',
                'Content-Type' => 'application/json',
            ],
        ];
        
        return \apply_filters('goldtwmcp_manifest', $manifest);
    }
    
    /**
     * Generate manifest as JSON
     * 
     * @param bool $pretty Pretty print JSON
     * @return string
     */
    public function generate_json($pretty = false) {
        $manifest = $this->generate();
        $options = $pretty ? JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES : JSON_UNESCAPED_SLASHES;
        return json_encode($manifest, $options);
    }
    
    /**
     * Set manifest metadata
     * 
     * @param string $key Metadata key
     * @param mixed $value Metadata value
     * @return void
     */
    public function set_metadata($key, $value) {
        $allowed_keys = ['name', 'version', 'description', 'schema_version', 'api_version'];
        
        if (in_array($key, $allowed_keys)) {
            $this->manifest_data[$key] = $value;
        }
    }
    
    /**
     * Get manifest metadata
     * 
     * @param string $key Metadata key (optional)
     * @return mixed
     */
    public function get_metadata($key = null) {
        if ($key === null) {
            return $this->manifest_data;
        }
        
        return isset($this->manifest_data[$key]) ? $this->manifest_data[$key] : null;
    }
}
