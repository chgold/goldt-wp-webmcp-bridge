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
			<button id="aic-tokens-toggle" type="button" class="aic-header-pill" onclick="aicOpenTokensModal()">
				&#128274; My AI Tokens <span id="aic-tokens-count" class="aic-pill-count"></span>
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
		<p class="aic-hero-intro">
			Click the button below to generate a complete connection prompt — Bearer token, refresh token, and the full list of tools you can call. Paste it into Claude, ChatGPT, Gemini, or any AI agent. <strong>Permissions automatically match your WordPress role.</strong>
		</p>

		<div class="aic-hero-btn-wrap">
			<button id="aic-gen-btn" type="button" class="aic-hero-btn" onclick="aicGeneratePrompt(this)">&#9889; Generate AI Prompt</button>
		</div>

		<div id="aic-prompt-result" class="aic-prompt-result is-hidden">
			<textarea id="aic-generated-prompt" class="aic-prompt-textarea aic-prompt-textarea-tall" readonly rows="22"></textarea>
			<div class="aic-prompt-actions">
				<button type="button" class="aic-btn-primary" onclick="aicCopy('aic-generated-prompt',this)">&#128203; Copy full prompt</button>
				<button id="aic-regen-btn" type="button" class="aic-btn-secondary" onclick="aicGeneratePrompt(this)">&#128260; Regenerate (new token)</button>
			</div>
		</div>
		<div id="aic-gen-error" class="aic-error is-hidden"></div>
	</div>

	<!-- Section 2: For advanced users (manifest URL only) -->
	<div class="aic-card aic-advanced-card">
		<details>
			<summary>Manual setup (for AI agents that don't accept generated prompts)</summary>
			<div class="aic-advanced-body">
				<div class="aic-label">Manifest URL &mdash; give this to the AI if it asks for one</div>
				<div class="aic-url-row">
					<input id="aic-manifest-url" type="text" class="aic-url-input" value="<?php echo esc_attr( $manifest_url ); ?>" readonly>
					<button type="button" class="aic-copy-btn" onclick="aicCopy('aic-manifest-url', this)">Copy</button>
				</div>
				<p class="aic-advanced-note">
					The AI agent will read this manifest, then send you to an OAuth login page in your browser. Approve there, and the agent receives its own token automatically.
				</p>
			</div>
		</details>
	</div>

	<!-- Tokens modal — opens from the header pill button -->
	<div id="aic-tokens-modal" class="aic-modal-backdrop is-hidden" onclick="aicCloseTokensModal(event)">
		<div class="aic-modal" onclick="event.stopPropagation();">
			<div class="aic-modal-header">
				<h2 class="aic-modal-title"><span>&#128274;</span> My AI Tokens</h2>
				<button type="button" class="aic-modal-close" onclick="aicCloseTokensModal()" aria-label="Close">&times;</button>
			</div>
			<div class="aic-modal-body">
				<p class="aic-modal-intro">
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
						<button type="button" class="aic-btn-secondary" onclick="aicLoadTokens()">&#128260; Refresh</button>
						<button type="button" class="aic-btn-danger" onclick="aicRevokeAllTokens()">&#9888;&#65039; Revoke all</button>
					</div>
				</div>
				<div id="aic-tokens-loading" class="aic-tokens-status is-hidden">Loading&hellip;</div>
				<div id="aic-tokens-empty" class="aic-tokens-status is-hidden">No tokens match this filter.</div>
				<div id="aic-tokens-list" class="aic-tokens-list"></div>
				<div id="aic-tokens-error" class="aic-error is-hidden"></div>
			</div>
		</div>
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
		/* === Utility === */
		.is-hidden { display: none !important; }

		/* === Header pill (My AI Tokens trigger) === */
		.aic-header-meta { display:flex; gap:10px; align-items:center; justify-content:center; flex-wrap:wrap; margin-top:14px; }
		.aic-header-pill { display:inline-flex; align-items:center; gap:6px; padding:5px 14px; background:rgba(255,255,255,0.12); border:1px solid rgba(255,255,255,0.2); color:#fff; font-size:13px; font-weight:500; border-radius:20px; cursor:pointer; transition:background 0.15s ease; }
		.aic-header-pill:hover { background:rgba(255,255,255,0.22); }
		.aic-pill-count { color:rgba(255,255,255,0.7); font-size:12px; font-weight:400; margin-left:2px; }

		/* === Hero card (prompt generator) === */
		.aic-hero-card { border-left:4px solid #16a34a; }
		.aic-hero-intro { font-size:14px; color:#475569; line-height:1.55; margin:0 0 18px; }
		.aic-hero-intro strong { color:#0f3460; }
		.aic-hero-btn-wrap { text-align:center; padding:8px 0 4px; }
		.aic-hero-btn {
			display:inline-flex; align-items:center; gap:8px;
			padding:14px 32px;
			background:linear-gradient(135deg, #0f3460 0%, #16213e 100%);
			color:#fff; border:none; border-radius:10px;
			font-size:16px; font-weight:600; font-family:inherit;
			cursor:pointer; box-shadow:0 4px 14px rgba(15,52,96,0.25);
			transition:transform 0.1s ease, box-shadow 0.15s ease;
		}
		.aic-hero-btn:hover { box-shadow:0 6px 20px rgba(15,52,96,0.35); transform:translateY(-1px); }
		.aic-hero-btn:active { transform:translateY(0); }
		.aic-hero-btn:disabled { opacity:0.7; cursor:wait; transform:none; box-shadow:none; }

		/* === Prompt result (textarea + action row) === */
		.aic-prompt-result { margin-top:20px; padding-top:20px; border-top:2px solid #f0f2f5; }
		.aic-prompt-textarea-tall { height:auto; min-height:400px; }
		.aic-prompt-actions { display:flex; gap:10px; margin-top:12px; flex-wrap:wrap; }

		/* === Buttons (shared, NOT positioned absolute) === */
		.aic-btn-primary, .aic-btn-secondary, .aic-btn-danger {
			padding:10px 18px; border:none; border-radius:8px;
			font-size:13px; font-weight:600; font-family:inherit;
			cursor:pointer; transition:background 0.15s ease;
		}
		.aic-btn-primary { background:#0f3460; color:#fff; }
		.aic-btn-primary:hover { background:#16213e; }
		.aic-btn-primary.copied { background:#16a34a; }
		.aic-btn-secondary { background:#e2e8f0; color:#1e293b; }
		.aic-btn-secondary:hover { background:#cbd5e1; }
		.aic-btn-danger { background:#dc2626; color:#fff; }
		.aic-btn-danger:hover { background:#b91c1c; }

		.aic-error { color:#dc2626; font-size:13px; margin-top:10px; padding:10px 14px; background:#fef2f2; border:1px solid #fecaca; border-radius:6px; }

		/* === Advanced (manual setup) === */
		.aic-advanced-card { padding:18px 32px; }
		.aic-advanced-card details summary { cursor:pointer; color:#6b7280; font-size:14px; font-weight:600; padding:4px 0; outline:none; user-select:none; }
		.aic-advanced-card details summary:hover { color:#0f3460; }
		.aic-advanced-card details[open] summary { color:#0f3460; margin-bottom:8px; }
		.aic-advanced-body { padding-top:14px; }
		.aic-advanced-note { font-size:12px; color:#6b7280; margin-top:10px; line-height:1.5; }

		/* === Modal (tokens history) === */
		.aic-modal-backdrop { position:fixed; inset:0; background:rgba(15,23,42,0.6); backdrop-filter:blur(3px); z-index:9998; display:flex; align-items:center; justify-content:center; padding:20px; animation:aicFadeIn 0.15s ease; }
		.aic-modal { background:#fff; border-radius:14px; max-width:760px; width:100%; max-height:85vh; display:flex; flex-direction:column; box-shadow:0 20px 60px rgba(0,0,0,0.3); animation:aicSlideUp 0.2s ease; }
		.aic-modal-header { display:flex; align-items:center; justify-content:space-between; padding:18px 24px; border-bottom:2px solid #f0f2f5; }
		.aic-modal-title { font-size:18px; font-weight:700; color:#0f3460; margin:0; display:flex; align-items:center; gap:8px; }
		.aic-modal-close { background:transparent; border:none; font-size:28px; line-height:1; color:#9ca3af; cursor:pointer; padding:4px 12px; border-radius:6px; }
		.aic-modal-close:hover { background:#f0f2f5; color:#1e293b; }
		.aic-modal-body { padding:20px 24px; overflow-y:auto; }
		.aic-modal-intro { font-size:13px; color:#475569; margin:0 0 16px; line-height:1.5; }

		/* === Tokens toolbar & list (inside modal) === */
		.aic-tokens-toolbar { display:flex; gap:14px; flex-wrap:wrap; align-items:flex-end; margin-bottom:16px; }
		.aic-tokens-filter-wrap { display:flex; flex-direction:column; gap:4px; flex:1; min-width:200px; }
		.aic-tokens-filter-wrap label { font-size:11px; font-weight:600; color:#6b7280; text-transform:uppercase; letter-spacing:0.4px; }
		.aic-tokens-filter-wrap select { padding:9px 12px; background:#f8fafc; color:#1e293b; border:1px solid #e2e8f0; border-radius:8px; font-size:13px; cursor:pointer; font-family:inherit; }
		.aic-tokens-filter-wrap select:focus { outline:none; border-color:#0f3460; }
		.aic-tokens-actions { display:flex; gap:8px; align-items:flex-end; flex-wrap:wrap; }
		.aic-tokens-status { color:#6b7280; font-size:13px; padding:14px; text-align:center; background:#f8fafc; border-radius:8px; }
		.aic-tokens-list { display:flex; flex-direction:column; gap:8px; }

		.aic-token-row { display:grid; grid-template-columns:1fr auto; gap:14px; padding:12px 14px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; }
		.aic-token-row.aic-token-revoked { opacity:0.55; background:#fef2f2; border-color:#fecaca; }
		.aic-token-row.aic-token-expired { opacity:0.75; }
		.aic-token-info { display:flex; flex-direction:column; gap:6px; font-size:13px; color:#1e293b; min-width:0; }
		.aic-token-info .aic-token-line { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
		.aic-token-info code { background:#fff; padding:2px 8px; border-radius:4px; font-size:12px; color:#0f3460; font-family:'SFMono-Regular',Consolas,monospace; border:1px solid #e2e8f0; }
		.aic-token-meta { color:#6b7280; font-size:12px; }
		.aic-state-badge { padding:2px 10px; border-radius:12px; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.4px; }
		.aic-state-active { background:#dcfce7; color:#166534; }
		.aic-state-expired { background:#fef3c7; color:#92400e; }
		.aic-state-revoked { background:#fecaca; color:#991b1b; }
		.aic-token-actions { display:flex; align-items:center; }
		.aic-token-revoke-btn { padding:6px 14px; background:#dc2626; color:#fff; border:none; border-radius:6px; cursor:pointer; font-size:12px; font-weight:600; font-family:inherit; }
		.aic-token-revoke-btn:hover { background:#b91c1c; }
		.aic-token-revoke-btn:disabled { opacity:0.5; cursor:not-allowed; }

		@keyframes aicFadeIn { from { opacity:0; } to { opacity:1; } }
		@keyframes aicSlideUp { from { transform:translateY(20px); opacity:0; } to { transform:translateY(0); opacity:1; } }
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

	function setHidden(node, hidden) {
		if (!node) return;
		node.classList.toggle('is-hidden', !!hidden);
	}

	function restoreGenBtnLabel(btn) {
		btn.disabled = false;
		btn.innerHTML = btn.id === 'aic-regen-btn'
			? '&#128260; Regenerate (new token)'
			: '&#9889; Generate AI Prompt';
	}

	window.aicGeneratePrompt = function(btn) {
		btn.disabled = true;
		btn.innerHTML = '&#9203; Generating&hellip;';
		var err = el('aic-gen-error');
		setHidden(err, true);

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
				setHidden(el('aic-prompt-result'), false);
				// Hide the big primary button after first generate; user uses Regenerate from now on.
				setHidden(el('aic-gen-btn'), true);
				restoreGenBtnLabel(btn);
				updateTokensCount();
			} else {
				err.textContent = (data && data.message) ? data.message : 'Failed to generate prompt.';
				setHidden(err, false);
				restoreGenBtnLabel(btn);
			}
		})
		.catch(function(e){
			err.textContent = 'Error: ' + e.message;
			setHidden(err, false);
			restoreGenBtnLabel(btn);
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

	window.aicOpenTokensModal = function() {
		var modal = el('aic-tokens-modal');
		if (!modal) return;
		setHidden(modal, false);
		document.body.style.overflow = 'hidden';
		aicLoadTokens();
	};

	window.aicCloseTokensModal = function(ev) {
		// When called from backdrop click, only close if the click was on the backdrop itself.
		if (ev && ev.target && ev.target.id && ev.target.id !== 'aic-tokens-modal') return;
		var modal = el('aic-tokens-modal');
		if (!modal) return;
		setHidden(modal, true);
		document.body.style.overflow = '';
	};

	// ESC closes the modal.
	document.addEventListener('keydown', function(e){
		if (e.key !== 'Escape') return;
		var modal = el('aic-tokens-modal');
		if (modal && !modal.classList.contains('is-hidden')) aicCloseTokensModal();
	});

	window.aicLoadTokens = function() {
		var filterSel = el('aic-tokens-filter');
		var status = filterSel ? filterSel.value : 'all';
		var list = el('aic-tokens-list');
		var empty = el('aic-tokens-empty');
		var loading = el('aic-tokens-loading');
		var errBox = el('aic-tokens-error');
		if (!list) return;
		setHidden(errBox, true);
		setHidden(empty, true);
		setHidden(loading, false);
		list.innerHTML = '';

		fetch(TOKENS_URL + '?status=' + encodeURIComponent(status), {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': NONCE }
		})
		.then(function(r){ return r.json(); })
		.then(function(data){
			setHidden(loading, true);
			var tokens = (data && data.tokens) || [];
			if (!tokens.length) {
				setHidden(empty, false);
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
				html +=     '<button type="button" class="aic-token-revoke-btn" ' + disabled + ' onclick="aicRevokeToken(' + t.id + ', this)">' + btnLabel + '</button>';
				html +=   '</div>';
				html += '</div>';
			});
			list.innerHTML = html;
			updateTokensCount();
		})
		.catch(function(e){
			setHidden(loading, true);
			errBox.textContent = 'Error loading tokens: ' + e.message;
			setHidden(errBox, false);
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
