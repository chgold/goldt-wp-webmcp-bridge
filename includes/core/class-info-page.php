<?php
namespace GoldtWebMCP\Core;

if (!defined('ABSPATH')) {
    exit;
}

class Info_Page {

    public function init() {
        add_action('init', [$this, 'add_rewrite_rule']);
        add_filter('query_vars', [$this, 'register_query_var']);
        add_action('template_redirect', [$this, 'maybe_render']);
        add_action('admin_bar_menu', [$this, 'add_admin_bar_link'], 100);
    }

    public function add_rewrite_rule() {
        add_rewrite_rule('^ai-connect/?$', 'index.php?goldtwmcp_info_page=1', 'top');
    }

    public function register_query_var($vars) {
        $vars[] = 'goldtwmcp_info_page';
        return $vars;
    }

    public function maybe_render() {
        if (!get_query_var('goldtwmcp_info_page')) {
            return;
        }
        $this->render();
        exit;
    }

    public function add_admin_bar_link($wp_admin_bar) {
        if (!is_user_logged_in()) {
            return;
        }

        $wp_admin_bar->add_node([
            'id'    => 'goldtwmcp-info',
            'title' => 'AI Connect',
            'href'  => home_url('/ai-connect/'),
            'meta'  => [
                'title' => 'AI Connect — Connect AI agents to this site',
            ],
        ]);
    }

    private function render() {
        $site_name    = get_bloginfo('name');
        $manifest_url = rest_url('goldt-webmcp-bridge/v1/manifest');
        $oauth_url    = home_url('/?goldtwmcp_oauth_authorize=1&response_type=code&client_id=claude-ai&redirect_uri=urn:ietf:wg:oauth:2.0:oob&scope=read+write&code_challenge=PASTE_YOUR_CODE_CHALLENGE&code_challenge_method=S256');

        $is_logged_in   = is_user_logged_in();
        $current_user   = $is_logged_in ? wp_get_current_user() : null;
        $display_name   = $current_user ? esc_html($current_user->display_name) : '';

        $quick_prompt = 'Connect to ' . $site_name . ' using the WebMCP protocol.' . "\n"
            . 'Manifest URL: ' . $manifest_url . "\n"
            . 'For authentication, go to the OAuth authorize URL and follow the instructions.' . "\n"
            . 'After authorization you will receive a Bearer token to use with all API calls.';

        $clients = [
            ['id' => 'claude-ai',   'name' => 'Claude (Anthropic)'],
            ['id' => 'chatgpt',     'name' => 'ChatGPT (OpenAI)'],
            ['id' => 'gemini',      'name' => 'Gemini (Google)'],
            ['id' => 'copilot',     'name' => 'Microsoft Copilot'],
            ['id' => 'grok',        'name' => 'Grok (xAI)'],
            ['id' => 'deepseek',    'name' => 'DeepSeek AI'],
            ['id' => 'perplexity',  'name' => 'Perplexity AI'],
            ['id' => 'meta-ai',     'name' => 'Meta AI'],
        ];

        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Connect &mdash; <?php echo esc_html($site_name); ?></title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, sans-serif;
            background: #f0f2f5;
            color: #1a1a2e;
            min-height: 100vh;
        }

        .aic-header {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 60%, #0f3460 100%);
            color: #fff;
            padding: 32px 24px 28px;
            text-align: center;
        }

        .aic-header-logo {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 8px;
        }

        .aic-header-icon {
            width: 40px;
            height: 40px;
            background: #e94560;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .aic-header h1 {
            font-size: 28px;
            font-weight: 700;
            letter-spacing: -0.5px;
        }

        .aic-header-sub {
            font-size: 15px;
            opacity: 0.75;
            margin-top: 4px;
        }

        .aic-user-badge {
            display: inline-block;
            margin-top: 14px;
            background: rgba(255,255,255,0.12);
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: 20px;
            padding: 5px 16px;
            font-size: 13px;
            color: #e0e0e0;
        }

        .aic-login-notice {
            display: inline-block;
            margin-top: 14px;
            background: rgba(233,69,96,0.2);
            border: 1px solid rgba(233,69,96,0.4);
            border-radius: 20px;
            padding: 5px 16px;
            font-size: 13px;
            color: #ffb3be;
        }

