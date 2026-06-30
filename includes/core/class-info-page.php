<?php
/**
 * Info Page class for displaying AI Connect information page.
 *
 * @package GoldtWebMCP\Core
 */

namespace GoldtWebMCP\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Info_Page class.
 *
 * Renders the public AI Connect information page at /ai-connect/
 */
class Info_Page {

	/**
	 * Initialize the Info Page.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'init', array( $this, 'add_rewrite_rule' ) );
		add_filter( 'query_vars', array( $this, 'register_query_var' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render' ) );
		add_action( 'admin_bar_menu', array( $this, 'add_admin_bar_link' ), 100 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Enqueue info page CSS and JavaScript assets.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		if ( ! get_query_var( 'goldtwmcp_info_page' ) ) {
			return;
		}
		wp_enqueue_style(
			'goldtwmcp-info-page',
			GOLDTWMCP_URL . 'assets/css/info-page.css',
			array(),
			GOLDTWMCP_VERSION
		);
		wp_enqueue_script(
			'goldtwmcp-info-page',
			GOLDTWMCP_URL . 'assets/js/info-page.js',
			array(),
			GOLDTWMCP_VERSION,
			true
		);
	}

	/**
	 * Add rewrite rule for /ai-connect/ URL.
	 *
	 * @return void
	 */
	public function add_rewrite_rule() {
		add_rewrite_rule( '^ai-connect/?$', 'index.php?goldtwmcp_info_page=1', 'top' );
	}

	/**
	 * Register query variable for info page.
	 *
	 * @param array $vars Query variables.
	 * @return array
	 */
	public function register_query_var( $vars ) {
		$vars[] = 'goldtwmcp_info_page';
		return $vars;
	}

	/**
	 * Render info page if query var is set.
	 *
	 * @return void
	 */
	public function maybe_render() {
		if ( ! get_query_var( 'goldtwmcp_info_page' ) ) {
			return;
		}
		$this->render();
		exit;
	}

	/**
	 * Add admin bar link for logged-in users.
	 *
	 * @param WP_Admin_Bar $wp_admin_bar Admin bar object.
	 * @return void
	 */
	public function add_admin_bar_link( $wp_admin_bar ) {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$wp_admin_bar->add_node(
			array(
				'id'    => 'goldtwmcp-info',
				'title' => 'AI Connect',
				'href'  => home_url( '/ai-connect/' ),
				'meta'  => array(
					'title' => 'AI Connect — Connect AI agents to this site',
				),
			)
		);
	}

