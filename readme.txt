=== GoldT WebMCP Bridge ===
Contributors: chgold
Tags: ai, webmcp, rest-api, oauth, ai-agent
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Bridge for 8 AI agents (Claude, ChatGPT, Grok, more) via WebMCP with OAuth 2.0

== Description ==

**GoldT WebMCP Bridge** enables AI agents to interact with your WordPress content through secure OAuth 2.0 authentication using the WebMCP protocol.

Perfect for AI-powered customer support, automated content analysis, intelligent search, and custom AI integrations.

= ✨ Features =

* **WebMCP Protocol Support** - Industry-standard AI integration
* **Secure OAuth 2.0** - Same security standard as Google, Facebook, GitHub - your passwords stay safe
* **8 Pre-registered AI Clients** - Claude, ChatGPT, Gemini, Grok, Perplexity, Copilot, Meta AI, DeepSeek
* **5 WordPress Tools** - Search/get posts, pages, and user info
* **Rate Limiting** - Prevent abuse (50 req/min default)
* **Security Controls** - Token management, block specific users
* **Zero Configuration** - Works out of the box
* **Extensible** - Add custom tools via developer hooks

= 🎯 Quick Start for AI Users =

**Using ChatGPT or Claude?**

Tell your AI agent:
> "I want to connect you to my WordPress site at https://mysite.com using GoldT WebMCP Bridge plugin. The manifest is at /wp-json/goldt-webmcp-bridge/v1/manifest. Use OAuth 2.0 with client_id: claude-ai"

The AI will guide you through OAuth authorization - you'll approve access in your browser.

= 🤖 Supported AI Agents =

**Pre-registered and ready to connect:**

* **Claude AI** - Use `client_id: claude-ai` (Anthropic)
* **ChatGPT** - Use `client_id: chatgpt` (OpenAI)
* **Gemini** - Use `client_id: gemini` (Google)
* **Grok** - Use `client_id: grok` (xAI)
* **Perplexity AI** - Use `client_id: perplexity`
* **Microsoft Copilot** - Use `client_id: copilot`
* **Meta AI** - Use `client_id: meta-ai` (Facebook)
* **DeepSeek** - Use `client_id: deepseek`

All clients use OAuth 2.0 with PKCE and `redirect_uri: urn:ietf:wg:oauth:2.0:oob` (out-of-band).

= 🛠️ Available Tools =

1. **wordpress.searchPosts** - Search posts with filters
2. **wordpress.getPost** - Get single post by ID or slug
3. **wordpress.searchPages** - Search pages
4. **wordpress.getPage** - Get single page by ID or slug
5. **wordpress.getCurrentUser** - Get authenticated user info

= 🔒 How Authentication Works =

**Secure OAuth 2.0 Authentication:**

Uses the same security standard trusted by Google, Facebook, and GitHub:

1. AI agent initiates OAuth flow with code challenge (PKCE)
2. User approves in browser (consent screen)
3. Agent receives one-time authorization code
4. Agent exchanges code for access token using code verifier
5. Agent uses token for API calls

**The AI agent operates as the user who authorized:**
* The agent receives an OAuth token linked to that user's ID
* All API requests run with that user's permissions
* The agent respects WordPress user capabilities

**Examples:**

**If Administrator authorizes:**
* ✅ Sees all posts (including drafts, private)
* ✅ Full access based on admin capabilities

**If Subscriber authorizes:**
* ✅ Sees only published content
* ❌ Cannot see drafts or private content

**Security:** Authorization codes are one-time use (10 min expiry). Access tokens expire after 1 hour. Refresh tokens valid for 30 days. PKCE ensures tokens can't be stolen.

= 🔐 Admin Controls =

**For Site Administrators:**

Manage security from the WordPress admin panel:

* **Revoke OAuth Tokens** - Go to **GoldT WebMCP → OAuth Tokens** to view and revoke active tokens
* **Block Users** - Go to **GoldT WebMCP → Settings** → scroll to "Manage User Access" section
* **Rate Limits** - Configure request limits in **GoldT WebMCP → Settings** (default: 50/min, 1000/hour)

= 💬 We Need Your Feedback! =

Help us build what YOU need:

* 💡 **What tools would be most useful?** Tell us which WordPress features you'd like AI agents to access
* 🐛 **Found a bug?** Report it so we can fix it quickly  
* ⭐ **Feature requests** - We prioritize based on community feedback

**How to provide feedback:**
* GitHub: https://github.com/chgold/goldt-webmcp-bridge/issues
* WordPress.org: Support forum

Your feedback directly shapes the future of this plugin!

== Installation ==

= Automatic Installation =

1. Go to **Plugins → Add New** in WordPress admin
2. Search for "GoldT WebMCP Bridge"
3. Click **Install Now** and then **Activate**

= Manual Installation =

1. Download the plugin zip file
2. Go to **Plugins → Add New → Upload Plugin**
3. Upload the zip file and click **Install Now**
4. Activate the plugin

**Note:** All required dependencies are included. No manual setup required!

= Setup =

**No setup required!** The plugin works immediately after activation.

**Optional:** Configure rate limits in **GoldT WebMCP → Settings**

