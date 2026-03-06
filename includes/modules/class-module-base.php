<?php
namespace GoldtWebMCP\Modules;

if (!defined('ABSPATH')) {
    exit;
}

abstract class Module_Base {
    
    protected $module_name;
    protected $module_version = '1.0.0';
    protected $tools = [];
    protected $manifest;
    
    public function __construct($manifest) {
        $this->manifest = $manifest;
        $this->register_tools();
    }
    
    abstract protected function register_tools();
    
    protected function register_tool($name, $config) {
        if (!isset($config['description']) || !isset($config['input_schema'])) {
            return false;
        }
        
        $full_name = $this->module_name . '.' . $name;
        
        $this->tools[$name] = [
            'name' => $full_name,
            'description' => $config['description'],
            'input_schema' => $config['input_schema'],
            'callback' => $config['callback'] ?? [$this, 'execute_' . $name],
            'required_scope' => $config['required_scope'] ?? 'read',
        ];
        
        if ($this->manifest) {
            $this->manifest->register_tool($full_name, [
                'description' => $config['description'],
                'input_schema' => $config['input_schema'],
            ]);
        }
        
        return true;
    }
    
    public function execute_tool($tool_name, $params = []) {
        if (!isset($this->tools[$tool_name])) {
            return new \WP_Error('tool_not_found', sprintf('Tool %s not found', $tool_name));
        }
        
        $tool = $this->tools[$tool_name];
        
        $validated = $this->validate_params($params, $tool['input_schema']);
        if (\is_wp_error($validated)) {
            return $validated;
        }
        
        if (!is_callable($tool['callback'])) {
            return new \WP_Error('tool_not_callable', sprintf('Tool %s is not callable', $tool_name));
        }
        
        try {
            return call_user_func($tool['callback'], $validated);
        } catch (\Exception $e) {
            return new \WP_Error('tool_execution_error', $e->getMessage());
        }
    }
    
    protected function validate_params($params, $schema) {
        if (!isset($schema['properties'])) {
            return $params;
        }
        
        $validated = [];
        
        foreach ($schema['properties'] as $key => $prop) {
            $required = isset($schema['required']) && in_array($key, $schema['required']);
            
            if ($required && !isset($params[$key])) {
                return new \WP_Error('missing_parameter', sprintf('Required parameter %s is missing', $key));
            }
            
            if (isset($params[$key])) {
                $value = $params[$key];
                
                if (isset($prop['type'])) {
                    $type_valid = $this->validate_type($value, $prop['type']);
                    if (!$type_valid) {
                        return new \WP_Error(
                            'invalid_type',
                            sprintf('Parameter %s must be of type %s', $key, $prop['type'])
                        );
                    }
                }
                
                $validated[$key] = $value;
            } elseif (isset($prop['default'])) {
                $validated[$key] = $prop['default'];
            }
        }
        
        return $validated;
    }
    
    protected function validate_type($value, $type) {
        switch ($type) {
            case 'string':
                return is_string($value);
            case 'integer':
                return is_int($value) || (is_string($value) && ctype_digit($value));
            case 'number':
                return is_numeric($value);
            case 'boolean':
                return is_bool($value) || in_array($value, ['true', 'false', 0, 1], true);
            case 'array':
                return is_array($value);
            case 'object':
                return is_object($value) || is_array($value);
            default:
                return true;
        }
    }
    
    public function get_tools() {
        return $this->tools;
    }
    
    public function get_module_name() {
        return $this->module_name;
    }
    
    protected function success_response($data, $message = null) {
        return [
            'success' => true,
            'data' => $data,
            'message' => $message,
        ];
    }
    
    protected function error_response($message, $code = 'error', $data = null) {
        return new \WP_Error($code, $message, $data);
    }
}