	/**
	 * Render the info page HTML.
	 *
	 * @return void
	 */
	private function render() {
		$site_name    = get_bloginfo( 'name' );
		$manifest_url = rest_url( 'goldt-webmcp-bridge/v1/manifest' );
		$oauth_url    = home_url( '/?goldtwmcp_oauth_authorize=1&response_type=code&client_id=claude-ai&redirect_uri=urn:ietf:wg:oauth:2.0:oob&scope=read+write&code_challenge=PASTE_YOUR_CODE_CHALLENGE&code_challenge_method=S256' );

		$is_logged_in = is_user_logged_in();
		$current_user = $is_logged_in ? wp_get_current_user() : null;
		$display_name = $current_user ? esc_html( $current_user->display_name ) : '';

		$quick_prompt = 'Connect to ' . $site_name . ' using the WebMCP protocol.' . "\n"
			. 'Manifest URL: ' . $manifest_url . "\n"
			. 'For authentication, go to the OAuth authorize URL and follow the instructions.' . "\n"
			. 'After authorization you will receive a Bearer token to use with all API calls.';

		$clients = array(
			array(
				'id'   => 'claude-ai',
				'name' => 'Claude (Anthropic)',
			),
			array(
				'id'   => 'chatgpt',
				'name' => 'ChatGPT (OpenAI)',
			),
			array(
				'id'   => 'gemini',
				'name' => 'Gemini (Google)',
			),
			array(
				'id'   => 'copilot',
				'name' => 'Microsoft Copilot',
			),
			array(
				'id'   => 'grok',
				'name' => 'Grok (xAI)',
			),
			array(
				'id'   => 'deepseek',
				'name' => 'DeepSeek AI',
			),
			array(
				'id'   => 'perplexity',
				'name' => 'Perplexity AI',
			),
			array(
				'id'   => 'meta-ai',
				'name' => 'Meta AI',
			),
			array(
				'id'   => 'webmcp-master',
				'name' => 'WebMCP Master (webmcp-master.ai)',
			),
		);

		?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>AI Connect &mdash; <?php echo esc_html( $site_name ); ?></title>
		<?php wp_head(); ?>
</head>
<body>

<div class="aic-header">
	<div class="aic-header-logo">
		<div class="aic-header-icon">&#9881;</div>
		<h1>AI Connect</h1>
	</div>
	<div class="aic-header-sub"><?php echo esc_html( $site_name ); ?> &mdash; WebMCP Protocol Bridge</div>
		<?php if ( $is_logged_in ) : ?>
		<div class="aic-user-badge">Logged in as <?php echo esc_html( $display_name ); ?></div>
	<?php else : ?>
		<div class="aic-login-notice">Login required to use AI agent connections</div>
	<?php endif; ?>
</div>

<div class="aic-container">

		<?php if ( ! $is_logged_in ) : ?>
	<div class="aic-card" style="border-left: 4px solid #e94560;">
		<p style="color: #6b7280; font-size: 14px;">
			You must be <a href="<?php echo esc_url( wp_login_url( home_url( '/ai-connect/' ) ) ); ?>" style="color: #0f3460; font-weight: 600;">logged in</a> to connect AI agents to this site.
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
			<input id="aic-manifest-url" type="text" class="aic-url-input" value="<?php echo esc_attr( $manifest_url ); ?>" readonly>
			<button class="aic-copy-btn" onclick="aicCopy('aic-manifest-url', this)">Copy</button>
		</div>

		<div class="aic-label" style="margin-top: 16px;">OAuth Authorize URL</div>
		<div class="aic-url-row">
			<input id="aic-oauth-url" type="text" class="aic-url-input" value="<?php echo esc_attr( $oauth_url ); ?>" readonly>
			<button class="aic-copy-btn" onclick="aicCopy('aic-oauth-url', this)">Copy</button>
		</div>

		<?php if ( $is_logged_in ) : ?>
		<!-- Personalized prompt generator (logged-in users only) -->
		<div style="margin-top:20px;">
			<div class="aic-label">&#129302; AI Connection Prompt &mdash; one-click setup with your personal token</div>
			<p style="font-size:13px;color:#9ca3af;margin:4px 0 12px;">
				Generates a ready-to-paste prompt for Claude, ChatGPT, Gemini, or any WebMCP-compatible client. Includes your Bearer token so the AI connects instantly — no OAuth dance required.
			</p>

			<div class="aic-gen-controls">
				<div class="aic-gen-field">
					<label for="aic-gen-agent">AI agent</label>
					<select id="aic-gen-agent">
						<option value="" disabled selected>Loading…</option>
					</select>
				</div>
				<div class="aic-gen-field">
					<label for="aic-gen-scope">Access level</label>
					<select id="aic-gen-scope">
						<option value="" disabled selected>Loading…</option>
					</select>
				</div>
				<div class="aic-gen-field">
					<label for="aic-gen-template">Format</label>
					<select id="aic-gen-template">
						<option value="">Recommended for agent</option>
						<option value="mcp">MCP (Claude Desktop / WebMCP)</option>
						<option value="rest">HTTP REST (curl / direct calls)</option>
					</select>
				</div>
			</div>

			<p id="aic-gen-scope-hint" style="font-size:12px;color:#9ca3af;margin:0 0 12px;"></p>

			<button id="aic-gen-btn" class="aic-prompt-copy-btn" style="margin-bottom:12px;" onclick="aicGeneratePrompt(this)">&#9889; Generate AI Prompt</button>
			<button id="aic-regen-btn" class="aic-prompt-copy-btn" style="margin-bottom:12px;display:none;background:#4b5563;" onclick="aicGeneratePrompt(this)">&#128260; Regenerate (new token)</button>

			<div id="aic-prompt-result" style="display:none;">
				<textarea id="aic-generated-prompt" class="aic-prompt-textarea" readonly rows="18"></textarea>
				<button class="aic-prompt-copy-btn" style="margin-top:8px;" onclick="aicCopy('aic-generated-prompt',this)">&#128203; Copy Prompt</button>
			</div>
			<div id="aic-gen-error" style="display:none;color:#f87171;font-size:13px;margin-top:8px;"></div>
		</div>
		<style>
			.aic-gen-controls { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:12px; margin-bottom:12px; }
			.aic-gen-field { display:flex; flex-direction:column; gap:4px; }
			.aic-gen-field label { font-size:12px; font-weight:600; color:#9ca3af; }
			.aic-gen-field select { padding:8px 10px; background:#1f2937; color:#f3f4f6; border:1px solid #374151; border-radius:6px; font-size:13px; cursor:pointer; }
			.aic-gen-field select:focus { outline:none; border-color:#0f3460; }
		</style>
		<?php else : ?>
		<div class="aic-label" style="margin-top: 16px;">Quick Prompt &mdash; paste this into your AI agent to get started</div>
		<div class="aic-prompt-wrap">
			<textarea id="aic-quick-prompt" class="aic-prompt-textarea" readonly><?php echo esc_textarea( $quick_prompt ); ?></textarea>
			<button class="aic-prompt-copy-btn" onclick="aicCopy('aic-quick-prompt', this)">Copy</button>
		</div>
		<?php endif; ?>
	</div>

	<!-- Section 2: Supported AI Platforms -->
	<div class="aic-card">
		<div class="aic-card-title">
			<span>&#129504;</span> Supported AI Platforms
		</div>
		<div class="aic-platforms-grid">
			<?php foreach ( $clients as $client ) : ?>
			<div class="aic-platform-chip">
				<span class="aic-platform-dot"></span>
				<span><?php echo esc_html( $client['name'] ); ?></span>
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
					<span>The AI agent can now search posts, read pages, and interact with <?php echo esc_html( $site_name ); ?> on your behalf.</span>
				</div>
			</li>
		</ol>
	</div>

</div>

		<?php wp_footer(); ?>
<script>
(function(){
	var GEN_URL  = '<?php echo esc_js( rest_url( 'goldt-webmcp-bridge/v1/generate-prompt' ) ); ?>';
	var OPTS_URL = '<?php echo esc_js( rest_url( 'goldt-webmcp-bridge/v1/prompt-options' ) ); ?>';
	var NONCE    = '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>';
	var presetsCache = {};
	var loggedIn = <?php echo $is_logged_in ? 'true' : 'false'; ?>;
	if (!loggedIn) return;

	function el(id){ return document.getElementById(id); }

	function loadOptions() {
		fetch(OPTS_URL, { credentials: 'same-origin', headers: { 'X-WP-Nonce': NONCE } })
			.then(function(r){ return r.json(); })
			.then(function(data){
				var agentSel = el('aic-gen-agent');
				var scopeSel = el('aic-gen-scope');
				if (!agentSel || !scopeSel) return;
				agentSel.innerHTML = '';
				(data.clients || []).forEach(function(c){
					var o = document.createElement('option');
					o.value = c.id; o.textContent = c.label;
					o.dataset.template = c.default_template;
					if (c.id === 'claude-ai') o.selected = true;
					agentSel.appendChild(o);
				});
				scopeSel.innerHTML = '';
				(data.presets || []).forEach(function(p){
					var o = document.createElement('option');
					o.value = p.key; o.textContent = p.label;
					if (p.key === 'read_write') o.selected = true;
					scopeSel.appendChild(o);
					presetsCache[p.key] = p.description || '';
				});
				updateScopeHint();
			})
			.catch(function(){ /* leave UI as-is; generate still works with defaults */ });
	}

	function updateScopeHint() {
		var scopeSel = el('aic-gen-scope');
		var hint = el('aic-gen-scope-hint');
		if (!scopeSel || !hint) return;
		hint.textContent = presetsCache[scopeSel.value] || '';
	}

	window.aicGeneratePrompt = function(btn) {
		btn.disabled = true;
		btn.textContent = '⏳ Generating...';
		var err = el('aic-gen-error'); err.style.display = 'none';

		var payload = {
			client_id:    el('aic-gen-agent') ? el('aic-gen-agent').value || 'claude-ai' : 'claude-ai',
			scope_preset: el('aic-gen-scope') ? el('aic-gen-scope').value || 'read_write' : 'read_write'
		};
		var tpl = el('aic-gen-template') ? el('aic-gen-template').value : '';
		if (tpl) payload.template = tpl;

		fetch(GEN_URL, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
			body: JSON.stringify(payload)
		})
		.then(function(r){ return r.json(); })
		.then(function(data){
			if (data && data.prompt) {
				var ta = el('aic-generated-prompt');
				ta.value = data.prompt;
				ta.rows = Math.min(data.prompt.split('\n').length + 2, 40);
				el('aic-prompt-result').style.display = 'block';
				var genBtn = el('aic-gen-btn'); if (genBtn) genBtn.style.display = 'none';
				var regenBtn = el('aic-regen-btn');
				if (regenBtn) { regenBtn.style.display = ''; regenBtn.disabled = false; regenBtn.innerHTML = '&#128260; Regenerate (new token)'; }
			} else {
				err.textContent = (data && data.message) ? data.message : 'Failed to generate prompt.';
				err.style.display = 'block';
				btn.disabled = false;
				btn.innerHTML = btn.id === 'aic-regen-btn' ? '&#128260; Regenerate (new token)' : '&#9889; Generate AI Prompt';
			}
		})
		.catch(function(e){
			err.textContent = 'Error: ' + e.message;
			err.style.display = 'block';
			btn.disabled = false;
			btn.innerHTML = btn.id === 'aic-regen-btn' ? '&#128260; Regenerate (new token)' : '&#9889; Generate AI Prompt';
		});
	};

	document.addEventListener('DOMContentLoaded', function(){
		loadOptions();
		var scopeSel = el('aic-gen-scope');
		if (scopeSel) scopeSel.addEventListener('change', updateScopeHint);
	});
})();
</script>
</body>
</html>
		<?php
	}
}