**For detailed setup and testing examples**, see the [plugin documentation on GitHub](https://github.com/chgold/goldt-webmcp-bridge).

== Frequently Asked Questions ==

= What is WebMCP? =

WebMCP (Web Model Context Protocol) is a standardized protocol for connecting AI agents to web services. It defines how AI assistants discover, authenticate with, and execute tools on web platforms.

= Does this work with ChatGPT and Claude? =

Yes! GoldT WebMCP Bridge works with any AI platform that supports REST APIs. This includes ChatGPT (OpenAI), Claude (Anthropic), Make.com, Zapier, and custom applications.

= Why does reading public content require authentication? =

All API calls require authentication for security:
* **Rate Limiting** - Prevents spam and abuse
* **Monitoring** - Track who uses your API
* **Security** - Protects against data scraping and DDoS attacks

This is the industry standard (Twitter, GitHub, Google APIs all require auth).

**Exception:** The manifest endpoint is public (no auth needed).

= How does the AI agent authentication work? =

**OAuth 2.0 Authorization:** The AI agent operates as the WordPress user who authorized it.

When a user approves access through the OAuth consent screen:
* The agent receives an access token linked to that user's ID
* All API requests run with that user's permissions
* The agent inherits the user's capabilities

**Security:** 
* No passwords are transmitted - only authorization codes
* PKCE prevents authorization code interception
* Tokens are time-limited (1 hour) and can be revoked
* The agent respects WordPress user capabilities

= Is Redis required? =

No, Redis is optional. The plugin works perfectly with WordPress transients. However, Redis is recommended for high-traffic sites (>1,000 requests/day) as it provides better rate limiting performance.

= Can I add custom tools? =

Yes! GoldT WebMCP Bridge is extensible. Use WordPress hooks to add custom tools:

```php
add_action('goldtwmcp_register_modules', function($goldtwmcp_plugin) {
    $manifest = $goldtwmcp_plugin->get_manifest_instance();
    $manifest->register_tool('mysite.getStats', [...]);
});
```

**Important:** Place your custom tools in your theme's `functions.php` or a separate plugin - they will be preserved during plugin updates.

See the [plugin documentation](https://github.com/chgold/goldt-webmcp-bridge) for more details.

= How long do tokens last? =

* **Access Token**: 1 hour (3600 seconds)
* **Refresh Token**: 30 days (2,592,000 seconds)

Use the refresh token to get a new access token without re-authentication.

= Can I revoke access? =

Yes! Multiple options:

**Revoke specific OAuth token:**
* Go to **GoldT WebMCP → OAuth Tokens**
* Find the token and click "Revoke"

**Block specific user:**
* Go to **GoldT WebMCP → Settings**
* Enter user ID in "Block User" section
* User cannot authenticate or use existing tokens

= How do I troubleshoot authentication errors? =

**Common issues:**

* **"invalid_client"** - Check client_id (use: claude-ai, chatgpt, or gemini)
* **"invalid_grant"** - Authorization code expired or already used (codes are one-time, 10 min expiry)
* **"access_denied"** - User is blocked (check GoldT WebMCP → Settings → Manage User Access)
* **"Token expired"** - Access token expired after 1 hour, use refresh token to get new access token
* **"Rate limit exceeded"** - Wait for retry period or increase limits in Settings

Enable WordPress debug mode and check `wp-content/debug.log` for details.

= Where can I get support? =

* **Community**: WordPress.org support forums

== Screenshots ==

1. Dashboard - System status and quick access to settings
2. Settings - Security controls, rate limits, user management
3. WebMCP Manifest - Auto-generated tool definitions
4. API Response - Example JSON response from API call

== Changelog ==

= 0.2.0 - 2026-02-23 =
* **Security**: Migrated to OAuth 2.0 with PKCE for secure authentication
* **Added**: 8 pre-registered AI clients (Claude, ChatGPT, Gemini, and more)
* **Added**: Parameter validation and resource limits (prevents abuse)
* **Improved**: Security hardening - comprehensive input validation

= 0.1.2 - 2026-02-19 =
* Added: Translation support for 12 languages

= 0.1.1 - 2026-02-16 =
* Improved: Bundled all dependencies - no manual setup required

= 0.1.0 - 2025-02-13 =
* Initial public release
* WebMCP protocol support
* 5 WordPress core tools

== Upgrade Notice ==

= 0.2.0 =
Security update: OAuth 2.0 authentication now enabled. See documentation for setup guide.



== Privacy Policy ==

GoldT WebMCP Bridge does not collect, store, or transmit any personal data to external services. All API requests are handled locally on your WordPress installation.

**Data stored locally:**
* OAuth clients (pre-registered: claude-ai, chatgpt, gemini)
* OAuth authorization codes (temporary, 10 min expiry, one-time use)
* OAuth access tokens (temporary, 1 hour expiry)
* Rate limiting counters
* User blacklist (WordPress user IDs only)

No data leaves your WordPress installation.

== Requirements ==

| Component | Required | Notes |
|-----------|----------|-------|
| WordPress | ✅ 6.0+ | Core requirement |
| PHP | ✅ 7.4+ | With json, openssl |
| Composer | ✅ Yes | For dependencies |
| HTTPS | ⚠️ Production | Required for security |
| Redis | ⭕ Optional | For high traffic |

== Credits ==

* Optional [predis/predis](https://github.com/predis/predis) support for rate limiting
* Compliant with WebMCP protocol specification

**Made with ❤️ for the WordPress & AI community**
