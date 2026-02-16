=== AI Connect ===
Contributors: chgold
Tags: ai, ai-agent, webmcp, rest-api, artificial-intelligence
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.1.1
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Connect AI agents (ChatGPT, Claude) to your WordPress site with simple, secure authentication.

== Description ==

**AI Connect** enables AI agents to interact with your WordPress content through secure JWT authentication using the WebMCP protocol.

Perfect for AI-powered customer support, automated content analysis, intelligent search, and custom AI integrations.

= ✨ Features =

* **WebMCP Protocol Support** - Industry-standard AI integration
* **Simple Authentication** - Direct username/password login, no complex OAuth setup
* **5 WordPress Tools** - Search/get posts, pages, and user info
* **Rate Limiting** - Prevent abuse (50 req/min default)
* **Security Controls** - Rotate JWT secret, block specific users
* **Zero Configuration** - Works out of the box
* **Extensible** - Add custom tools via developer hooks

= 🎯 Quick Start for AI Users =

**Using ChatGPT or Claude?**

Tell your AI agent:
> "I want to connect you to my WordPress site at https://mysite.com using AI Connect plugin. The manifest is at /wp-json/ai-connect/v1/manifest"

The AI will ask for your WordPress username and password to authenticate.

= 🛠️ Available Tools =

1. **wordpress.searchPosts** - Search posts with filters
2. **wordpress.getPost** - Get single post by ID or slug
3. **wordpress.searchPages** - Search pages
4. **wordpress.getPage** - Get single page by ID or slug
5. **wordpress.getCurrentUser** - Get authenticated user info

= 🔒 How Authentication Works =

**Simple and Universal:**

Any AI agent can connect to your WordPress site using:
* WordPress username
* WordPress password

**The AI agent operates as the user who authenticated:**
* The agent receives a JWT token linked to that user's ID
* All API requests run with that user's permissions
* The agent respects WordPress user capabilities

**Examples:**

**If Administrator logs in:**
* ✅ Sees all posts (including drafts, private)
* ✅ Full access based on admin capabilities

**If Subscriber logs in:**
* ✅ Sees only published content
* ❌ Cannot see drafts or private content

**Note:** All API calls require authentication, even for reading public content. This prevents abuse and enables rate limiting.

= 🔐 Admin Controls =

**For Site Administrators:**

Go to **AI Connect → Settings** to manage security:

* **Rotate JWT Secret** - Emergency disconnect all AI agents (all tokens become invalid)
* **Block Users** - Revoke access for specific WordPress users
* **Rate Limits** - Configure request limits (default: 50/min, 1000/hour)

= 🗺️ Future Development =

We're actively working on new features and improvements!

**We want your feedback:**
* 💡 What features do you need most?
* 🐛 Found a bug? Let us know!

**How to provide feedback:**
* GitHub: https://github.com/chgold/ai-connect/issues/new
* WordPress.org: Support forum

Your feedback directly influences what we build next!

== Installation ==

= Automatic Installation =

1. Go to **Plugins → Add New** in WordPress admin
2. Search for "AI Connect"
3. Click **Install Now** and then **Activate**

= Manual Installation =

1. Download the plugin zip file
2. Go to **Plugins → Add New → Upload Plugin**
3. Upload the zip file and click **Install Now**
4. Activate the plugin

**Note:** All required dependencies are included:
* `firebase/php-jwt` (v6.10.0) - JWT token handling
* `predis/predis` (v2.4.1) - Redis client for rate limiting (optional)

No manual `composer install` required!

= Setup =

**No setup required!** The plugin works immediately after activation.

**Optional:** Configure rate limits in **AI Connect → Settings**

= Testing Your Setup =

**Development Note:**

If using PHP built-in server or environments where pretty permalinks don't work with dots in URLs, use the `index.php?rest_route=` format:

```bash
# Development format (PHP built-in server)
curl "http://localhost:8888/index.php?rest_route=/ai-connect/v1/tools/wordpress.searchPosts"
```

On production servers with Apache/Nginx, use the standard format:

```bash
# Production format (Apache/Nginx)
curl "http://yoursite.com/wp-json/ai-connect/v1/tools/wordpress.searchPosts"
```

**Quick cURL Test:**

```bash
# Step 1: Login and get access token
curl -X POST "http://yoursite.com/wp-json/ai-connect/v1/auth/login" \
  -H "Content-Type: application/json" \
  -d '{"username":"your_username","password":"your_password"}'

# You'll receive an access_token

# Step 2: Use the access token
curl -X POST "http://yoursite.com/wp-json/ai-connect/v1/tools/wordpress.getCurrentUser" \
  -H "Authorization: Bearer YOUR_ACCESS_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{}'
```

