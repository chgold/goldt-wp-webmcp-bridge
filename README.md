# GoldT WebMCP Bridge - WebMCP Bridge for WordPress

![WordPress Plugin Version](https://img.shields.io/badge/version-0.2.0-blue.svg)
![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue.svg)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)
![License](https://img.shields.io/badge/license-GPL--3.0-green.svg)

Bridge for 8 AI agents (Claude, ChatGPT, Grok, more) via WebMCP with OAuth 2.0

**Secure authentication:** Uses OAuth 2.0 (the same security standard as Google, Facebook, and GitHub) - your passwords stay safe and private!

---

## 🚀 Quick Start

### Installation

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate through WordPress admin

**That's it!** No configuration needed.

**Note:** The plugin includes all required dependencies:
- `predis/predis` (v3.4.0) - Redis client for rate limiting (optional)

No manual `composer install` required - everything is bundled!

---

## 🤖 For AI Agent Users

**Already installed the plugin? Here's all you need!**

Just copy and paste this instruction to your AI agent (Claude, ChatGPT, etc.):

```
Connect to my WordPress site and follow the instructions at:
https://github.com/chgold/goldt-wp-webmcp-bridge
```

The AI agent will handle the rest - OAuth authorization, API connection, and tool discovery.

**That's it!** No technical knowledge required. ✨

---

## 🔐 OAuth 2.0 Authentication Guide

### How It Works

GoldT WebMCP Bridge uses **OAuth 2.0 Authorization Code Flow with PKCE** - the industry standard for secure API authentication:

1. AI agent requests authorization with a code challenge (PKCE)
2. User approves in browser → receives one-time authorization code
3. Agent exchanges code for access token (with code verifier)
4. Agent uses token for API calls

**Pre-registered clients** (ready to use!):
- `claude-ai` - Claude AI (Anthropic)
- `chatgpt` - ChatGPT (OpenAI)
- `gemini` - Gemini (Google)
- `grok` - Grok (xAI)
- `perplexity` - Perplexity AI
- `copilot` - Microsoft Copilot
- `meta-ai` - Meta AI (Facebook)
- `deepseek` - DeepSeek AI

---

### Step 1: Generate PKCE Parameters

```bash
# Code verifier (random 128 character string)
CODE_VERIFIER=$(openssl rand -hex 64)

# Code challenge (SHA256 hash of verifier)
CODE_CHALLENGE=$(echo -n "$CODE_VERIFIER" | openssl dgst -sha256 -binary | base64 | tr '+/' '-_' | tr -d '=')

# State (for CSRF protection)
STATE=$(openssl rand -hex 16)
```

---

### Step 2: Authorization URL

Direct user to this URL in their browser:

```
http://yoursite.com/?goldtwmcp_oauth_authorize=1
  &response_type=code
  &client_id=claude-ai
  &redirect_uri=urn:ietf:wg:oauth:2.0:oob
  &scope=read%20write
  &state=YOUR_STATE
  &code_challenge=YOUR_CODE_CHALLENGE
  &code_challenge_method=S256
```

**Available scopes:**
- `read` - Read posts, pages, and content
- `write` - Create and update posts and pages  
- `delete` - Delete posts and pages
- `manage_users` - View and manage user accounts

User will see a consent screen and approve. They'll receive an **authorization code** (valid for 10 minutes).

---

### Step 3: Exchange Code for Token

**Request:**
```bash
curl -X POST "http://yoursite.com/wp-json/goldt-webmcp-bridge/v1/oauth/token" \
  -H "Content-Type: application/json" \
  -d '{
    "grant_type": "authorization_code",
    "client_id": "claude-ai",
    "code": "AUTHORIZATION_CODE_HERE",
    "redirect_uri": "urn:ietf:wg:oauth:2.0:oob",
    "code_verifier": "YOUR_CODE_VERIFIER"
  }'
```

**Response:**
```json
{
  "access_token": "wpc_c6c9f8398c5f7921713011d19676ee2f81470cf7ec7c71ce91925cd129853dd3",
  "token_type": "Bearer",
  "expires_in": 3600,
  "refresh_token": "wpr_8a7b6c5d4e3f2a1b9c8d7e6f5a4b3c2d1e0f9a8b7c6d5e4f3a2b1c0d9e8f7a6b",
  "refresh_token_expires_in": 2592000,
  "scope": "read write"
}
```

⚠️ **Security Notes:** 
- Authorization codes are **one-time use** and expire in 10 minutes
- Access tokens expire after **1 hour**
- Refresh tokens expire after **30 days**
- PKCE verification ensures the token can only be claimed by the client that initiated the flow
- **Save your refresh token** - you'll need it to get new access tokens without re-authenticating!

---

### Step 4: Use the API

**Request:**
```bash
curl -X POST "http://yoursite.com/wp-json/goldt-webmcp-bridge/v1/tools/wordpress.searchPosts" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_ACCESS_TOKEN" \
  -d '{
    "search": "hello",
    "limit": 5
  }'
```

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "title": "Hello World",
      "content": "Welcome to WordPress...",
      "excerpt": "Welcome...",
      "author": {
        "id": "1",
        "name": "admin"
      },
      "date": "2024-01-15T10:30:00",
      "modified": "2024-01-15T10:30:00",
      "status": "publish",
      "url": "http://yoursite.com/hello-world",
      "categories": [],
      "tags": []
    }
  ]
}
```

---

### Step 4: Refresh Access Token (After 1 Hour)

Access tokens expire after 1 hour. Use your refresh token to get a new access token **without** re-authenticating:

**Request:**
```bash
curl -X POST "http://yoursite.com/wp-json/goldt-webmcp-bridge/v1/oauth/token" \
  -H "Content-Type: application/json" \
  -d '{
    "grant_type": "refresh_token",
    "client_id": "claude-ai",
    "refresh_token": "wpr_8a7b6c5d4e3f2a1b9c8d7e6f5a4b3c2d1e0f9a8b7c6d5e4f3a2b1c0d9e8f7a6b"
  }'
