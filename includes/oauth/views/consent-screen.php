<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php esc_html_e('Authorization Request', 'goldt-webmcp-bridge'); ?></title>
    <?php wp_head(); ?>
</head>
<body>
    <div class="consent-container">
        <div class="consent-header">
            <h1><?php esc_html_e('Authorization Request', 'goldt-webmcp-bridge'); ?></h1>
            <p><?php echo esc_html(get_bloginfo('name')); ?></p>
        </div>

        <div class="client-info">
            <div class="client-name"><?php echo esc_html($client->client_name); ?></div>
            <p style="color: #646970; font-size: 14px;">
                <?php esc_html_e('is requesting access to your account', 'goldt-webmcp-bridge'); ?>
            </p>
        </div>

        <div class="scopes-section">
            <h2><?php esc_html_e('This will allow the application to:', 'goldt-webmcp-bridge'); ?></h2>
            <ul class="scope-list">
                <?php foreach ($scopes as $goldtwmcp_scope): ?>
                    <li class="scope-item">
                        <svg class="scope-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                        </svg>
                        <span class="scope-label"><?php echo esc_html(goldtwmcp_get_scope_label($goldtwmcp_scope)); ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <form method="post">
            <?php wp_nonce_field('goldtwmcp_oauth_consent'); ?>
            <input type="hidden" name="client_id" value="<?php echo esc_attr($client_id); ?>">
            <input type="hidden" name="redirect_uri" value="<?php echo esc_attr($redirect_uri); ?>">
            <input type="hidden" name="response_type" value="<?php echo esc_attr($response_type); ?>">
            <input type="hidden" name="scope" value="<?php echo esc_attr($scope); ?>">
            <input type="hidden" name="state" value="<?php echo esc_attr($state); ?>">
            <input type="hidden" name="code_challenge" value="<?php echo esc_attr($code_challenge); ?>">
            <input type="hidden" name="code_challenge_method" value="<?php echo esc_attr($code_challenge_method); ?>">

            <div class="actions">
                <button type="submit" name="goldtwmcp_oauth_deny" class="btn btn-deny">
                    <?php esc_html_e('Deny', 'goldt-webmcp-bridge'); ?>
                </button>
                <button type="submit" name="goldtwmcp_oauth_approve" class="btn btn-approve">
                    <?php esc_html_e('Approve', 'goldt-webmcp-bridge'); ?>
                </button>
            </div>
        </form>

        <div class="warning">
            <p><?php esc_html_e('Only approve if you trust this application. It will have access to your account data based on the permissions above.', 'goldt-webmcp-bridge'); ?></p>
        </div>
    </div>
</body>
</html>
<?php
function goldtwmcp_get_scope_label($goldtwmcp_scope_name) {
    $goldtwmcp_labels = [
        'read' => __('Read your posts and content', 'goldt-webmcp-bridge'),
        'write' => __('Create and modify posts', 'goldt-webmcp-bridge'),
        'delete' => __('Delete posts', 'goldt-webmcp-bridge'),
        'manage_users' => __('Manage users', 'goldt-webmcp-bridge'),
    ];
    return isset($goldtwmcp_labels[$goldtwmcp_scope_name]) ? $goldtwmcp_labels[$goldtwmcp_scope_name] : ucfirst($goldtwmcp_scope_name);
}
?>
