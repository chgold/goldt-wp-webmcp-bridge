# GoldT WebMCP Bridge - WebMCP Bridge for WordPress

![WordPress Plugin Version](https://img.shields.io/badge/version-0.3.0-blue.svg)
![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue.svg)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)
![License](https://img.shields.io/badge/license-GPL--3.0-green.svg)

Bridge for 8 AI agents (Claude, ChatGPT, Grok, more) via WebMCP with OAuth 2.0 and optional translation

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

## Claude Desktop / Cursor / OpenCode — MCP Setup

**Step 1** — Install [webmcp-client](https://www.npmjs.com/package/webmcp-client):
```bash
npm install -g webmcp-client
```

**Step 2** — Add to `claude_desktop_config.json` (set once, never change):
```json
{
  "mcpServers": {
    "webmcp": {
      "command": "webmcp-client",
      "env": {
        "NODE_TLS_REJECT_UNAUTHORIZED": "0"
      }
    }
  }
}
```

> **Note for NetFree / corporate SSL proxy users:** The `NODE_TLS_REJECT_UNAUTHORIZED: "0"` setting bypasses SSL certificate verification. Required for networks that intercept HTTPS traffic.

**Step 3** — Use the WordPress admin page to generate a token and connect your site.

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

## ⚙️ Admin Settings

Navigate to **WordPress Admin → GoldT WebMCP → Settings** to configure the plugin.

### Translation Provider

Controls how the plugin handles content translation requests.

| Option | Description |
|--------|-------------|
| `ai_self` | AI agent translates on its own (default) |
| `mymemory` | Plugin calls MyMemory API and returns translated text |
| `disabled` | Translation tools are not exposed in the manifest |

When set to `mymemory`, the `translation.translate` and `translation.getSupportedLanguages` tools appear in the manifest. When set to `ai_self` or `disabled`, those tools are hidden.

### Rate Limiting

Default limits (per user):
- **50 requests per minute**
- **1,000 requests per hour**

Adjust both values in **GoldT WebMCP → Settings**.

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

## 🌐 Translation Module

Available when **Translation Provider** is set to `mymemory` in Admin Settings. These tools are hidden from the manifest when the provider is `ai_self` or `disabled`.

### 6. translation.translate

Translate text using the MyMemory API.

**Parameters:**
- `text` (string, required) - Text to translate
- `source_lang` (string, required) - Source language code (e.g. `en`, `he`, `fr`)
- `target_lang` (string, required) - Target language code

**Example:**
```bash
curl -X POST "http://yoursite.com/wp-json/goldt-webmcp-bridge/v1/tools/translation.translate" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "text": "Hello, world!",
    "source_lang": "en",
    "target_lang": "he"
  }'
```

**Response:**
```json
{
  "success": true,
  "data": {
    "translated_text": "שלום, עולם!",
    "source_lang": "en",
    "target_lang": "he"
  }
}
```

---

### 7. translation.getSupportedLanguages

Returns the list of language codes supported by the MyMemory API.

**Example:**
```bash
curl -X POST "http://yoursite.com/wp-json/goldt-webmcp-bridge/v1/tools/translation.getSupportedLanguages" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{}'
```

**Response:**
```json
{
  "success": true,
  "data": {
    "languages": ["en", "he", "fr", "de", "es", "it", "pt", "ru", "ar", "zh", "ja", "ko"]
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
**Solution:** The `client_id` parameter is optional — omitting it defaults to `claude`, which automatically resolves to `claude-ai`. Fuzzy matching recognizes common variants with underscores or dashes (e.g. `claude`, `claude_ai`, `claude-ai`, `gemini_client`, `gemini-client` all work).

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

### Version 0.3.0 - 2026-03-19
* **Added:** Translation Provider setting (AI Self-Translate, MyMemory API, or Disabled)
* **Added:** TranslationModule with MyMemory API integration (translate, getSupportedLanguages)
* **Added:** Dynamic manifest instructions to prevent AI agents from inventing capabilities
* **Improved:** OAuth client_id is now optional (defaults to 'claude')
* **Improved:** Fuzzy client_id matching — recognizes variants with underscores and dashes (e.g. `claude`, `claude_ai`, `claude-ai`, `gemini_client`, `gemini-client`)
* **Improved:** Specific OAuth error messages instead of generic errors

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

## 🔧 Troubleshooting

### Common Issues & Solutions

#### "Missing Dependencies" Error

**Symptoms:**
- Red error notice in WordPress admin
- Plugin appears active but doesn't work
- REST API endpoints return 404

**Causes & Solutions:**

1. **vendor/autoload.php missing**
   
   **Solution Option 1 (Recommended):**
   - Download the complete plugin ZIP with dependencies from [GitHub Releases](https://github.com/chgold/goldt-wp-webmcp-bridge/releases)
   - Delete the incomplete plugin folder
   - Upload and activate the complete version
   
   **Solution Option 2 (Advanced):**
   ```bash
   cd /path/to/wp-content/plugins/goldt-webmcp-bridge
   composer install --no-dev
   ```

2. **exec() function disabled on server**
   
   The plugin tries to auto-install composer dependencies during activation. If your hosting provider disables the `exec()` PHP function, this will fail silently.
   
   **Solution:** Download the complete plugin with vendor/ included (see Solution Option 1 above)

3. **Composer not available on server**
   
   Many shared hosting plans don't have composer installed.
   
   **Solution:** Download the complete plugin with vendor/ included (see Solution Option 1 above)

4. **Plugin directory not writable**
   
   The server can't create the vendor/ folder due to file permissions.
   
   **Solution:** 
   - Contact your hosting provider to fix permissions, OR
   - Download the complete plugin with vendor/ included (see Solution Option 1 above)

**How to diagnose:**
1. Go to **AI Connect → Settings** in WordPress admin
2. Check the "Environment Status" table
3. Look for red ✗ marks - they show exactly what's wrong

---

#### "Database Tables Missing" Error

**Symptoms:**
- Red error notice: "OAuth database tables were not created"
- OAuth authorization fails

**Solution:**
1. Deactivate the plugin
2. Reactivate the plugin
3. Check **AI Connect → Settings** to verify "OAuth Tables: ✓ Created"

If the problem persists:
- Your database user may not have CREATE TABLE permissions
- Contact your hosting provider or check your wp-config.php database settings

---

#### OAuth Authorization Fails

**Symptoms:**
- Clicking "Authorize" button does nothing
- Redirect loop during OAuth flow
- "invalid_client" or "invalid_request" errors

**Solutions:**

1. **Clear WordPress rewrite rules:**
   - Go to **Settings → Permalinks**
   - Click "Save Changes" (no need to change anything)
   - This flushes and regenerates rewrite rules

2. **Check OAuth tables:**
   - Go to **AI Connect → Settings**
   - Verify "OAuth Tables: ✓ Created"

3. **Verify client exists:**
   - The default clients (claude-ai, chatgpt, etc.) are auto-created during activation
   - If they're missing, deactivate and reactivate the plugin

---

#### REST API Endpoints Return 404

**Symptoms:**
- `/wp-json/goldt-webmcp-bridge/v1/manifest` returns 404
- Tools API calls fail with 404

**Solutions:**

1. **Flush permalinks:**
   - Go to **Settings → Permalinks**  
   - Click "Save Changes"

2. **Check if plugin is fully activated:**
   - Go to **Plugins** page
   - Deactivate and reactivate "GoldT WebMCP Bridge"

3. **Check WordPress REST API:**
   ```bash
   curl http://yoursite.com/wp-json/
   ```
   If this also returns 404, your WordPress REST API is disabled or blocked.
   - Check for conflicting security plugins
   - Check .htaccess rules
   - Check server configuration

---

### Still Having Issues?

**Before asking for help, please gather this information:**

1. Go to **AI Connect → Settings** in WordPress admin
2. Take a screenshot of the "Environment Status" table
3. Check browser console for JavaScript errors (F12 → Console)
4. Check WordPress debug log (if enabled)

**Get support:**
- [GitHub Issues](https://github.com/chgold/goldt-wp-webmcp-bridge/issues) - Bug reports and technical issues
- [WordPress.org Support Forum](https://wordpress.org/support/plugin/goldt-webmcp-bridge/) - General questions

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

## 🌐 External Services

This plugin optionally uses the **MyMemory Translation API** when the Translation Provider setting is set to "MyMemory API".

### MyMemory API

| | |
|---|---|
| **What it is** | A free machine translation service |
| **When used** | Only when an AI agent calls `translation.translate` AND the plugin settings have "MyMemory API" selected |
| **Data sent** | Text to be translated + source/target language codes |
| **Default** | **Disabled** — default provider is "AI Self-Translate" (no external requests) |
| **Terms of Service** | https://mymemory.translated.net/ |
| **Privacy Policy** | https://mymemory.translated.net/ |

If "MyMemory API" is not selected, no data is sent to any external service.

---

## 📄 License

GPL-3.0-or-later

---

**Made with ❤️ for the WordPress & AI community**