```

**Response:**
```json
{
  "access_token": "wpc_NEW_ACCESS_TOKEN_HERE",
  "token_type": "Bearer",
  "expires_in": 3600,
  "refresh_token": "wpr_NEW_REFRESH_TOKEN_HERE",
  "refresh_token_expires_in": 2592000,
  "scope": "read write"
}
```

**Important:**
- The old access token and refresh token are **automatically revoked**
- You receive **new** access token AND refresh token
- Refresh tokens are valid for 30 days
- If the refresh token expires, user must re-authorize from Step 1

---

### Step 5: Revoke Token (Optional)

Revoke an access token when you're done or if it's compromised:

**Request:**
```bash
curl -X POST "http://yoursite.com/wp-json/goldt-webmcp-bridge/v1/oauth/revoke" \
  -H "Content-Type: application/json" \
  -d '{
    "token": "wpc_c6c9f8398c5f7921713011d19676ee2f81470cf7ec7c71ce91925cd129853dd3"
  }'
```

**Response:**
```json
{
  "success": true,
  "message": "Token revoked successfully"
}
```

**Note:** Revoking an access token also revokes its associated refresh token.

---

## 🛠️ Available Tools

### 1. wordpress.searchPosts

Search WordPress posts with filters.

**Parameters:**
- `search` (string, optional) - Search query
- `category` (string, optional) - Category slug
- `tag` (string, optional) - Tag slug
- `author` (integer, optional) - Author ID
- `status` (string, optional) - Post status (default: `publish`)
- `limit` (integer, optional) - Max results (default: 10)
- `offset` (integer, optional) - Skip results (default: 0)

**Example:**
```bash
curl -X POST "http://yoursite.com/wp-json/goldt-webmcp-bridge/v1/tools/wordpress.searchPosts" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "search": "technology",
    "category": "news",
    "limit": 10
  }'
```

---

### 2. wordpress.getPost

Get a single post by ID or slug.

**Parameters:**
- `identifier` (integer|string, required) - Post ID or slug

**Example:**
```bash
curl -X POST "http://yoursite.com/wp-json/goldt-webmcp-bridge/v1/tools/wordpress.getPost" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"identifier": 123}'
```

---

### 3. wordpress.searchPages

Search WordPress pages.

**Parameters:**
- `search` (string, optional) - Search query
- `parent` (integer, optional) - Parent page ID
- `status` (string, optional) - Page status (default: `publish`)
- `limit` (integer, optional) - Max results (default: 10)

**Example:**
```bash
curl -X POST "http://yoursite.com/wp-json/goldt-webmcp-bridge/v1/tools/wordpress.searchPages" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"search": "about", "limit": 10}'
```

---

### 4. wordpress.getPage

Get a single page by ID or slug.

**Parameters:**
- `identifier` (integer|string, required) - Page ID or slug

**Example:**
```bash
curl -X POST "http://yoursite.com/wp-json/goldt-webmcp-bridge/v1/tools/wordpress.getPage" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"identifier": "about-us"}'
```

---

### 5. wordpress.getCurrentUser

Get information about the authenticated user.

**Example:**
```bash
curl -X POST "http://yoursite.com/wp-json/goldt-webmcp-bridge/v1/tools/wordpress.getCurrentUser" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{}'
```

**Response:**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "username": "admin",
    "email": "admin@example.com",
    "display_name": "Admin User",
    "roles": ["administrator"],
    "capabilities": ["edit_posts", "delete_posts", "manage_options", ...]
  }
}
```

---

## 🔐 Admin Controls

### Security Features

Navigate to **WordPress Admin → GoldT WebMCP → Settings** to manage security:

#### 1. Rotate JWT Secret

**Emergency disconnect all AI agents:**
- Click "Rotate JWT Secret" button
- All existing access tokens become invalid immediately
- All users must re-authenticate

**When to use:**
- Security breach suspected
- Lost/stolen credentials
- Decommissioning integration

---

#### 2. Block User Access

**Revoke access for specific users:**
1. Go to **WordPress Admin → GoldT WebMCP → Settings**
2. Scroll to "Manage User Access" section
3. Enter WordPress user ID
4. Click "Block User"

**Result:** User cannot authenticate or use existing tokens, even if valid.