        .aic-container {
            max-width: 860px;
            margin: 32px auto;
            padding: 0 16px 48px;
        }

        .aic-card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.07);
            padding: 28px 32px;
            margin-bottom: 24px;
        }

        .aic-card-title {
            font-size: 18px;
            font-weight: 700;
            color: #0f3460;
            margin-bottom: 18px;
            padding-bottom: 12px;
            border-bottom: 2px solid #f0f2f5;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .aic-label {
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            color: #6b7280;
            margin-bottom: 6px;
        }

        .aic-url-row {
            display: flex;
            align-items: stretch;
            gap: 0;
            margin-bottom: 18px;
            border-radius: 8px;
            overflow: hidden;
            border: 1px solid #e2e8f0;
        }

        .aic-url-input {
            flex: 1;
            font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
            font-size: 13px;
            padding: 10px 14px;
            border: none;
            background: #f8fafc;
            color: #1e293b;
            outline: none;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .aic-copy-btn {
            padding: 10px 18px;
            background: #0f3460;
            color: #fff;
            border: none;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            transition: background 0.15s ease;
            white-space: nowrap;
        }

        .aic-copy-btn:hover { background: #16213e; }
        .aic-copy-btn.copied { background: #16a34a; }

        .aic-prompt-wrap {
            position: relative;
        }

        .aic-prompt-textarea {
            width: 100%;
            font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
            font-size: 13px;
            line-height: 1.6;
            padding: 14px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            background: #f8fafc;
            color: #1e293b;
            resize: none;
            outline: none;
            height: 100px;
        }

        .aic-prompt-copy-btn {
            position: absolute;
            top: 10px;
            right: 10px;
            padding: 6px 14px;
            background: #0f3460;
            color: #fff;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
            transition: background 0.15s ease;
        }

        .aic-prompt-copy-btn:hover { background: #16213e; }
        .aic-prompt-copy-btn.copied { background: #16a34a; }

        .aic-platforms-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(170px, 1fr));
            gap: 10px;
        }

        .aic-platform-chip {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 14px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            background: #f8fafc;
            font-size: 13px;
            font-weight: 500;
            color: #374151;
        }

        .aic-platform-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #22c55e;
            flex-shrink: 0;
        }

        .aic-steps {
            counter-reset: aic-step;
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .aic-step {
            display: flex;
            gap: 16px;
            align-items: flex-start;
        }

        .aic-step-num {
            counter-increment: aic-step;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: #0f3460;
            color: #fff;
            font-size: 14px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .aic-step-content {
            padding-top: 4px;
        }

        .aic-step-content strong {
            display: block;
            font-size: 14px;
            color: #1a1a2e;
            margin-bottom: 2px;
        }

        .aic-step-content span {
            font-size: 13px;
            color: #6b7280;
        }

        .aic-footer {
            text-align: center;
            font-size: 12px;
            color: #9ca3af;
            margin-top: 12px;
        }

        .aic-footer a {
            color: #6b7280;
            text-decoration: none;
        }

        .aic-footer a:hover { text-decoration: underline; }
    </style>
</head>
<body>

<div class="aic-header">
    <div class="aic-header-logo">
        <div class="aic-header-icon">&#9881;</div>
        <h1>AI Connect</h1>
    </div>
    <div class="aic-header-sub"><?php echo esc_html($site_name); ?> &mdash; WebMCP Protocol Bridge</div>
    <?php if ($is_logged_in): ?>
        <div class="aic-user-badge">Logged in as <?php echo esc_html( $display_name ); ?></div>
    <?php else: ?>
        <div class="aic-login-notice">Login required to use AI agent connections</div>
    <?php endif; ?>
</div>

<div class="aic-container">

    <?php if (!$is_logged_in): ?>
    <div class="aic-card" style="border-left: 4px solid #e94560;">
        <p style="color: #6b7280; font-size: 14px;">
            You must be <a href="<?php echo esc_url(wp_login_url(home_url('/ai-connect/'))); ?>" style="color: #0f3460; font-weight: 600;">logged in</a> to connect AI agents to this site.
            The manifest URL is still publicly accessible for AI agent discovery.
        </p>
    </div>
    <?php endif; ?>

    <!-- Section 1: Connect Your AI Agent -->
    <div class="aic-card">
        <div class="aic-card-title">
            <span>&#128279;</span> Connect Your AI Agent
        </div>

        <div class="aic-label">Manifest URL</div>
        <div class="aic-url-row">
            <input id="aic-manifest-url" type="text" class="aic-url-input" value="<?php echo esc_attr($manifest_url); ?>" readonly>
            <button class="aic-copy-btn" onclick="aicCopy('aic-manifest-url', this)">Copy</button>
        </div>

        <div class="aic-label">OAuth Authorize URL</div>
        <div class="aic-url-row">
            <input id="aic-oauth-url" type="text" class="aic-url-input" value="<?php echo esc_attr(home_url('/?goldtwmcp_oauth_authorize=1')); ?>" readonly>
            <button class="aic-copy-btn" onclick="aicCopy('aic-oauth-url', this)">Copy</button>
        </div>

        <div class="aic-label" style="margin-top: 4px;">Quick Prompt &mdash; paste this into your AI agent to get started</div>
        <div class="aic-prompt-wrap">
            <textarea id="aic-quick-prompt" class="aic-prompt-textarea" readonly><?php echo esc_textarea($quick_prompt); ?></textarea>
            <button class="aic-prompt-copy-btn" onclick="aicCopy('aic-quick-prompt', this)">Copy</button>
        </div>
    </div>

    <!-- Section 2: Supported AI Platforms -->
    <div class="aic-card">
        <div class="aic-card-title">
            <span>&#129504;</span> Supported AI Platforms
        </div>
        <div class="aic-platforms-grid">
            <?php foreach ($clients as $client): ?>
            <div class="aic-platform-chip">
                <span class="aic-platform-dot"></span>
                <span><?php echo esc_html($client['name']); ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Section 3: How to Connect -->
    <div class="aic-card">
        <div class="aic-card-title">
            <span>&#128196;</span> How to Connect
        </div>
        <ol class="aic-steps">
            <li class="aic-step">
                <div class="aic-step-num">1</div>
                <div class="aic-step-content">
                    <strong>Copy the Manifest URL</strong>
                    <span>Use the copy button above to copy the manifest URL to your clipboard.</span>
                </div>
            </li>
            <li class="aic-step">
                <div class="aic-step-num">2</div>
                <div class="aic-step-content">
                    <strong>Paste into your AI agent's MCP / tool settings</strong>
                    <span>In Claude Desktop, ChatGPT Custom Actions, or any MCP-compatible client, add the manifest URL as a new tool source.</span>
                </div>
            </li>
            <li class="aic-step">
                <div class="aic-step-num">3</div>
                <div class="aic-step-content">
                    <strong>Approve access when prompted</strong>
                    <span>Your browser will open the OAuth authorization screen. Log in and click Authorize to grant the AI agent access.</span>
                </div>
            </li>
            <li class="aic-step">
                <div class="aic-step-num">4</div>
                <div class="aic-step-content">
                    <strong>Start chatting with your site's content</strong>
                    <span>The AI agent can now search posts, read pages, and interact with <?php echo esc_html($site_name); ?> on your behalf.</span>
                </div>
            </li>
        </ol>
    </div>

</div>

<div class="aic-footer">
    Powered by <a href="https://ai-connect.gold-t.co.il/" target="_blank" rel="noopener noreferrer">AI Connect</a> &mdash; WebMCP Protocol Bridge
</div>

<script>
function aicCopy(inputId, btn) {
    var el = document.getElementById(inputId);
    if (!el) return;
    var text = el.tagName === 'TEXTAREA' ? el.value : el.value;
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function() {
            aicFlashCopied(btn);
        }).catch(function() {
            aicFallbackCopy(el, btn);
        });
    } else {
        aicFallbackCopy(el, btn);
    }
}

function aicFallbackCopy(el, btn) {
    el.select();
    el.setSelectionRange(0, 99999);
    try {
        document.execCommand('copy');
        aicFlashCopied(btn);
    } catch (e) {}
}

function aicFlashCopied(btn) {
    var orig = btn.textContent;
    btn.textContent = 'Copied!';
    btn.classList.add('copied');
    setTimeout(function() {
        btn.textContent = orig;
        btn.classList.remove('copied');
    }, 2000);
}
</script>

</body>
</html>
        <?php
    }
}
