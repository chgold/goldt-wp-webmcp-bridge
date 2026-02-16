# AI Connect - WebMCP Bridge for WordPress

![WordPress Plugin Version](https://img.shields.io/badge/version-0.1.1-blue.svg)
![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue.svg)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)
![License](https://img.shields.io/badge/license-GPL--3.0-green.svg)

Connect AI agents (ChatGPT, Claude, or any custom AI) to your WordPress site with **simple, secure authentication** using the WebMCP protocol.

**No complex OAuth setup required!** Just username + password.

---

## 🚀 Quick Start

### Installation

1. Upload the plugin to `/wp-content/plugins/ai-connect/`
2. Activate through WordPress admin

**That's it!** No configuration needed.

**Note:** The plugin includes all required dependencies:
- `firebase/php-jwt` (v6.10.0) - JWT token handling
- `predis/predis` (v2.4.1) - Redis client for rate limiting (optional)

No manual `composer install` required - everything is bundled!

---

## 🔧 Development Note

**Important for local development:**

If using PHP built-in server or environments where pretty permalinks don't work with dots in URLs, use the `index.php?rest_route=` format:

```bash
# Development format (PHP built-in server)
curl "http://localhost:8888/index.php?rest_route=/ai-connect/v1/tools/wordpress.searchPosts"
```

On production servers with Apache/Nginx and proper rewrite rules, use the standard format:

```bash
# Production format (Apache/Nginx)
curl "http://yoursite.com/wp-json/ai-connect/v1/tools/wordpress.searchPosts"
```

**All examples below use the production format.** For local development, replace `/wp-json/` with `/index.php?rest_route=/`.

---

## 📖 Authentication Guide

### How It Works

AI Connect uses **direct authentication** - any AI agent can connect to your WordPress site using a WordPress username and password. No pre-registration required!

### Step 1: Login and Get Tokens

**Request:**
```bash
curl -X POST "http://yoursite.com/wp-json/ai-connect/v1/auth/login" \
  -H "Content-Type: application/json" \
  -d '{
    "username": "your_wordpress_username",
    "password": "your_wordpress_password"
  }'
```

**Response:**
```json
{
  "access_token": "eyJ0eXAiOiJKV1QiLCJhbGc...",
  "token_type": "Bearer",
  "expires_in": 3600,
  "refresh_token": "def50200a1b2c3...",
  "scope": "read write",
  "user_id": 1,
  "user_login": "admin",
  "user_email": "admin@example.com"
}
```

⚠️ **Security Note:** Access tokens expire after 1 hour. Store the refresh token to get new access tokens without re-authenticating.

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

### Common Errors

#### `"authentication_failed"` - Invalid username or password
**Solution:** Verify WordPress credentials are correct

#### `"access_denied"` - User blocked
**Solution:** Check if user is in blacklist (AI Connect → Settings)

#### `"Token expired"`
**Solution:** Use refresh token to get a new access token

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

## 📝 Code Examples

### JavaScript

```javascript
// Login and get tokens
async function loginToWordPress(siteUrl, username, password) {
  const response = await fetch(`${siteUrl}/wp-json/ai-connect/v1/auth/login`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ username, password })
  });
  
  const tokens = await response.json();
  return tokens.access_token;
}

// Use the API
async function searchPosts(siteUrl, accessToken, query) {
  const response = await fetch(`${siteUrl}/wp-json/ai-connect/v1/tools/wordpress.searchPosts`, {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${accessToken}`,
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({ search: query, limit: 10 })
  });
  
  const data = await response.json();
  return data;
}

// Example usage
const token = await loginToWordPress('https://yoursite.com', 'admin', 'password');
const posts = await searchPosts('https://yoursite.com', token, 'hello');
console.log(posts);
```

---

### Python

```python
import requests

class WordPressAI:
    def __init__(self, site_url, username, password):
        self.site_url = site_url
        self.access_token = None
        self.refresh_token = None
        self.login(username, password)
    
    def login(self, username, password):
        """Authenticate and get tokens"""
        response = requests.post(
            f"{self.site_url}/wp-json/ai-connect/v1/auth/login",
            json={'username': username, 'password': password}
        )
        response.raise_for_status()
        
        data = response.json()
        self.access_token = data['access_token']
        self.refresh_token = data['refresh_token']
    
    def refresh_access_token(self):
        """Get new access token using refresh token"""
        response = requests.post(
            f"{self.site_url}/wp-json/ai-connect/v1/oauth/refresh",
            json={'refresh_token': self.refresh_token}
        )
        response.raise_for_status()
        
        data = response.json()
        self.access_token = data['access_token']
    
    def call_tool(self, tool_name, params):
        """Call a WordPress tool"""
        response = requests.post(
            f"{self.site_url}/wp-json/ai-connect/v1/tools/{tool_name}",
            headers={'Authorization': f'Bearer {self.access_token}'},
            json=params
        )
        
        # Handle token expiry
        if response.status_code == 401:
            self.refresh_access_token()
            return self.call_tool(tool_name, params)
        
        response.raise_for_status()
        return response.json()

# Example usage
wp = WordPressAI('https://yoursite.com', 'admin', 'password')

# Search posts
posts = wp.call_tool('wordpress.searchPosts', {'search': 'hello', 'limit': 5})
print(posts)

# Get current user
user = wp.call_tool('wordpress.getCurrentUser', {})
print(user)
```

---

## 🔧 Development

### Testing Locally

```bash
# Start WordPress with PHP built-in server
cd /var/www/wp
php -S localhost:8888

# Test login
curl -X POST "http://localhost:8888/wp-json/ai-connect/v1/auth/login" \
  -H "Content-Type: application/json" \
  -d '{"username": "admin", "password": "admin"}'

# Test API call
curl -X POST "http://localhost:8888/wp-json/ai-connect/v1/tools/wordpress.getCurrentUser" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{}'
```

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
