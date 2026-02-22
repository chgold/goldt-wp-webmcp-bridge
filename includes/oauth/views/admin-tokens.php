<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap">
    <h1><?php esc_html_e('OAuth Access Tokens', 'ai-connect'); ?></h1>
    <p><?php esc_html_e('Manage OAuth access tokens that have been granted to AI applications.', 'ai-connect'); ?></p>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th><?php esc_html_e('Client', 'ai-connect'); ?></th>
                <th><?php esc_html_e('User', 'ai-connect'); ?></th>
                <th><?php esc_html_e('Scopes', 'ai-connect'); ?></th>
                <th><?php esc_html_e('Created', 'ai-connect'); ?></th>
                <th><?php esc_html_e('Expires', 'ai-connect'); ?></th>
                <th><?php esc_html_e('Actions', 'ai-connect'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($tokens)): ?>
                <tr>
                    <td colspan="6" style="text-align: center; padding: 20px;">
                        <?php esc_html_e('No active tokens found.', 'ai-connect'); ?>
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($tokens as $ai_connect_token): ?>
                    <tr>
                        <td><strong><?php echo esc_html($ai_connect_token->client_name); ?></strong></td>
                        <td><?php echo esc_html($ai_connect_token->user_login); ?></td>
                        <td>
                            <?php 
                            $ai_connect_scopes = json_decode($ai_connect_token->scopes, true);
                            echo esc_html(implode(', ', $ai_connect_scopes)); 
                            ?>
                        </td>
                        <td><?php echo esc_html(mysql2date('Y-m-d H:i', $ai_connect_token->created_at)); ?></td>
                        <td>
                            <?php 
                            $ai_connect_expires = strtotime($ai_connect_token->expires_at);
                            $ai_connect_now = time();
                            if ($ai_connect_expires < $ai_connect_now) {
                                echo '<span style="color: #dc3232;">' . esc_html__('Expired', 'ai-connect') . '</span>';
                            } else {
                                echo esc_html(mysql2date('Y-m-d H:i', $ai_connect_token->expires_at));
                            }
                            ?>
                        </td>
                        <td>
                            <form method="post" style="display: inline;">
                                <?php wp_nonce_field('ai_connect_revoke_token'); ?>
                                <input type="hidden" name="token_id" value="<?php echo esc_attr($ai_connect_token->id); ?>">
                                <button type="submit" name="revoke_token" class="button button-small" 
                                        onclick="return confirm('<?php esc_attr_e('Are you sure you want to revoke this token?', 'ai-connect'); ?>')">
                                    <?php esc_html_e('Revoke', 'ai-connect'); ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
