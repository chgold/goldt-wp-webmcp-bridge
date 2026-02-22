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
    <title><?php esc_html_e('Authorization Successful', 'ai-connect'); ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #f0f0f1;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 20px;
        }
        .success-container {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            max-width: 500px;
            width: 100%;
            padding: 40px;
            text-align: center;
        }
        .success-icon {
            width: 64px;
            height: 64px;
            margin: 0 auto 24px;
            color: #00a32a;
        }
        h1 {
            font-size: 24px;
            color: #1d2327;
            margin-bottom: 12px;
        }
        .instruction {
            color: #646970;
            font-size: 14px;
            margin-bottom: 24px;
            line-height: 1.5;
        }
        .code-container {
            background: #f6f7f7;
            border: 2px solid #2271b1;
            border-radius: 6px;
            padding: 20px;
            margin-bottom: 24px;
        }
        .code-label {
            font-size: 12px;
            color: #646970;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }
        .code-value {
            font-family: 'Courier New', monospace;
            font-size: 18px;
            font-weight: 600;
            color: #1d2327;
            word-break: break-all;
            user-select: all;
        }
        .copy-btn {
            background: #2271b1;
            color: white;
            border: none;
            border-radius: 4px;
            padding: 12px 24px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            margin-bottom: 16px;
            transition: background 0.2s;
        }
        .copy-btn:hover {
            background: #135e96;
        }
        .copy-success {
            color: #00a32a;
            font-size: 14px;
            display: none;
        }
        .warning {
            background: #fcf9e8;
            border-left: 4px solid #dba617;
            padding: 12px 16px;
            border-radius: 4px;
            text-align: left;
        }
        .warning p {
            color: #646970;
            font-size: 13px;
            line-height: 1.5;
        }
    </style>
</head>
<body>
    <div class="success-container">
        <svg class="success-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
        </svg>

        <h1><?php esc_html_e('Authorization Successful', 'ai-connect'); ?></h1>
        <p class="instruction">
            <?php esc_html_e('Copy this authorization code and paste it back to the AI application:', 'ai-connect'); ?>
        </p>

        <div class="code-container">
            <div class="code-label"><?php esc_html_e('Authorization Code', 'ai-connect'); ?></div>
            <div class="code-value" id="authCode"><?php echo esc_html($code); ?></div>
        </div>

        <button class="copy-btn" onclick="copyCode()">
            <?php esc_html_e('Copy Code', 'ai-connect'); ?>
        </button>
        <div class="copy-success" id="copySuccess">
            ✓ <?php esc_html_e('Code copied to clipboard!', 'ai-connect'); ?>
        </div>

        <div class="warning">
            <p><strong><?php esc_html_e('Important:', 'ai-connect'); ?></strong> <?php esc_html_e('This code expires in 10 minutes and can only be used once.', 'ai-connect'); ?></p>
        </div>
    </div>

    <script>
        function copyCode() {
            const code = document.getElementById('authCode').textContent;
            navigator.clipboard.writeText(code).then(() => {
                const btn = document.querySelector('.copy-btn');
                const success = document.getElementById('copySuccess');
                btn.style.display = 'none';
                success.style.display = 'block';
                setTimeout(() => {
                    btn.style.display = 'block';
                    success.style.display = 'none';
                }, 3000);
            });
        }
    </script>
</body>
</html>