**To restore access:** Find the blocked user in the list and click "Restore Access".

---

## 📊 Rate Limiting

Default limits (per user):
- **50 requests per minute**
- **1,000 requests per hour**

**Configure in:** GoldT WebMCP → Settings

**Rate limit response:**
```json
{
  "code": "rate_limit_exceeded",
  "message": "Rate limit exceeded: 50 requests per minute",
  "data": {
    "status": 429,
    "retry_after": 45,
    "limit": 50,
    "current": 51
  }
}
```

---

## 🔐 Security Best Practices

### For Site Administrators

1. **Create dedicated AI user accounts** - Don't use your admin account
2. **Use Application Passwords** (WordPress 5.6+) - More secure than regular passwords
3. **Monitor blocked users list** - Revoke access when no longer needed
4. **Enable 2FA** - Additional layer of security (compatible plugins: Wordfence, iThemes Security)
5. **Use HTTPS** - Encrypt all traffic in production

### For Developers

1. **Store credentials securely** - Use environment variables, never hardcode
2. **Handle token expiry gracefully** - Implement automatic refresh
3. **Respect rate limits** - Cache responses when possible
4. **Use HTTPS endpoints** - Never send credentials over HTTP
5. **Rotate refresh tokens** - Get new ones periodically

---

## 🐛 Troubleshooting

### Common OAuth Errors

#### `"invalid_client"` - Invalid client_id
**Solution:** Use one of the pre-registered clients: `claude-ai`, `chatgpt`, or `gemini`

#### `"invalid_grant"` - Authorization code invalid
**Solution:** 
- Authorization codes are one-time use and expire after 10 minutes
- Request a new authorization code

#### `"PKCE verification failed"`
**Solution:** Ensure you're using the same `code_verifier` that generated the `code_challenge`

#### `"access_denied"` - User blocked
**Solution:** Check if user is in blacklist (GoldT WebMCP → OAuth Tokens)

#### `"Token expired"`
**Solution:** Access tokens expire after 1 hour. Request new authorization.

#### `"Rate limit exceeded"`
**Solution:** 
- Wait for retry period (check `retry_after` in response)
- Increase limits in **GoldT WebMCP → Settings**

#### REST API 404 errors
**Solution:** 
1. Go to **Settings → Permalinks**
2. Click **Save Changes** (flush rewrite rules)
3. Test again

---

## 🔧 For Developers

### Running Tests

```bash
cd wp-content/plugins/goldt-webmcp-bridge
./tests/test-endpoints.sh
```

This tests:
- All tool endpoints
- OAuth 2.0 Bearer token authentication
- Public vs protected endpoints
- Error handling

---

### Add Custom Tools

```php
add_action('goldtwmcp_register_modules', function($goldtwmcp_plugin) {
    $manifest = $goldtwmcp_plugin->get_manifest_instance();
    
    $manifest->register_tool('mysite.customTool', [
        'description' => 'My custom tool',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'param1' => [
                    'type' => 'string',
                    'description' => 'First parameter'
                ]
            ],
            'required' => ['param1']
        ]
    ]);
});
```

**Important:** Custom tools are preserved during plugin updates. Place your code in your theme's `functions.php` or a custom plugin to ensure it persists across updates.

---

## 📋 Changelog

### Version 0.2.0 - 2026-02-23
* **Security:** Migrated to OAuth 2.0 with PKCE for secure authentication
* **Added:** 8 pre-registered AI clients (Claude, ChatGPT, Gemini, and more)
* **Added:** Parameter validation and resource limits (prevents abuse)
* **Improved:** Security hardening - comprehensive input validation

### Version 0.1.2 - 2026-02-19
* **Added:** Translation support for 12 languages

### Version 0.1.1 - 2026-02-16
* **Improved:** Bundled all dependencies - no manual setup required

### Version 0.1.0 - 2025-02-13
* Initial public release
* WebMCP protocol support
* 5 WordPress core tools

---

## 💬 We Need Your Feedback!

**Help us build what YOU need:**

* 💡 **What tools would be most useful?** Tell us which WordPress features you'd like AI agents to access
* 🐛 **Found a bug?** Report it so we can fix it quickly
* ⭐ **Feature requests** - We prioritize based on community feedback

**How to provide feedback:**
* [GitHub Issues](https://github.com/chgold/goldt-wp-webmcp-bridge/issues) - For bug reports and feature requests
* [WordPress.org Support Forum](https://wordpress.org/support/plugin/goldt-webmcp-bridge/) - For questions and discussions

Your feedback directly shapes the future of this plugin!

---

## 🤝 Contributing

**This plugin is maintained by a single developer.**

To ensure code quality and maintain a consistent architecture, 
we do not accept code contributions (Pull Requests) at this time.

**How you can help:**
- 🐛 **Report bugs** - Open an issue with reproduction steps
- 💡 **Request features** - Tell us what would make the plugin better
- ⭐ **Spread the word** - Rate, review, and share if you find it useful
- 📖 **Improve docs** - Suggest clarifications or corrections

Thank you for your understanding!

---

## 📄 License

GPL-3.0-or-later

---

**Made with ❤️ for the WordPress & AI community**
