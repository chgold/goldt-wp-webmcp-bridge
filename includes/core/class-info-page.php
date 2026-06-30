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
		<div class="aic-header-meta">
			<span class="aic-user-badge">Logged in as <?php echo esc_html( $display_name ); ?></span>
			<button id="aic-tokens-toggle" type="button" class="aic-tokens-toggle" onclick="aicToggleTokens(this)" aria-expanded="false">
				&#128274; My AI Tokens <span id="aic-tokens-count" class="aic-tokens-count"></span>
			</button>
		</div>
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

		<?php if ( $is_logged_in ) : ?>
	<!-- Section 1 (THE HERO): Generate AI Prompt -->
	<div class="aic-card aic-hero-card">
		<div class="aic-card-title">
			<span>&#129302;</span> Connect your AI Agent
		</div>
		<p style="font-size:13px;color:#9ca3af;margin:0 0 16px;">
			Click the button below to generate a complete connection prompt — Bearer token, refresh token, and the full list of tools you can call. Paste it into Claude, ChatGPT, Gemini, or any AI agent. <strong>Permissions automatically match your WordPress role.</strong>
		</p>

		<button id="aic-gen-btn" class="aic-prompt-copy-btn aic-gen-main-btn" onclick="aicGeneratePrompt(this)">&#9889; Generate AI Prompt</button>

		<div id="aic-prompt-result" style="display:none;margin-top:16px;">
			<textarea id="aic-generated-prompt" class="aic-prompt-textarea" readonly rows="22"></textarea>
			<div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap;">
				<button class="aic-prompt-copy-btn" onclick="aicCopy('aic-generated-prompt',this)">&#128203; Copy full prompt</button>
				<button id="aic-regen-btn" class="aic-prompt-copy-btn" style="background:#4b5563;" onclick="aicGeneratePrompt(this)">&#128260; Regenerate (new token)</button>
			</div>
		</div>
		<div id="aic-gen-error" style="display:none;color:#f87171;font-size:13px;margin-top:8px;"></div>
	</div>

	<!-- Hidden tokens panel — opens from the header button -->
	<div id="aic-tokens-body" class="aic-card aic-tokens-card" style="display:none;">
		<div class="aic-card-title">
			<span>&#128274;</span> My AI Tokens
		</div>
		<p style="font-size:13px;color:#9ca3af;margin:0 0 12px;">
			Each prompt you generate creates a personal Bearer token. Revoke any token here to instantly cut off the AI agent that holds it.
		</p>

		<div class="aic-tokens-toolbar">
			<div class="aic-tokens-filter-wrap">
				<label for="aic-tokens-filter">Filter</label>
				<select id="aic-tokens-filter">
					<option value="all">All my tokens</option>
					<option value="active">Active (in use)</option>
					<option value="unused">Issued but unused</option>
					<option value="inactive">Inactive 30+ days</option>
					<option value="renewable">Access expired, refresh valid</option>
					<option value="expired">Fully expired</option>
					<option value="revoked">Revoked</option>
				</select>
			</div>
			<div class="aic-tokens-actions">
				<button class="aic-prompt-copy-btn" style="background:#4b5563;" onclick="aicLoadTokens()">&#128260; Refresh</button>
				<button class="aic-prompt-copy-btn" style="background:#b91c1c;" onclick="aicRevokeAllTokens()">&#9888;&#65039; Revoke all</button>
			</div>
		</div>

		<div id="aic-tokens-loading" style="margin-top:12px;color:#9ca3af;font-size:13px;display:none;">Loading…</div>
		<div id="aic-tokens-empty" style="margin-top:12px;color:#9ca3af;font-size:13px;display:none;">No tokens match this filter.</div>
		<div id="aic-tokens-list" style="margin-top:12px;"></div>
		<div id="aic-tokens-error" style="margin-top:8px;color:#f87171;font-size:13px;display:none;"></div>
	</div>

	<!-- Section 2: For advanced users (manifest URL only) -->
	<div class="aic-card aic-advanced-card">
		<details>
			<summary>Manual setup (for AI agents that don't accept generated prompts)</summary>
			<div style="margin-top:14px;">
				<div class="aic-label">Manifest URL — give this to the AI if it asks for one</div>
				<div class="aic-url-row">
					<input id="aic-manifest-url" type="text" class="aic-url-input" value="<?php echo esc_attr( $manifest_url ); ?>" readonly>
					<button class="aic-copy-btn" onclick="aicCopy('aic-manifest-url', this)">Copy</button>
				</div>
				<p style="font-size:12px;color:#9ca3af;margin-top:10px;">
					The AI agent will read this manifest, then send you to an OAuth login page in your browser. Approve there, and the agent receives its own token automatically.
				</p>
			</div>
		</details>
	</div>
		<?php else : ?>
	<!-- Logged-out fallback: manifest URL only, no generator -->
	<div class="aic-card">
		<div class="aic-card-title">
			<span>&#128279;</span> Connect Your AI Agent
		</div>
		<div class="aic-label">Manifest URL</div>
		<div class="aic-url-row">
			<input id="aic-manifest-url" type="text" class="aic-url-input" value="<?php echo esc_attr( $manifest_url ); ?>" readonly>
			<button class="aic-copy-btn" onclick="aicCopy('aic-manifest-url', this)">Copy</button>
		</div>
		<div class="aic-label" style="margin-top: 16px;">Quick Prompt &mdash; paste this into your AI agent to get started</div>
		<div class="aic-prompt-wrap">
			<textarea id="aic-quick-prompt" class="aic-prompt-textarea" readonly><?php echo esc_textarea( $quick_prompt ); ?></textarea>
			<button class="aic-prompt-copy-btn" onclick="aicCopy('aic-quick-prompt', this)">Copy</button>
		</div>
	</div>
	<style>
		.aic-header-meta { display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-top:8px; }
		.aic-tokens-toggle { display:inline-flex; align-items:center; gap:6px; padding:6px 12px; background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.15); color:#f3f4f6; font-size:13px; font-weight:500; border-radius:6px; cursor:pointer; }
		.aic-tokens-toggle:hover { background:rgba(255,255,255,0.14); }
		.aic-tokens-toggle[aria-expanded="true"] { background:#1f2937; border-color:#374151; }
		.aic-tokens-count { color:#9ca3af; font-size:12px; font-weight:400; margin-left:2px; }
		.aic-hero-card { border-left:4px solid #10b981; }
		.aic-gen-main-btn { font-size:15px; padding:12px 24px; }
		.aic-advanced-card details summary { cursor:pointer; color:#9ca3af; font-size:13px; padding:4px 0; }
		.aic-advanced-card details summary:hover { color:#f3f4f6; }
		.aic-advanced-card details[open] summary { color:#f3f4f6; }
		.aic-tokens-card { border-left:4px solid #6366f1; }
		.aic-tokens-toolbar { display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end; }
		.aic-tokens-filter-wrap { display:flex; flex-direction:column; gap:4px; flex:1; min-width:160px; }
		.aic-tokens-filter-wrap label { font-size:12px; font-weight:600; color:#9ca3af; }
		.aic-tokens-filter-wrap select { padding:8px 10px; background:#1f2937; color:#f3f4f6; border:1px solid #374151; border-radius:6px; font-size:13px; cursor:pointer; }
		.aic-tokens-actions { display:flex; gap:8px; align-items:flex-end; flex-wrap:wrap; }
		.aic-token-row { display:grid; grid-template-columns:1fr auto; gap:12px; padding:10px 12px; background:#1f2937; border:1px solid #374151; border-radius:6px; margin-bottom:8px; }
		.aic-token-row.aic-token-revoked { opacity:0.55; }
		.aic-token-row.aic-token-expired { opacity:0.7; }
		.aic-token-info { display:flex; flex-direction:column; gap:4px; font-size:13px; color:#f3f4f6; }
		.aic-token-info .aic-token-line { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
		.aic-token-info code { background:#0f172a; padding:2px 6px; border-radius:3px; font-size:12px; color:#cbd5e1; }
		.aic-token-meta { color:#9ca3af; font-size:12px; }
		.aic-state-badge { padding:2px 8px; border-radius:10px; font-size:11px; font-weight:600; text-transform:uppercase; }
		.aic-state-active { background:#065f46; color:#a7f3d0; }
		.aic-state-expired { background:#78350f; color:#fde68a; }
		.aic-state-revoked { background:#7f1d1d; color:#fecaca; }
		.aic-token-actions { display:flex; align-items:center; }
		.aic-token-revoke-btn { padding:6px 12px; background:#dc2626; color:white; border:none; border-radius:4px; cursor:pointer; font-size:12px; }
		.aic-token-revoke-btn:hover { background:#b91c1c; }
		.aic-token-revoke-btn:disabled { opacity:0.5; cursor:not-allowed; }
	</style>
	<?php endif; ?>

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
	var NONCE    = '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>';
	var loggedIn = <?php echo $is_logged_in ? 'true' : 'false'; ?>;
	if (!loggedIn) return;

	function el(id){ return document.getElementById(id); }

	window.aicGeneratePrompt = function(btn) {
		btn.disabled = true;
		btn.textContent = '⏳ Generating...';
		var err = el('aic-gen-error'); err.style.display = 'none';

		fetch(GEN_URL, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
			body: '{}'
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
				// Refresh tokens list so the user sees the freshly issued token.
				aicLoadTokens();
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

	// ====== My AI Tokens ============================================
	var TOKENS_URL = '<?php echo esc_js( rest_url( 'goldt-webmcp-bridge/v1/my-tokens' ) ); ?>';

	function fmtTime(ts) {
		if (!ts) return '—';
		var d = new Date(ts * 1000);
		return d.toLocaleString();
	}
	function escapeHtml(s) {
		return (s || '').replace(/[&<>"']/g, function(c){
			return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c];
		});
	}

	function updateTokensCount() {
		fetch(TOKENS_URL + '?status=active', {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': NONCE }
		})
		.then(function(r){ return r.json(); })
		.then(function(data){
			var n = (data && data.tokens) ? data.tokens.length : 0;
			var c = el('aic-tokens-count');
			if (c) c.textContent = n > 0 ? '(' + n + ')' : '';
		})
		.catch(function(){ /* silent */ });
	}

	window.aicToggleTokens = function(btn) {
		var body = el('aic-tokens-body');
		if (!body) return;
		var open = body.style.display !== 'none';
		body.style.display = open ? 'none' : 'block';
		btn.setAttribute('aria-expanded', open ? 'false' : 'true');
		if (!open) {
			aicLoadTokens(); // lazy-load on open
			body.scrollIntoView({ behavior: 'smooth', block: 'start' });
		}
	};

	window.aicLoadTokens = function() {
		var filterSel = el('aic-tokens-filter');
		var status = filterSel ? filterSel.value : 'all';
		var list = el('aic-tokens-list');
		var empty = el('aic-tokens-empty');
		var loading = el('aic-tokens-loading');
		var errBox = el('aic-tokens-error');
		if (!list) return;
		errBox.style.display = 'none';
		empty.style.display = 'none';
		loading.style.display = '';
		list.innerHTML = '';

		fetch(TOKENS_URL + '?status=' + encodeURIComponent(status), {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': NONCE }
		})
		.then(function(r){ return r.json(); })
		.then(function(data){
			loading.style.display = 'none';
			var tokens = (data && data.tokens) || [];
			if (!tokens.length) {
				empty.style.display = '';
				return;
			}
			var html = '';
			tokens.forEach(function(t){
				var stateClass = 'aic-state-' + t.state;
				var rowClass = 'aic-token-' + t.state;
				var disabled = (t.state === 'revoked') ? 'disabled' : '';
				var btnLabel = (t.state === 'revoked') ? 'Revoked' : 'Revoke';
				html += '<div class="aic-token-row ' + rowClass + '" data-id="' + t.id + '">';
				html +=   '<div class="aic-token-info">';
				html +=     '<div class="aic-token-line">';
				html +=       '<span class="aic-state-badge ' + stateClass + '">' + escapeHtml(t.state) + '</span>';
				html +=       '<code>' + escapeHtml(t.token_prefix) + '…</code>';
				html +=       '<span class="aic-token-meta">scope: ' + escapeHtml(t.scope) + '</span>';
				html +=     '</div>';
				html +=     '<div class="aic-token-line aic-token-meta">';
				html +=       '<span>Issued: ' + fmtTime(t.issued_at) + '</span>';
				html +=       '<span>Expires: ' + fmtTime(t.expires_at) + '</span>';
				html +=       '<span>Last used: ' + (t.last_used_at ? fmtTime(t.last_used_at) : 'never') + '</span>';
				if (t.last_used_ip) html += '<span>IP: ' + escapeHtml(t.last_used_ip) + '</span>';
				if (t.revoked_at) html += '<span>Revoked: ' + fmtTime(t.revoked_at) + '</span>';
				html +=     '</div>';
				html +=   '</div>';
				html +=   '<div class="aic-token-actions">';
				html +=     '<button class="aic-token-revoke-btn" ' + disabled + ' onclick="aicRevokeToken(' + t.id + ', this)">' + btnLabel + '</button>';
				html +=   '</div>';
				html += '</div>';
			});
			list.innerHTML = html;
			updateTokensCount();
		})
		.catch(function(e){
			loading.style.display = 'none';
			errBox.textContent = 'Error loading tokens: ' + e.message;
			errBox.style.display = '';
		});
	};

	window.aicRevokeToken = function(id, btn) {
		if (!confirm('Revoke this token? Any AI agent currently using it will lose access immediately.')) return;
		btn.disabled = true;
		btn.textContent = '⏳';
		fetch(TOKENS_URL + '/' + id, {
			method: 'DELETE',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': NONCE }
		})
		.then(function(r){ return r.json(); })
		.then(function(data){
			if (data && data.success) {
				aicLoadTokens();
			} else {
				btn.disabled = false;
				btn.textContent = 'Revoke';
				alert((data && data.message) || 'Revoke failed.');
			}
		})
		.catch(function(e){
			btn.disabled = false;
			btn.textContent = 'Revoke';
			alert('Error: ' + e.message);
		});
	};

	window.aicRevokeAllTokens = function() {
		if (!confirm('Revoke ALL your active AI tokens? All connected AI agents will lose access immediately. This cannot be undone.')) return;
		fetch(TOKENS_URL, {
			method: 'DELETE',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': NONCE }
		})
		.then(function(r){ return r.json(); })
		.then(function(data){
			if (data && data.success) {
				alert('Revoked ' + (data.revoked || 0) + ' token(s).');
				aicLoadTokens();
			} else {
				alert((data && data.message) || 'Revoke-all failed.');
			}
		})
		.catch(function(e){
			alert('Error: ' + e.message);
		});
	};

	document.addEventListener('DOMContentLoaded', function(){
		var filterSel = el('aic-tokens-filter');
		if (filterSel) filterSel.addEventListener('change', aicLoadTokens);
		// Don't load full list on page load — only fetch the count.
		// User opens the tokens panel manually if they want to see/manage.
		updateTokensCount();
	});
})();
</script>
</body>
</html>
		<?php
	}
}
