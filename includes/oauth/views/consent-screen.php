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
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
            background: #f0f0f1;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 20px;
        }
        .consent-container {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            max-width: 500px;
            width: 100%;
            padding: 40px;
        }
        .consent-header {
            text-align: center;
            margin-bottom: 30px;
        }
        .consent-header h1 {
            font-size: 24px;
            color: #1d2327;
            margin-bottom: 8px;
        }
        .consent-header p {
            color: #646970;
            font-size: 14px;
        }
        .client-info {
            background: #f6f7f7;
            border-radius: 6px;
            padding: 20px;
            margin-bottom: 24px;
        }
        .client-name {
            font-weight: 600;
            font-size: 18px;
            color: #1d2327;
            margin-bottom: 12px;
        }
        .scopes-section h2 {
            font-size: 16px;
            color: #1d2327;
            margin-bottom: 16px;
        }
        .scope-list {
            list-style: none;
            margin-bottom: 24px;
        }
        .scope-item {
            display: flex;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #f0f0f1;
        }
        .scope-item:last-child {
            border-bottom: none;
        }
        .scope-icon {
            width: 20px;
            height: 20px;
            margin-right: 12px;
            color: #2271b1;
        }
        .scope-label {
            color: #1d2327;
            font-size: 14px;
        }
        .actions {
            display: flex;
            gap: 12px;
        }
        .btn {
            flex: 1;
            padding: 12px 24px;
            border: none;
            border-radius: 4px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-approve {
            background: #2271b1;
            color: white;
        }
        .btn-approve:hover {
            background: #135e96;
        }
        .btn-deny {
            background: #f0f0f1;
            color: #1d2327;
        }
        .btn-deny:hover {
            background: #dcdcde;
        }
        .warning {
            background: #fcf9e8;
            border-left: 4px solid #dba617;
            padding: 12px 16px;
            margin-top: 24px;
            border-radius: 4px;
        }
        .warning p {
            color: #646970;
            font-size: 13px;
            line-height: 1.5;
        }
    </style>
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
