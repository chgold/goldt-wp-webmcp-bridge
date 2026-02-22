<?php
namespace AIConnect\OAuth;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * OAuth 2.0 Authorization Server with PKCE
 */
class OAuth_Server {
    
    private $default_token_lifetime = 3600; // 1 hour
    private $default_code_lifetime = 600; // 10 minutes
    private $default_refresh_token_lifetime = 2592000; // 30 days
    
    /**
     * Generate authorization code
     */
    public function create_authorization_code($client_id, $user_id, $redirect_uri, $code_challenge, $code_challenge_method, $scopes) {
        global $wpdb;
        
        $code = $this->generate_token(128);
        $expires_at = gmdate('Y-m-d H:i:s', time() + $this->default_code_lifetime);
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- OAuth authorization code generation
        $inserted = $wpdb->insert(
            "{$wpdb->prefix}ai_connect_oauth_codes",
            [
                'code' => $code,
                'client_id' => $client_id,
                'user_id' => $user_id,
                'redirect_uri' => $redirect_uri,
                'code_challenge' => $code_challenge,
                'code_challenge_method' => $code_challenge_method,
                'scopes' => json_encode($scopes),
                'expires_at' => $expires_at
            ],
            ['%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s']
        );
        
        if (!$inserted) {
            return new \WP_Error('db_error', 'Failed to create authorization code');
        }
        
        return $code;
    }
    
