=== GoldT WebMCP Bridge ===
Contributors: chgold
Tags: ai, ai-agent, webmcp, rest-api, artificial-intelligence, oauth
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
* **OAuth 2.0 with PKCE** - Secure authorization code flow, no passwords transmitted
* **8 Pre-registered AI Clients** - Claude, ChatGPT, Gemini, Grok, Perplexity, Copilot, Meta AI, DeepSeek
* **11 WordPress Tools** - Search/get posts, pages, products, cart, orders, and user info
* **Rate Limiting** - Prevent abuse (50 req/min default)
* **Security Controls** - Token management, user blacklist
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

**OAuth 2.0 Authorization Code Flow with PKCE:**

Secure authentication without exposing passwords:

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

Go to **GoldT WebMCP → OAuth Tokens** to manage security:

* **Revoke OAuth Tokens** - View and revoke active OAuth access tokens
* **Block Users** - Revoke access for specific WordPress users (GoldT WebMCP → Settings)
* **Rate Limits** - Configure request limits (GoldT WebMCP → Settings, default: 50/min, 1000/hour)

= 🗺️ Future Development =

We're actively working on new features and improvements!

**We want your feedback:**
* 💡 What features do you need most?
* 🐛 Found a bug? Let us know!

**How to provide feedback:**
* WordPress.org: Support forum

Your feedback directly influences what we build next!

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

**Note:** All required dependencies are included:
* `predis/predis` (v3.4.0) - Redis client for rate limiting (optional)

No manual `composer install` required!

= Setup =

**No setup required!** The plugin works immediately after activation.

**Optional:** Configure rate limits in **GoldT WebMCP → Settings**

= Testing Your Setup =

**Development Note:**

If using PHP built-in server, you MUST use the router script:

```bash
cd /var/www/wp
php -S 0.0.0.0:8888 router.php
```

Without `router.php`, REST API requests will return 404.

**Quick OAuth Test:**

```bash
# Step 1: Generate PKCE parameters
CODE_VERIFIER=$(openssl rand -hex 64)
CODE_CHALLENGE=$(echo -n "$CODE_VERIFIER" | openssl dgst -sha256 -binary | base64 | tr '+/' '-_' | tr -d '=')
STATE=$(openssl rand -hex 16)

# Step 2: Open authorization URL in browser
echo "http://yoursite.com/?goldtwmcp_oauth_authorize=1&response_type=code&client_id=claude-ai&redirect_uri=urn:ietf:wg:oauth:2.0:oob&scope=read%20write&state=$STATE&code_challenge=$CODE_CHALLENGE&code_challenge_method=S256"

# Step 3: Exchange authorization code for token
curl -X POST "http://yoursite.com/wp-json/goldt-webmcp-bridge/v1/oauth/token" \
  -H "Content-Type: application/json" \
  -d "{
    \"grant_type\": \"authorization_code\",
    \"client_id\": \"claude-ai\",
    \"code\": \"PASTE_CODE_FROM_BROWSER\",
    \"redirect_uri\": \"urn:ietf:wg:oauth:2.0:oob\",
    \"code_verifier\": \"$CODE_VERIFIER\"
  }"

# Step 4: Use the access token
curl -X POST "http://yoursite.com/wp-json/goldt-webmcp-bridge/v1/tools/wordpress.getCurrentUser" \
  -H "Authorization: Bearer YOUR_ACCESS_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{}'
```

**For detailed examples**, see the plugin README.md file.

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

See the plugin documentation for more details.

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
* **"PKCE verification failed"** - Code verifier doesn't match code challenge
* **"access_denied"** - User is blocked (check Settings → OAuth Tokens)
* **"Token expired"** - Access token expired after 1 hour, request new authorization
* **"Rate limit exceeded"** - Wait for retry period or increase limits in Settings
* **REST API 404** - Using PHP built-in server? Must use `php -S 0.0.0.0:8888 router.php`

Enable WordPress debug mode and check `wp-content/debug.log` for details.

= Where can I get support? =

* **Community**: WordPress.org support forums

== Screenshots ==

1. Dashboard - System status and quick access to settings
2. Settings - Security controls, rate limits, user management
3. WebMCP Manifest - Auto-generated tool definitions
4. API Response - Example JSON response from API call

== Changelog ==

= 0.2.0 - 2026-02-22 =
**BREAKING CHANGE: OAuth 2.0 Migration**
* **Security**: Migrated from username/password to OAuth 2.0 Authorization Code Flow with PKCE
* **Added**: 8 pre-registered OAuth clients (claude-ai, chatgpt, gemini, grok, perplexity, copilot, meta-ai, deepseek)
* **Added**: OAuth consent screen for user authorization
* **Added**: Refresh token support (30-day validity for seamless token renewal)
* **Added**: Admin UI for OAuth token management (GoldT WebMCP → OAuth Tokens)
* **Added**: router.php for PHP built-in server support
* **Removed**: Direct username/password authentication endpoint (`/auth/login`)
* **Security**: Authorization codes are one-time use with 10 minute expiry
* **Security**: Access tokens expire after 1 hour
* **Security**: Refresh tokens expire after 30 days
* **Security**: PKCE (S256) required to prevent authorization code interception
* **Fixed**: Timezone handling in token expiration validation
* **Improved**: Bearer token authentication middleware
* **Note**: Existing username/password integrations must migrate to OAuth 2.0

= 0.1.2 - 2026-02-19 =
* Added: Translation support for 12 languages (ar, de_DE, en_US, es_ES, fr_FR, he_IL, it_IT, ja, nl_NL, pt_BR, ru_RU, zh_CN)
* Improved: Full Hebrew translation completed with emoji and placeholder support
* Fix: Include composer.json and composer.lock in WordPress.org builds
* Improved: Ready for community translations via translate.wordpress.org

= 0.1.1 - 2026-02-16 =
* Include vendor dependencies (firebase/php-jwt, predis/predis) in distribution
* Update installation documentation - no manual composer install required
* Add .distignore for WordPress.org distribution
* Add dependency check with admin notice
* Improve plugin distribution workflow

= 0.1.0 - 2025-02-13 =
* Initial public release
* WebMCP protocol support
* Direct username/password authentication with JWT
* 5 WordPress core tools (searchPosts, getPost, searchPages, getPage, getCurrentUser)
* Rate limiting (Redis + WordPress transients)
* Security controls (Rotate JWT Secret, User Blacklist)
* Automatic manifest generation
* Production ready

== Upgrade Notice ==

= 0.2.0 =
**BREAKING CHANGE**: OAuth 2.0 required. Username/password authentication removed. Existing integrations must migrate to OAuth 2.0 Authorization Code Flow. See changelog and documentation for migration guide.

= 0.1.2 =
Translation infrastructure added! Plugin now supports 12 languages with full Hebrew translation available.

= 0.1.1 =
Improved distribution - all dependencies now bundled. No manual setup required!

== Feedback & Roadmap ==

**This is an early release (v0.1.0)** and we want your input!

* 💡 **Feature Requests** - What tools do you need?
* 🐛 **Bug Reports** - Found an issue?
* ⭐ **Vote on Features** - Star what you want most

**How to provide feedback:**
* WordPress.org: Support forum

Your feedback directly influences development priorities!

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

* Built with [firebase/php-jwt](https://github.com/firebase/php-jwt)
* Optional [predis/predis](https://github.com/predis/predis) support
* Compliant with WebMCP protocol specification

**Made with ❤️ for the WordPress & AI community**
