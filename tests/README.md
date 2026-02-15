# AI Connect - Testing Guide

This directory contains test scripts for AI Connect plugin.

## Quick Start

### Option 0: Test Both Configurations (Recommended)

**NEW!** Test Free-only AND Free+Pro in one command:

```bash
cd /var/www/wp/wp-content/plugins/ai-connect
./tests/test-both-configs.sh
```

**What it does:**
- 🔄 Tests Free plugin standalone (5 tools)
- 🔄 Tests Free + Pro together (10 tools)
- 📊 Validates manifest in both configs
- ✅ Ensures clean separation

**Expected Output:**
```
🎉 ALL CONFIGURATIONS PASS! 🎉
Total: 47 tests across both configurations
```

---

### Option 1: Command Line Tests (Automated)

Run comprehensive automated tests:

```bash
cd /var/www/wp/wp-content/plugins/ai-connect
php tests/run-tests.php
```

Or via WP-CLI:

```bash
wp eval-file wp-content/plugins/ai-connect/tests/run-tests.php
```

**Expected Output:**
```
✅ All tests passed! Plugin is ready.
```

---

### Option 2: HTTP Endpoint Tests (Live API)

Test all endpoints via real HTTP requests with authentication:

```bash
cd /var/www/wp/wp-content/plugins/ai-connect
./tests/test-endpoints.sh
```

**What it tests:**
- 📋 Retrieves manifest dynamically
- 🔐 Creates real OAuth token
- 🧪 Tests all tools from manifest with authentication
- 🌐 Tests status and manifest endpoints
- ✅ Validates responses from live API

**Expected Output:**
```
✅ ALL TESTS PASSED!
12 endpoints tested successfully
```

---

### Option 3: Browser Tests (Manual)

1. **Activate the test page:**
   ```bash
   # Add this to wp-config.php temporarily:
   define('AI_CONNECT_ENABLE_TESTS', true);
   ```

2. **Load the test page in wp-admin:**
   ```php
   // In ai-connect.php, add:
   if (defined('AI_CONNECT_ENABLE_TESTS') && AI_CONNECT_ENABLE_TESTS) {
       require_once AI_CONNECT_PATH . 'tests/test-admin-page.php';
   }
   ```

3. **Access tests:**
   - Go to WordPress Admin → **Tools → AI Connect Tests**
   - View automated test results
   - Click manual test buttons

---

## What Gets Tested

### ✅ Unit Tests (run-tests.php)

1. **Plugin Files** - All required files exist
2. **Activation** - Plugin is active in WordPress
3. **PHP Errors** - No fatal errors on load
4. **Database** - Plugin properly installed
5. **REST API** - Endpoints registered
6. **Manifest** - Returns correct JSON
7. **Status** - Health check passes
8. **OAuth** - Client creation works
9. **Rate Limiter** - Limits requests properly
10. **Core Tools** - All 5 WordPress tools present
11. **No Locked Features** - Free has no premium gates
12. **Security** - Proper escaping used

### 🔧 Pro Plugin Tests (when active)

13. **Pro Detected** - Pro plugin constant defined
14. **Hooks Registered** - Pro hooks into Free
15. **WooCommerce Tools** - WC tools added to manifest

### 🌐 Integration Tests (test-endpoints.sh)

**Tests all tools from manifest via HTTP:**
- wordpress.searchPosts
- wordpress.getPost
- wordpress.searchPages
- wordpress.getPage
- wordpress.getCurrentUser
- woocommerce.searchProducts
- woocommerce.getProduct
- woocommerce.addToCart
- woocommerce.getCart
- woocommerce.getOrders

**Plus infrastructure:**
- Status endpoint
- Manifest endpoint

---

## Test Scenarios

### Scenario 1: Free Plugin Only

```bash
# Deactivate Pro (if active)
wp plugin deactivate ai-connect-pro

# Run tests
php tests/run-tests.php
```

**Expected:**
- ✅ 5 WordPress Core tools
- ✅ No WooCommerce tools
- ✅ No "upgrade" or "premium" references

---

### Scenario 2: Free + Pro Together

```bash
# Activate both
wp plugin activate ai-connect
wp plugin activate ai-connect-pro

# Run tests
php tests/run-tests.php
```

**Expected:**
- ✅ 5 WordPress Core tools + WooCommerce tools (15+)
- ✅ Pro hooks fired
- ✅ Manifest includes both Free and Pro tools

---

### Scenario 3: Pro Without Free

```bash
# Deactivate Free
wp plugin deactivate ai-connect

# Try to activate Pro
wp plugin activate ai-connect-pro
```

**Expected:**
- ❌ Pro shows admin notice: "Requires AI Connect (Free)"
- ❌ Pro does not load modules

---

## Manual Testing Checklist

After automated tests pass, manually verify:

### OAuth Flow
- [ ] Create OAuth client in admin
- [ ] Get Client ID & Secret
- [ ] Test authorization URL: `/oauth/authorize?client_id=...`
- [ ] Exchange code for token
- [ ] Use token to call API

### API Endpoints
- [ ] Call `wordpress.searchPosts` with token
- [ ] Verify search returns actual posts
- [ ] Test rate limiting (50+ requests)
- [ ] Test expired token (should fail)

### Pro Integration (if using Pro)
- [ ] Activate Pro plugin
- [ ] Verify WooCommerce tools appear
- [ ] Test `woocommerce.searchProducts`
- [ ] Deactivate Pro, verify Free still works

---

## Troubleshooting

### Tests Fail: "Plugin not active"

**Solution:**
```bash
wp plugin activate ai-connect
```

### Tests Fail: "Manifest route not registered"

**Solution:**
```bash
wp rewrite flush
```

### Tests Fail: "No escaping functions found"

**Solution:** Check `includes/class-ai-connect.php` for proper use of `esc_html()`, `esc_attr()`, etc.

---

## CI/CD Integration

Add to your CI pipeline:

```yaml
# .github/workflows/test.yml
name: Tests

on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v2
      
      - name: Setup WordPress
        run: |
          # Install WordPress + WP-CLI
          
      - name: Run Tests
        run: |
          wp eval-file wp-content/plugins/ai-connect/tests/run-tests.php
```

---

## Writing Custom Tests

Extend `AI_Connect_Test_Runner` in `run-tests.php`:

```php
$this->run_test('My Custom Test', function() {
    // Your test logic
    if ($condition) {
        return true; // Pass
    }
    return "Failure reason"; // Fail
});
```

---

## Support

If tests fail and you need help:

1. Run tests with verbose output
2. Check error logs: `wp-content/debug.log`
3. Open an issue: https://github.com/chgold/ai-connect/issues