    /**
     * Exchange authorization code for access token
     */
    public function exchange_code_for_token($code, $client_id, $code_verifier, $redirect_uri) {
        global $wpdb;
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- OAuth code exchange
        $auth_code = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ai_connect_oauth_codes WHERE code = %s",
            $code
        ));
        
        if (!$auth_code) {
            return new \WP_Error('invalid_grant', 'Authorization code not found');
        }
        
        if ($auth_code->used_at !== null) {
            return new \WP_Error('invalid_grant', 'Authorization code already used');
        }
        
        if (strtotime($auth_code->expires_at . ' UTC') < time()) {
            return new \WP_Error('invalid_grant', 'Authorization code expired');
        }
        
        if ($auth_code->client_id !== $client_id) {
            return new \WP_Error('invalid_client', 'Client ID mismatch');
        }
        
        if ($auth_code->redirect_uri !== $redirect_uri) {
            return new \WP_Error('invalid_grant', 'Redirect URI mismatch');
        }
        
        if (!$this->verify_pkce($code_verifier, $auth_code->code_challenge, $auth_code->code_challenge_method)) {
            return new \WP_Error('invalid_grant', 'PKCE verification failed');
        }
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Mark OAuth code as used
        $wpdb->update(
            "{$wpdb->prefix}ai_connect_oauth_codes",
            ['used_at' => gmdate('Y-m-d H:i:s')],
            ['id' => $auth_code->id],
            ['%s'],
            ['%d']
        );
        
        $token = $this->create_access_token(
            $auth_code->client_id,
            $auth_code->user_id,
            json_decode($auth_code->scopes, true)
        );
        
        return $token;
    }
    
    /**
     * Create access token with refresh token
     */
    public function create_access_token($client_id, $user_id, $scopes) {
        global $wpdb;
        
        $token = 'wpc_' . $this->generate_token(64);
        $refresh_token = 'wpr_' . $this->generate_token(64);
        $expires_at = gmdate('Y-m-d H:i:s', time() + $this->default_token_lifetime);
        $refresh_token_expires_at = gmdate('Y-m-d H:i:s', time() + $this->default_refresh_token_lifetime);
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- OAuth access token creation
        $inserted = $wpdb->insert(
            "{$wpdb->prefix}ai_connect_oauth_tokens",
            [
                'token' => $token,
                'refresh_token' => $refresh_token,
                'client_id' => $client_id,
                'user_id' => $user_id,
                'scopes' => json_encode($scopes),
                'expires_at' => $expires_at,
                'refresh_token_expires_at' => $refresh_token_expires_at
            ],
            ['%s', '%s', '%s', '%d', '%s', '%s', '%s']
        );
        
        if (!$inserted) {
            return new \WP_Error('db_error', 'Failed to create access token');
        }
        
        return [
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => $this->default_token_lifetime,
            'refresh_token' => $refresh_token,
            'refresh_token_expires_in' => $this->default_refresh_token_lifetime,
            'scope' => implode(' ', $scopes)
        ];
    }
    
    /**
     * Validate access token
     */
    public function validate_token($token) {
        global $wpdb;
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- OAuth token validation
        $token_data = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ai_connect_oauth_tokens WHERE token = %s",
            $token
        ));
        
        if (!$token_data) {
            return new \WP_Error('invalid_token', 'Token not found');
        }
        
        if ($token_data->revoked_at !== null) {
            return new \WP_Error('invalid_token', 'Token has been revoked');
        }
        
        if (strtotime($token_data->expires_at . ' UTC') < time()) {
            return new \WP_Error('invalid_token', 'Token expired');
        }
        
        return [
            'user_id' => $token_data->user_id,
            'client_id' => $token_data->client_id,
            'scopes' => json_decode($token_data->scopes, true)
        ];
    }
    
    /**
     * Exchange refresh token for new access token
     */
    public function exchange_refresh_token($refresh_token, $client_id) {
        global $wpdb;
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- OAuth refresh token exchange
        $token_data = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ai_connect_oauth_tokens WHERE refresh_token = %s",
            $refresh_token
        ));
        
        if (!$token_data) {
            return new \WP_Error('invalid_grant', 'Refresh token not found');
        }
        
        if ($token_data->client_id !== $client_id) {
            return new \WP_Error('invalid_client', 'Client ID mismatch');
        }
        
        if ($token_data->revoked_at !== null) {
            return new \WP_Error('invalid_grant', 'Refresh token has been revoked');
        }
        
        if (strtotime($token_data->refresh_token_expires_at . ' UTC') < time()) {
            return new \WP_Error('invalid_grant', 'Refresh token expired');
        }
        
        // Revoke the old access token and refresh token
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- OAuth token revocation
        $wpdb->update(
            "{$wpdb->prefix}ai_connect_oauth_tokens",
            ['revoked_at' => gmdate('Y-m-d H:i:s')],
            ['id' => $token_data->id],
            ['%s'],
            ['%d']
        );
        
        // Create new access token and refresh token
        $new_token = $this->create_access_token(
            $token_data->client_id,
            $token_data->user_id,
            json_decode($token_data->scopes, true)
        );
        
        return $new_token;
    }
    
    /**
     * Revoke access token
     */
    public function revoke_token($token) {
        global $wpdb;
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- OAuth token revocation
        $updated = $wpdb->update(
            "{$wpdb->prefix}ai_connect_oauth_tokens",
            ['revoked_at' => gmdate('Y-m-d H:i:s')],
            ['token' => $token],
            ['%s'],
            ['%s']
        );
        
        return $updated !== false;
    }
    
    /**
     * Verify PKCE code challenge
     */
    private function verify_pkce($code_verifier, $code_challenge, $method) {
        if ($method !== 'S256') {
            return false;
        }
        
        $computed_challenge = $this->base64url_encode(
            hash('sha256', $code_verifier, true)
        );
        
        return hash_equals($code_challenge, $computed_challenge);
    }
    
    /**
     * Validate client
     */
    public function validate_client($client_id) {
        global $wpdb;
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- OAuth client validation
        $client = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ai_connect_oauth_clients WHERE client_id = %s",
            $client_id
        ));
        
        return $client !== null;
    }
    
    /**
     * Validate redirect URI
     */
    public function validate_redirect_uri($client_id, $redirect_uri) {
        global $wpdb;
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- OAuth redirect URI validation
        $client = $wpdb->get_row($wpdb->prepare(
            "SELECT redirect_uris FROM {$wpdb->prefix}ai_connect_oauth_clients WHERE client_id = %s",
            $client_id
        ));
        
        if (!$client) {
            return false;
        }
        
        $allowed_uris = json_decode($client->redirect_uris, true);
        return in_array($redirect_uri, $allowed_uris, true);
    }
    
    /**
     * Validate scopes
     */
    public function validate_scopes($client_id, $requested_scopes) {
        global $wpdb;
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- OAuth scope validation
        $client = $wpdb->get_row($wpdb->prepare(
            "SELECT allowed_scopes FROM {$wpdb->prefix}ai_connect_oauth_clients WHERE client_id = %s",
            $client_id
        ));
        
        if (!$client) {
            return false;
        }
        
        $allowed_scopes = json_decode($client->allowed_scopes, true);
        
        foreach ($requested_scopes as $scope) {
            if (!in_array($scope, $allowed_scopes, true)) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Generate random token
     */
    private function generate_token($length = 64) {
        return bin2hex(random_bytes($length / 2));
    }
    
    /**
     * Base64 URL encoding (RFC 7636)
     */
    private function base64url_encode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