**For detailed examples** (JavaScript, Python), see [README.md](https://github.com/chgold/ai-connect/blob/main/README.md)

== Frequently Asked Questions ==

= What is WebMCP? =

WebMCP (Web Model Context Protocol) is a standardized protocol for connecting AI agents to web services. It defines how AI assistants discover, authenticate with, and execute tools on web platforms.

= Does this work with ChatGPT and Claude? =

Yes! AI Connect works with any AI platform that supports REST APIs. This includes ChatGPT (OpenAI), Claude (Anthropic), Make.com, Zapier, and custom applications.

= Why does reading public content require authentication? =

All API calls require authentication for security:
* **Rate Limiting** - Prevents spam and abuse
* **Monitoring** - Track who uses your API
* **Security** - Protects against data scraping and DDoS attacks

This is the industry standard (Twitter, GitHub, Google APIs all require auth).

**Exception:** The manifest endpoint is public (no auth needed).

= How does the AI agent authentication work? =

**User authentication:** The AI agent operates as the WordPress user who authenticated.

When a user provides their credentials to an AI agent:
* The agent receives a JWT token linked to that user's ID
* All API requests run with that user's permissions
* The agent inherits the user's capabilities

**Security:** The agent is NOT a superuser - it respects WordPress user capabilities.

= Is Redis required? =

No, Redis is optional. The plugin works perfectly with WordPress transients. However, Redis is recommended for high-traffic sites (>1,000 requests/day) as it provides better rate limiting performance.

= Can I add custom tools? =

Yes! AI Connect is extensible. Use WordPress hooks to add custom tools:

```php
add_action('ai_connect_register_tools', function($manifest) {
    $manifest->register_tool('mysite.getStats', [...]);
});
```

[See full documentation →](https://github.com/chgold/ai-connect)

= How long do tokens last? =

* **Access Token**: 1 hour (3600 seconds)
* **Refresh Token**: 30 days (2,592,000 seconds)

Use the refresh token to get a new access token without re-authentication.

= Can I revoke access? =

Yes! Go to **AI Connect → Settings** and:

**For all users:**
* Click **"Rotate JWT Secret"** to invalidate all tokens immediately

**For specific user:**
* Enter user ID in "Block User" section
* User cannot authenticate or use existing tokens

= How do I troubleshoot authentication errors? =

**Common issues:**

* **"authentication_failed"** - Check username and password
* **"access_denied"** - User is blocked (check Settings)
* **"Token expired"** - Use refresh token to get new access token
* **"Rate limit exceeded"** - Wait for retry period or increase limits in Settings
* **REST API 404** - Flush permalinks (Settings → Permalinks → Save)

Enable WordPress debug mode and check `wp-content/debug.log` for details.

= Where can I get support? =

* **Documentation**: https://github.com/chgold/ai-connect
* **Bug Reports**: https://github.com/chgold/ai-connect/issues
* **Community**: WordPress.org support forums

== Screenshots ==

1. Dashboard - System status and quick access to settings
2. Settings - Security controls, rate limits, user management
3. WebMCP Manifest - Auto-generated tool definitions
4. API Response - Example JSON response from API call

== Changelog ==

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

= 0.1.1 =
Improved distribution - all dependencies now bundled. No manual setup required!

= 0.1.0 =
Initial release. Install and start connecting AI agents to your WordPress site!

== Feedback & Roadmap ==

**This is an early release (v0.1.0)** and we want your input!

* 💡 **Feature Requests** - What tools do you need?
* 🐛 **Bug Reports** - Found an issue?
* ⭐ **Vote on Features** - Star what you want most

**How to provide feedback:**
* GitHub: https://github.com/chgold/ai-connect/issues/new
* WordPress.org: Support forum

Your feedback directly influences development priorities!

== Privacy Policy ==

AI Connect does not collect, store, or transmit any personal data to external services. All API requests are handled locally on your WordPress installation.

**Data stored locally:**
* JWT secret (for token signing)
* Access tokens (temporary, 1 hour expiry)
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

== Links ==

* [GitHub Repository](https://github.com/chgold/ai-connect)
* [Documentation](https://github.com/chgold/ai-connect)
* [Issue Tracker](https://github.com/chgold/ai-connect/issues)

---

**Made with ❤️ for the WordPress & AI community**
