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
		add_action( 'wp_head', array( $this, 'add_font_preconnect' ), 1 );
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

		// Google Fonts — Space Grotesk (display), Inter (body), JetBrains Mono (code/token).
		// phpcs:disable WordPress.WP.EnqueuedResourceParameters.MissingVersion -- External Google Fonts URL, versioned by Google.
		wp_enqueue_style(
			'goldtwmcp-info-fonts',
			'https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;500;600&display=swap',
			array(),
			null
		);
		// phpcs:enable WordPress.WP.EnqueuedResourceParameters.MissingVersion

		wp_enqueue_style(
			'goldtwmcp-info-page',
			GOLDTWMCP_URL . 'assets/css/info-page.css',
			array( 'goldtwmcp-info-fonts' ),
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
	 * Add preconnect hints to fonts.gstatic.com / fonts.googleapis.com
	 * so the font request finishes faster.
	 *
	 * @return void
	 */
	public function add_font_preconnect() {
		if ( ! get_query_var( 'goldtwmcp_info_page' ) ) {
			return;
		}
		echo '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n";
		echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
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

		// Mark up the platform list with the featured webmcp-master row.
		?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>AI Connect &mdash; <?php echo esc_html( $site_name ); ?></title>
		<?php wp_head(); ?>
</head>
<body class="aiconnect-page">

<div class="aiconnect-app">
	<div class="shell">

		<!-- Brand header -->
		<header class="pagehead">
			<div class="logo">
				<svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
			</div>
			<div class="titles">
				<h1>AI Connect</h1>
				<p><?php echo esc_html( $site_name ); ?> &middot; WebMCP Protocol Bridge</p>
			</div>
			<span class="spacer"></span>
			<a class="docslink" href="https://ai-connect.gold-t.co.il/wordpress" target="_blank" rel="noopener">
				<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
				Documentation
			</a>
			<?php if ( $is_logged_in ) : ?>
				<button type="button" class="tokens-pill" onclick="aicOpenTokensModal()">
					<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
					My AI Tokens <span id="aic-tokens-count" class="count"></span>
				</button>
				<span class="rolepill"><span class="dot"></span> <?php echo esc_html( $display_name ); ?></span>
			<?php else : ?>
				<span class="rolepill" style="color:#B91C1C;">Login required</span>
			<?php endif; ?>
		</header>

		<?php if ( ! $is_logged_in ) : ?>
		<div class="card"><div class="card-pad">
			<p style="margin:0;color:var(--muted);font-size:14px;">
				You must be <a href="<?php echo esc_url( wp_login_url( home_url( '/ai-connect/' ) ) ); ?>" style="color:var(--coral);font-weight:600;text-decoration:none;">logged in</a> to generate a connection prompt. The manifest URL below is publicly accessible for AI agent discovery.
			</p>
		</div></div>
		<?php endif; ?>

		<?php if ( $is_logged_in ) : ?>
		<!-- ============== CONSOLE HERO ============== -->
		<section class="console" aria-label="Connect your AI agent">
			<div class="console-top">
				<span class="traffic"><i></i><i></i><i></i></span>
				<span class="label">ai-connect &middot; connection</span>
				<span class="ready"><span class="pulse"></span> Ready</span>
			</div>
			<div class="console-body">
				<p class="eyebrow">Connect your AI agent</p>
				<h2>One prompt connects any agent to your site</h2>
				<p class="lede">Generate a connection prompt with a Bearer token, refresh token, and the full list of tools the agent can call. Paste it into Claude, ChatGPT, Gemini, or any agent. <b>Permissions match your WordPress role automatically.</b></p>

				<button class="btn btn-primary" id="aic-gen-btn" type="button" onclick="aicGeneratePrompt(this)">
					<svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z"/></svg>
					Generate AI prompt
				</button>

				<div class="output" id="aic-output">
					<div class="output-head">
						<span class="tag">&#9679;</span> connection-prompt.txt
						<span class="acts">
							<button class="miniact" id="aic-copy-btn" type="button" onclick="aicCopyPrompt(this)">
								<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
								Copy prompt
							</button>
							<button class="miniact" id="aic-regen-btn" type="button" onclick="aicGeneratePrompt(this)">
								<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M23 4v6h-6"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
								Regenerate
							</button>
						</span>
					</div>
<pre id="aic-output-pre" class="is-placeholder"># Click "Generate AI prompt" above to create your personal connection.
# The prompt will appear here with your Bearer token, refresh token,
# and the full list of tools available to your WordPress role.</pre>
				</div>
				<div id="aic-gen-error" class="gen-error is-hidden"></div>
			</div>
		</section>

		<!-- Manual setup disclosure -->
		<details class="disclosure">
			<summary>
				<svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
				Manual setup <span class="sub">&mdash; for agents that don't accept generated prompts</span>
			</summary>
			<div class="disc-body">
				Copy the manifest URL and add it as a tool source in your agent's MCP settings, then authorize when prompted. The four steps below cover the full flow.
				<div class="url-row">
					<input id="aic-manifest-url" type="text" value="<?php echo esc_attr( $manifest_url ); ?>" readonly>
					<button type="button" onclick="aicCopyManifest(this)">Copy</button>
				</div>
			</div>
		</details>

		<!-- Tokens modal -->
		<div id="aic-tokens-modal" class="modal-backdrop is-hidden" onclick="aicCloseTokensModal(event)">
			<div class="modal" onclick="event.stopPropagation();">
				<div class="modal-header">
					<h2 class="modal-title">
						<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
						My AI tokens
					</h2>
					<button type="button" class="modal-close" onclick="aicCloseTokensModal()" aria-label="Close">&times;</button>
				</div>
				<div class="modal-body">
					<p style="font-size:13.5px;color:var(--muted);margin:0 0 4px;">Every prompt you generate creates a personal Bearer token. Revoke one to cut off the agent that holds it.</p>

					<div class="tk-controls">
						<select class="select" id="aic-tokens-filter" aria-label="Filter tokens">
							<option value="all">All my tokens</option>
							<option value="active">Active (in use)</option>
							<option value="unused">Issued but unused</option>
							<option value="inactive">Inactive 30+ days</option>
							<option value="renewable">Access expired, refresh valid</option>
							<option value="expired">Fully expired</option>
							<option value="revoked">Revoked</option>
						</select>
						<button type="button" class="btn-ghost" onclick="aicLoadTokens()">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M23 4v6h-6"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
							Refresh
						</button>
						<span class="spacer"></span>
						<button type="button" class="btn-ghost btn-danger" onclick="aicRevokeAllTokens()">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
							Revoke all
						</button>
					</div>

					<div id="aic-tokens-loading" class="empty is-hidden" style="padding:24px 16px;">
						<p>Loading&hellip;</p>
					</div>
					<div id="aic-tokens-empty" class="empty is-hidden">
						<span class="ico">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 9.9-1"/></svg>
						</span>
						<h4>No tokens here yet</h4>
						<p>Generate a prompt above and the first token will appear here, ready to revoke whenever you want.</p>
					</div>
					<div id="aic-tokens-list" class="tokens-list"></div>
					<div id="aic-tokens-error" class="gen-error is-hidden" style="margin-top:14px;"></div>
				</div>
			</div>
		</div>
		<?php else : ?>
		<!-- Logged-out fallback -->
		<div class="card"><div class="card-pad">
			<div class="sec-head">
				<span class="ico">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
				</span>
				<div><h3>Manifest URL</h3><p>Public — AI agents can discover this site through it.</p></div>
			</div>
			<div style="margin-top:16px;display:flex;gap:0;border-radius:9px;overflow:hidden;border:1px solid var(--line);">
				<input id="aic-manifest-url" type="text" value="<?php echo esc_attr( $manifest_url ); ?>" readonly style="flex:1;font-family:var(--font-mono);font-size:13px;padding:10px 14px;border:none;background:#F8FAFC;color:#1E293B;outline:none;">
				<button type="button" onclick="aicCopyManifest(this)" style="padding:10px 18px;background:var(--ink);color:#fff;border:none;cursor:pointer;font-size:13px;font-weight:600;font-family:var(--font-body);">Copy</button>
			</div>
		</div></div>
		<?php endif; ?>

		<!-- Supported platforms -->
		<section class="card">
			<div class="card-pad">
				<div class="sec-head">
					<span class="ico">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 2a4 4 0 0 1 4 4 4 4 0 0 1 1 7.87V18a3 3 0 0 1-6 0 3 3 0 0 1-6 0v-4.13A4 4 0 0 1 6 6a4 4 0 0 1 4-4"/><path d="M12 2v20"/></svg>
					</span>
					<div>
						<h3>Supported AI platforms</h3>
						<p>All of these can connect with a generated prompt today.</p>
					</div>
				</div>
				<div class="plat-grid">
					<?php
					foreach ( $clients as $client ) :
						$is_feat = ( 'webmcp-master' === $client['id'] );
						$parts   = explode( '(', $client['name'], 2 );
						$main    = trim( $parts[0] );
						$sub     = isset( $parts[1] ) ? rtrim( $parts[1], ')' ) : '';
						?>
					<div class="plat<?php echo $is_feat ? ' feat' : ''; ?>">
						<span class="dot"></span>
						<div>
							<span><?php echo esc_html( $main ); ?></span>
							<?php if ( '' !== $sub ) : ?>
								<span class="sub"><?php echo esc_html( $sub ); ?></span>
							<?php endif; ?>
						</div>
					</div>
					<?php endforeach; ?>
				</div>
			</div>
		</section>

		<!-- How to connect -->
		<section class="card">
			<div class="card-pad">
				<div class="sec-head">
					<span class="ico">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M9 13h6M9 17h4"/></svg>
					</span>
					<div>
						<h3>How to connect</h3>
						<p>Four steps from copy to chatting with your content.</p>
					</div>
				</div>
				<div class="steps">
					<div class="step">
						<div class="num">1</div>
						<h5>Generate a prompt</h5>
						<p>Click the button in the console above. Your token and tools are baked in automatically.</p>
					</div>
					<div class="step">
						<div class="num">2</div>
						<h5>Paste into your agent</h5>
						<p>Open Claude, ChatGPT, Gemini, or any AI agent and paste the full prompt as your first message.</p>
					</div>
					<div class="step">
						<div class="num">3</div>
						<h5>Agent connects</h5>
						<p>The agent reads the manifest, stores the token, and confirms it can reach <?php echo esc_html( $site_name ); ?>.</p>
					</div>
					<div class="step">
						<div class="num">4</div>
						<h5>Start chatting</h5>
						<p>Ask the agent to search posts, read pages, or act on your site &mdash; it does so on your behalf.</p>
					</div>
				</div>
			</div>
		</section>

	</div>
</div>

		<?php wp_footer(); ?>
<script>
(function(){
	var GEN_URL  = '<?php echo esc_js( rest_url( 'goldt-webmcp-bridge/v1/generate-prompt' ) ); ?>';
	var NONCE    = '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>';
	var loggedIn = <?php echo $is_logged_in ? 'true' : 'false'; ?>;
	var lastPrompt = '';

	function el(id){ return document.getElementById(id); }

	function setHidden(node, hidden) {
		if (!node) return;
		node.classList.toggle('is-hidden', !!hidden);
	}

	function genBtnLabel(btn) {
		if (btn.id === 'aic-regen-btn') {
			return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M23 4v6h-6"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg> Regenerate';
		}
		return '<svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z"/></svg> Generate AI prompt';
	}

	function restoreBtn(btn) {
		btn.disabled = false;
		btn.innerHTML = genBtnLabel(btn);
	}

	// Syntax-highlight the prompt for the console pre block.
	function renderPrompt(prompt) {
		var pre = el('aic-output-pre');
		if (!pre) return;
		pre.classList.remove('is-placeholder');
		// Escape, then colorize markdown headings + key:value pairs the prompt uses.
		var esc = prompt
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;');
		// Comment lines (#)
		esc = esc.replace(/^(\s*#.*)$/gm, '<span class="c-note">$1</span>');
		// Markdown headings (##)
		esc = esc.replace(/^(##.*)$/gm, '<span class="c-key">$1</span>');
		// key:value lines (name: ..., manifest_url: ..., token: ..., etc.)
		esc = esc.replace(/^(\s*[a-z_]+):(\s+)(.+)$/gim, '<span class="c-key">$1</span>:$2<span class="c-val">$3</span>');
		pre.innerHTML = esc;
	}

	if (!loggedIn) return;

	window.aicGeneratePrompt = function(btn) {
		btn.disabled = true;
		btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg> Working&hellip;';
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
				lastPrompt = data.prompt;
				renderPrompt(data.prompt);
				restoreBtn(btn);
				updateTokensCount();
				// Smoothly bring the console output into view.
				var output = el('aic-output');
				if (output && output.scrollIntoView) {
					output.scrollIntoView({behavior: 'smooth', block: 'nearest'});
				}
			} else {
				err.textContent = (data && data.message) ? data.message : 'Failed to generate prompt.';
				setHidden(err, false);
				restoreBtn(btn);
			}
		})
		.catch(function(e){
			err.textContent = 'Error: ' + e.message;
			setHidden(err, false);
			restoreBtn(btn);
		});
	};

	window.aicCopyPrompt = function(btn) {
		if (!lastPrompt) {
			alert('Click "Generate AI prompt" first.');
			return;
		}
		var original = btn.innerHTML;
		var done = function(){
			btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg> Copied';
			setTimeout(function(){ btn.innerHTML = original; }, 1600);
		};
		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(lastPrompt).then(done).catch(function(){
				fallbackCopy(lastPrompt); done();
			});
		} else {
			fallbackCopy(lastPrompt); done();
		}
	};

	window.aicCopyManifest = function(btn) {
		var input = el('aic-manifest-url');
		if (!input) return;
		var original = btn.textContent;
		input.select();
		var ok = false;
		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(input.value);
			ok = true;
		} else {
			try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
		}
		if (ok) {
			btn.classList.add('copied');
			btn.textContent = 'Copied';
			setTimeout(function(){
				btn.classList.remove('copied');
				btn.textContent = original;
			}, 1600);
		}
	};

	function fallbackCopy(text) {
		var ta = document.createElement('textarea');
		ta.value = text;
		ta.style.position = 'fixed';
		ta.style.top = '-1000px';
		document.body.appendChild(ta);
		ta.select();
		try { document.execCommand('copy'); } catch (e) {}
		document.body.removeChild(ta);
	}

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
				var stateClass = 'state-' + t.state;
				var rowClass = 'is-' + t.state;
				var disabled = (t.state === 'revoked') ? 'disabled' : '';
				var btnLabel = (t.state === 'revoked') ? 'Revoked' : 'Revoke';
				html += '<div class="token-row ' + rowClass + '" data-id="' + t.id + '">';
				html +=   '<div class="token-info">';
				html +=     '<div class="token-line">';
				html +=       '<span class="state-badge ' + stateClass + '">' + escapeHtml(t.state) + '</span>';
				html +=       '<code>' + escapeHtml(t.token_prefix) + '&hellip;</code>';
				html +=       '<span class="token-meta">scope: ' + escapeHtml(t.scope) + '</span>';
				html +=     '</div>';
				html +=     '<div class="token-line token-meta">';
				html +=       '<span>Issued: ' + fmtTime(t.issued_at) + '</span>';
				html +=       '<span>Expires: ' + fmtTime(t.expires_at) + '</span>';
				html +=       '<span>Last used: ' + (t.last_used_at ? fmtTime(t.last_used_at) : 'never') + '</span>';
				if (t.last_used_ip) html += '<span>IP: ' + escapeHtml(t.last_used_ip) + '</span>';
				if (t.revoked_at) html += '<span>Revoked: ' + fmtTime(t.revoked_at) + '</span>';
				html +=     '</div>';
				html +=   '</div>';
				html +=   '<div>';
				html +=     '<button type="button" class="token-revoke" ' + disabled + ' onclick="aicRevokeToken(' + t.id + ', this)">' + btnLabel + '</button>';
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
		btn.textContent = '\u23F3';
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
