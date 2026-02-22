<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap">
    <h1><?php esc_html_e('OAuth Access Tokens', 'goldt-webmcp-bridge'); ?></h1>
    <p><?php esc_html_e('Manage OAuth access tokens that have been granted to AI applications.', 'goldt-webmcp-bridge'); ?></p>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th><?php esc_html_e('Client', 'goldt-webmcp-bridge'); ?></th>
                <th><?php esc_html_e('User', 'goldt-webmcp-bridge'); ?></th>
                <th><?php esc_html_e('Scopes', 'goldt-webmcp-bridge'); ?></th>
                <th><?php esc_html_e('Created', 'goldt-webmcp-bridge'); ?></th>
                <th><?php esc_html_e('Access Token Expires', 'goldt-webmcp-bridge'); ?></th>
                <th><?php esc_html_e('Refresh Token Expires', 'goldt-webmcp-bridge'); ?></th>
                <th><?php esc_html_e('Actions', 'goldt-webmcp-bridge'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($tokens)): ?>
                <tr>
                    <td colspan="7" style="text-align: center; padding: 20px;">
                        <?php esc_html_e('No active tokens found.', 'goldt-webmcp-bridge'); ?>
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($tokens as $goldtwmcp_token): ?>
                    <tr>
                        <td><strong><?php echo esc_html($goldtwmcp_token->client_name); ?></strong></td>
                        <td><?php echo esc_html($goldtwmcp_token->user_login); ?></td>
                        <td>
                            <?php 
                            $goldtwmcp_scopes = json_decode($goldtwmcp_token->scopes, true);
                            echo esc_html(implode(', ', $goldtwmcp_scopes)); 
                            ?>
                        </td>
                        <td><?php echo esc_html(mysql2date('Y-m-d H:i', $goldtwmcp_token->created_at)); ?></td>
                        <td>
                            <?php 
                            $goldtwmcp_expires = strtotime($goldtwmcp_token->expires_at . ' UTC');
                            $goldtwmcp_now = time();
                            if ($goldtwmcp_expires < $goldtwmcp_now) {
                                echo '<span style="color: #dc3232;">' . esc_html__('Expired', 'goldt-webmcp-bridge') . '</span>';
                            } else {
                                echo esc_html(mysql2date('Y-m-d H:i', $goldtwmcp_token->expires_at));
                            }
                            ?>
                        </td>
                        <td>
                            <?php 
                            if (!empty($goldtwmcp_token->refresh_token_expires_at)) {
                                $goldtwmcp_refresh_expires = strtotime($goldtwmcp_token->refresh_token_expires_at . ' UTC');
                                if ($goldtwmcp_refresh_expires < $goldtwmcp_now) {
                                    echo '<span style="color: #dc3232;">' . esc_html__('Expired', 'goldt-webmcp-bridge') . '</span>';
                                } else {
                                    echo esc_html(mysql2date('Y-m-d H:i', $goldtwmcp_token->refresh_token_expires_at));
                                }
                            } else {
                                echo '<span style="color: #999;">' . esc_html__('N/A', 'goldt-webmcp-bridge') . '</span>';
                            }
                            ?>
                        </td>
                        <td>
                            <form method="post" style="display: inline;">
                                <?php wp_nonce_field('goldtwmcp_revoke_token'); ?>
                                <input type="hidden" name="token_id" value="<?php echo esc_attr($goldtwmcp_token->id); ?>">
                                <button type="submit" name="revoke_token" class="button button-small" 
                                        onclick="return confirm('<?php esc_attr_e('Are you sure you want to revoke this token?', 'goldt-webmcp-bridge'); ?>')">
                                    <?php esc_html_e('Revoke', 'goldt-webmcp-bridge'); ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
