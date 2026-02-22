# AI Connect - WebMCP Bridge for WordPress

![WordPress Plugin Version](https://img.shields.io/badge/version-0.1.2-blue.svg)
![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue.svg)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)
![License](https://img.shields.io/badge/license-GPL--3.0-green.svg)

Connect AI agents (ChatGPT, Claude, or any custom AI) to your WordPress site with **secure OAuth 2.0 authentication** using the WebMCP protocol.

**Secure by design:** OAuth 2.0 Authorization Code Flow with PKCE - no passwords transmitted over the wire!

---

## 🚀 Quick Start

### Installation

1. Upload the plugin to `/wp-content/plugins/ai-connect/`
2. Activate through WordPress admin
3. **For local development**: Start server with `php -S 0.0.0.0:8888 router.php` (see [Development Setup](#-development-setup))

**That's it!** No configuration needed.

**Note:** The plugin includes all required dependencies:
- `firebase/php-jwt` (v6.10.0) - JWT token handling  
- `predis/predis` (v2.4.1) - Redis client for rate limiting (optional)

No manual `composer install` required - everything is bundled!

---

## 🔐 OAuth 2.0 Authentication Guide

### How It Works

AI Connect uses **OAuth 2.0 Authorization Code Flow with PKCE** - the industry standard for secure API authentication:

1. AI agent requests authorization with a code challenge (PKCE)
2. User approves in browser → receives one-time authorization code
3. Agent exchanges code for access token (with code verifier)
4. Agent uses token for API calls

**Pre-registered clients**: `claude-ai`, `chatgpt`, `gemini` - ready to use!

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
http://yoursite.com/?ai_connect_oauth_authorize=1
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
curl -X POST "http://yoursite.com/wp-json/ai-connect/v1/oauth/token" \
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
  "scope": "read write"
}
```

⚠️ **Security Notes:** 
- Authorization codes are **one-time use** and expire in 10 minutes
- Access tokens expire after 1 hour
- PKCE verification ensures the token can only be claimed by the client that initiated the flow

---

### Step 2: Use the API

**Request:**
```bash
curl -X POST "http://yoursite.com/wp-json/ai-connect/v1/tools/wordpress.searchPosts" \
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

### Step 3: Refresh Token (After 1 Hour)

Access tokens expire after 1 hour. Use your refresh token to get a new one:

**Request:**
```bash
curl -X POST "http://yoursite.com/wp-json/ai-connect/v1/oauth/refresh" \
  -H "Content-Type: application/json" \
  -d '{
    "refresh_token": "def50200a1b2c3..."
  }'
```

**Response:**
```json
{
  "access_token": "NEW_ACCESS_TOKEN",
  "token_type": "Bearer",
  "expires_in": 3600
}
```

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
curl -X POST "http://yoursite.com/wp-json/ai-connect/v1/tools/wordpress.searchPosts" \
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
curl -X POST "http://yoursite.com/wp-json/ai-connect/v1/tools/wordpress.getPost" \
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
curl -X POST "http://yoursite.com/wp-json/ai-connect/v1/tools/wordpress.searchPages" \
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
curl -X POST "http://yoursite.com/wp-json/ai-connect/v1/tools/wordpress.getPage" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"identifier": "about-us"}'
```

---

### 5. wordpress.getCurrentUser

Get information about the authenticated user.

**Example:**
```bash
curl -X POST "http://yoursite.com/wp-json/ai-connect/v1/tools/wordpress.getCurrentUser" \
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

Navigate to **WordPress Admin → AI Connect → Settings** to manage security:

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
1. Go to **AI Connect → Settings → Manage User Access**
2. Enter WordPress user ID
3. Click "Block User"

**Result:** User cannot authenticate or use existing tokens, even if valid.

**To restore access:** Click "Restore Access" next to blocked user.

---

## 📊 Rate Limiting

Default limits (per user):
- **50 requests per minute**
- **1,000 requests per hour**

**Configure in:** AI Connect → Settings

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
**Solution:** Check if user is in blacklist (AI Connect → OAuth Tokens)

#### `"Token expired"`
**Solution:** Access tokens expire after 1 hour. Request new authorization.

#### `"Rate limit exceeded"`
**Solution:** 
- Wait for retry period (check `retry_after` in response)
- Increase limits in **AI Connect → Settings**

#### REST API 404 errors
**Solution:** 
1. Go to **Settings → Permalinks**
2. Click **Save Changes** (flush rewrite rules)
3. Test again

---

## 🔧 Development Setup

### Local Development with PHP Built-in Server

**Important:** PHP's built-in server requires a router script for WordPress REST API to work correctly.

```bash
# Start server with router (REQUIRED for REST API)
cd /var/www/wp
php -S 0.0.0.0:8888 router.php
```

**Why is `router.php` needed?**
- PHP built-in server doesn't support WordPress permalinks/rewrite rules by default
- Without it, requests to `/wp-json/...` return 404
- The router forwards all non-static requests through WordPress `index.php`

**Production:** On Apache/Nginx, `.htaccess` or nginx config handle this automatically - `router.php` is only for local development.

---

### Testing OAuth Flow Locally

```bash
# 1. Generate PKCE parameters
CODE_VERIFIER=$(openssl rand -hex 64)
CODE_CHALLENGE=$(echo -n "$CODE_VERIFIER" | openssl dgst -sha256 -binary | base64 | tr '+/' '-_' | tr -d '=')
STATE=$(openssl rand -hex 16)

# 2. Open authorization URL in browser (replace localhost:8888 with your server)
echo "http://localhost:8888/?ai_connect_oauth_authorize=1&response_type=code&client_id=claude-ai&redirect_uri=urn:ietf:wg:oauth:2.0:oob&scope=read%20write&state=$STATE&code_challenge=$CODE_CHALLENGE&code_challenge_method=S256"

# 3. After approval, exchange code for token
curl -X POST "http://localhost:8888/wp-json/ai-connect/v1/oauth/token" \
  -H "Content-Type: application/json" \
  -d "{
    \"grant_type\": \"authorization_code\",
    \"client_id\": \"claude-ai\",
    \"code\": \"PASTE_CODE_HERE\",
    \"redirect_uri\": \"urn:ietf:wg:oauth:2.0:oob\",
    \"code_verifier\": \"$CODE_VERIFIER\"
  }"

# 4. Test API with token
curl -X POST "http://localhost:8888/wp-json/ai-connect/v1/tools/wordpress.getCurrentUser" \
  -H "Authorization: Bearer YOUR_ACCESS_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{}'
```

---

### Running Tests

```bash
cd wp-content/plugins/ai-connect
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
add_action('ai_connect_register_modules', function($ai_connect) {
    $manifest = $ai_connect->get_manifest_instance();
    
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

---

## 📋 Changelog

### Version 0.2.0 - 2026-02-22 🔐 **BREAKING CHANGE**

**Security:** Migrated from username/password to OAuth 2.0 Authorization Code Flow with PKCE

* **Added:** OAuth 2.0 Authorization Code Flow with PKCE (S256)
* **Added:** Pre-registered OAuth clients (claude-ai, chatgpt, gemini)
* **Added:** OAuth consent screen for user authorization
* **Added:** Admin UI for OAuth token management (AI Connect → OAuth Tokens)
* **Added:** `router.php` for PHP built-in server support
* **Removed:** Direct username/password authentication endpoint (`/auth/login`)
* **Security:** Authorization codes are one-time use with 10 minute expiry
* **Security:** Access tokens expire after 1 hour
* **Security:** PKCE (S256) required to prevent authorization code interception
* **Fixed:** Timezone handling in token expiration validation
* **Fixed:** Bearer token authentication middleware
* **Fixed:** Unreachable code in permission checks

**Migration Required:** Existing username/password integrations must migrate to OAuth 2.0. See documentation for details.

### Version 0.1.2 - 2026-02-19
* **Added:** Translation support for 12 languages (ar, de_DE, en_US, es_ES, fr_FR, he_IL, it_IT, ja, nl_NL, pt_BR, ru_RU, zh_CN)
* **Fix:** Include composer.json and composer.lock in WordPress.org builds

### Version 0.1.1 - 2026-02-16
* Include vendor dependencies (firebase/php-jwt, predis/predis) in distribution
* Update installation documentation - no manual composer install required
* Add .distignore for WordPress.org distribution
* Add dependency check with admin notice
* Improve plugin distribution workflow

### Version 0.1.0 - 2025-02-13
* Initial public release
* WebMCP protocol support
* 5 WordPress core tools (searchPosts, getPost, searchPages, getPage, getCurrentUser)
* Rate limiting (Redis + WordPress transients)
* Security controls (User Blacklist)
* Automatic manifest generation
* Production ready

**Note:** v0.1.0 used username/password authentication. This was replaced with OAuth 2.0 in v0.2.0.

---

## 🤝 Contributing

Found a bug or want to contribute? Visit our [GitHub repository](https://github.com/chgold/ai-connect).

---

## 📄 License

GPL-3.0-or-later

---

## 🔗 Links

- [GitHub Repository](https://github.com/chgold/ai-connect)
- [Issue Tracker](https://github.com/chgold/ai-connect/issues)
- [Documentation](https://github.com/chgold/ai-connect/wiki)

---

**Made with ❤️ for the WordPress & AI community**
