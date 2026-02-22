<?php
namespace GoldtWebMCP\OAuth;

if (!defined('ABSPATH')) {
    exit;
}

class Scopes {
    
    public static function get_all_scopes() {
        return [
            'read' => [
                'label' => __('Read content', 'goldt-webmcp-bridge'),
                'description' => __('Read posts, pages, and other content', 'goldt-webmcp-bridge'),
            ],
            'write' => [
                'label' => __('Write content', 'goldt-webmcp-bridge'),
                'description' => __('Create and update posts and pages', 'goldt-webmcp-bridge'),
            ],
            'delete' => [
                'label' => __('Delete content', 'goldt-webmcp-bridge'),
                'description' => __('Delete posts and pages', 'goldt-webmcp-bridge'),
            ],
            'manage_users' => [
                'label' => __('Manage users', 'goldt-webmcp-bridge'),
                'description' => __('View and manage user accounts', 'goldt-webmcp-bridge'),
            ],
        ];
    }
    
    public static function get_scope_label($scope) {
        $scopes = self::get_all_scopes();
        return isset($scopes[$scope]['label']) ? $scopes[$scope]['label'] : ucfirst($scope);
    }
    
    public static function get_scope_description($scope) {
        $scopes = self::get_all_scopes();
        return isset($scopes[$scope]['description']) ? $scopes[$scope]['description'] : '';
    }
    
    public static function validate_scope($scope) {
        $scopes = self::get_all_scopes();
        return isset($scopes[$scope]);
    }
}
