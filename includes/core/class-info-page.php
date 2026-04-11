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

		<div class="aic-label">OAuth Authorize URL</div>
		<div class="aic-url-row">
			<input id="aic-oauth-url" type="text" class="aic-url-input" value="<?php echo esc_attr( home_url( '/?goldtwmcp_oauth_authorize=1' ) ); ?>" readonly>
			<button class="aic-copy-btn" onclick="aicCopy('aic-oauth-url', this)">Copy</button>
		</div>

		<div class="aic-label" style="margin-top: 4px;">Quick Prompt &mdash; paste this into your AI agent to get started</div>
		<div class="aic-prompt-wrap">
			<textarea id="aic-quick-prompt" class="aic-prompt-textarea" readonly><?php echo esc_textarea( $quick_prompt ); ?></textarea>
			<button class="aic-prompt-copy-btn" onclick="aicCopy('aic-quick-prompt', this)">Copy</button>
		</div>
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

<div class="aic-footer">
	Powered by <a href="https://ai-connect.gold-t.co.il/" target="_blank" rel="noopener noreferrer">AI Connect</a> &mdash; WebMCP Protocol Bridge
</div>

		<?php wp_footer(); ?>

</body>
</html>
		<?php
	}
}
