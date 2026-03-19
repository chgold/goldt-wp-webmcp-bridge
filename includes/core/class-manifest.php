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
                'resources' => false,
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
     * Build dynamic instructions for the AI agent based on registered tools and settings.
     *
     * @return string
     */
    private function get_translation_instructions() {
        $provider = \get_option('goldtwmcp_translation_provider', 'ai_self');

        if ($provider === 'mymemory') {
            return "## TRANSLATION (translation.translate)\n"
                . "Accepts text of ANY length — automatically split into chunks if needed. "
                . "Pass the full text without worrying about length.\n"
                . "IMPORTANT: Translation uses the MyMemory free API, which is limited to ~5,000 characters/day. "
                . "If you receive a quota_exceeded error, inform the user that the daily translation limit has been reached and suggest trying again tomorrow. "
                . "Use translation sparingly — prefer translating only what the user specifically asks for, not entire posts.\n\n";
        }

        if ($provider === 'ai_self') {
            return "## TRANSLATION\n"
                . "You have built-in translation capabilities. When the user asks you to translate content, "
                . "translate it directly using your own language abilities — no external tool is needed. "
                . "You can translate between any languages.\n\n";
        }

        return '';
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

        // Build dynamic instructions for the AI agent
        if (!empty($this->tools)) {
            $tool_names = array_map(function ($t) {
                return $t['name'];
            }, $this->get_tools());
            $site_name = \get_bloginfo('name');
            $manifest['instructions'] = "You have access to {$site_name} via AI Connect.\n\n"
                . "## AVAILABLE TOOLS\n"
                . "You have ONLY these tools: " . implode(', ', $tool_names) . ".\n"
                . "Do NOT claim to have any capabilities beyond the tools listed above.\n\n"
                . "## SEARCH TIPS\n"
                . "- Empty search → returns latest content\n"
                . "- No results? Try removing filters or broadening date range\n"
                . "- 'Recent content' without date → try last week, then last month\n\n"
                . $this->get_translation_instructions();
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
