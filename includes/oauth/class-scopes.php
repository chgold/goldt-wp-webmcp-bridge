<?php
namespace AIConnect\OAuth;

if (!defined('ABSPATH')) {
    exit;
}

class Scopes {
    
    public static function get_all_scopes() {
        return [
            'read' => [
                'label' => __('Read content', 'ai-connect'),
                'description' => __('Read posts, pages, and other content', 'ai-connect'),
            ],
            'write' => [
                'label' => __('Write content', 'ai-connect'),
                'description' => __('Create and update posts and pages', 'ai-connect'),
            ],
            'delete' => [
                'label' => __('Delete content', 'ai-connect'),
                'description' => __('Delete posts and pages', 'ai-connect'),
            ],
            'manage_users' => [
                'label' => __('Manage users', 'ai-connect'),
                'description' => __('View and manage user accounts', 'ai-connect'),
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
