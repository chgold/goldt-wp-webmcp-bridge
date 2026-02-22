#!/bin/bash

echo "╔════════════════════════════════════════════╗"
echo "║ GoldT WebMCP - Complete Endpoint Test     ║"
echo "╚════════════════════════════════════════════╝"
echo ""

PASSED=0
FAILED=0

echo "📋 Getting manifest and creating auth token..."

# Get site URL and manifest
SITE_URL=$(wp option get siteurl 2>/dev/null)
MANIFEST=$(curl -s "$SITE_URL/index.php?rest_route=/goldt-webmcp-bridge/v1/manifest")

# Extract tool names from manifest
echo "$MANIFEST" | grep -oP '"name":"[^"]*"' | cut -d'"' -f4 > /tmp/tool_list.txt

TOKEN=$(wp eval "
\$oauth_server = new \GoldtWebMCP\OAuth\OAuth_Server();
\$token_data = \$oauth_server->create_access_token('test-client', 1, ['read', 'write']);
echo \$token_data['access_token'];
" 2>/dev/null)

echo "✅ Authentication ready"
echo "✅ Found $(wc -l < /tmp/tool_list.txt) tools to test"
echo ""
echo "🧪 Testing all tools from manifest:"
echo ""

while IFS= read -r TOOL; do
  
  case $TOOL in
    wordpress.getPost|wordpress.getPage)
      TEST_DATA='{"identifier": 1}'
      ;;
    woocommerce.getProduct)
      TEST_DATA='{"identifier": 1}'
      ;;
    woocommerce.addToCart)
      TEST_DATA='{"product_id": 1}'
      ;;
    wordpress.searchPosts|wordpress.searchPages)
      TEST_DATA='{"limit": 5}'
      ;;
    woocommerce.searchProducts)
      TEST_DATA='{"limit": 5}'
      ;;
    woocommerce.getOrders)
      TEST_DATA='{"limit": 5}'
      ;;
    *)
      TEST_DATA='{}'
      ;;
  esac
  
  RESPONSE=$(curl -s -X POST \
    "$SITE_URL/index.php?rest_route=/goldt-webmcp-bridge/v1/tools/$TOOL" \
    -H "Authorization: Bearer $TOKEN" \
    -H "Content-Type: application/json" \
    -d "$TEST_DATA" 2>/dev/null)
  
  if echo "$RESPONSE" | grep -qE '"code":|"error":'; then
    ERROR_MSG=$(echo "$RESPONSE" | grep -oE '"message":"[^"]*"' | head -1 | cut -d'"' -f4)
    
    if [[ "$ERROR_MSG" =~ (not found|not exist|No posts|No pages|No products|No orders|does not exist) ]]; then
      echo "   ✅ $TOOL (endpoint works)"
      ((PASSED++))
    else
      echo "   ❌ $TOOL - $ERROR_MSG"
      ((FAILED++))
    fi
  elif [ ${#RESPONSE} -gt 10 ]; then
    if echo "$RESPONSE" | grep -q '"id"'; then
      COUNT=$(echo "$RESPONSE" | grep -o '"id"' | wc -l | tr -d ' ')
      echo "   ✅ $TOOL ($COUNT results)"
    elif echo "$RESPONSE" | grep -q '"username"'; then
      echo "   ✅ $TOOL (user data)"
    else
      echo "   ✅ $TOOL (data returned)"
    fi
    ((PASSED++))
  else
    echo "   ❌ $TOOL - No response"
    ((FAILED++))
  fi
  
done < /tmp/tool_list.txt

echo ""
echo "🌐 Testing infrastructure endpoints:"
echo ""

STATUS=$(curl -s "$SITE_URL/index.php?rest_route=/goldt-webmcp-bridge/v1/status")
if echo "$STATUS" | grep -q '"status":"ok"'; then
  echo "   ✅ Status endpoint"
  ((PASSED++))
else
  echo "   ❌ Status endpoint"
  ((FAILED++))
fi

MANIFEST=$(curl -s "$SITE_URL/index.php?rest_route=/goldt-webmcp-bridge/v1/manifest")
if echo "$MANIFEST" | grep -q '"tools"'; then
  echo "   ✅ Manifest endpoint"
  ((PASSED++))
else
  echo "   ❌ Manifest endpoint"
  ((FAILED++))
fi

echo ""
echo "🔐 Testing OAuth 2.0 endpoints:"
echo ""

OAUTH_TOKEN=$(wp eval "
global \$wpdb;
\$token = 'wpc_test_' . bin2hex(random_bytes(32));
\$expires_at = gmdate('Y-m-d H:i:s', time() + 3600);
\$wpdb->insert(
    \$wpdb->prefix . 'ai_connect_oauth_tokens',
    [
        'token' => \$token,
        'client_id' => 'test-client',
        'user_id' => 1,
        'scopes' => json_encode(['read', 'write']),
        'expires_at' => \$expires_at,
        'created_at' => gmdate('Y-m-d H:i:s')
    ],
    ['%s', '%s', '%d', '%s', '%s', '%s']
);
echo \$token;
" 2>/dev/null)

OAUTH_RESPONSE=$(curl -s -X POST \
  "$SITE_URL/index.php?rest_route=/goldt-webmcp-bridge/v1/tools/wordpress.getCurrentUser" \
  -H "Authorization: Bearer $OAUTH_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{}')

if echo "$OAUTH_RESPONSE" | grep -q '"username"'; then
  echo "   ✅ OAuth Bearer token authentication"
  ((PASSED++))
else
  echo "   ❌ OAuth Bearer token authentication"
  ((FAILED++))
fi

NO_AUTH=$(curl -s -X POST \
  "$SITE_URL/index.php?rest_route=/goldt-webmcp-bridge/v1/tools/wordpress.getCurrentUser" \
  -H "Content-Type: application/json" \
  -d '{}')

if echo "$NO_AUTH" | grep -qE '"code":"(rest_not_logged_in|no_token)"'; then
  echo "   ✅ Auth required for protected endpoints"
  ((PASSED++))
else
  echo "   ❌ Auth required for protected endpoints"
  ((FAILED++))
fi

MANIFEST_PUBLIC=$(curl -s "$SITE_URL/index.php?rest_route=/goldt-webmcp-bridge/v1/manifest")
if echo "$MANIFEST_PUBLIC" | grep -q '"tools"'; then
  echo "   ✅ Manifest accessible without auth"
  ((PASSED++))
else
  echo "   ❌ Manifest accessible without auth"
  ((FAILED++))
fi

wp eval "
global \$wpdb;
\$wpdb->delete(\$wpdb->prefix . 'ai_connect_oauth_tokens', ['token' => '$OAUTH_TOKEN']);
" 2>/dev/null

REFRESH_TOKEN_TEST=$(wp eval "
\$oauth_server = new \GoldtWebMCP\OAuth\OAuth_Server();
\$token_data = \$oauth_server->create_access_token('claude-ai', 1, ['read', 'write']);
echo json_encode(\$token_data);
" 2>/dev/null)

OLD_ACCESS_TOKEN=$(echo "$REFRESH_TOKEN_TEST" | grep -oP '"access_token":"[^"]*"' | cut -d'"' -f4)
REFRESH_TOKEN=$(echo "$REFRESH_TOKEN_TEST" | grep -oP '"refresh_token":"[^"]*"' | cut -d'"' -f4)

if [ -n "$REFRESH_TOKEN" ]; then
  REFRESH_RESPONSE=$(curl -s -X POST \
    "$SITE_URL/index.php?rest_route=/goldt-webmcp-bridge/v1/oauth/token" \
    -H "Content-Type: application/json" \
    -d "{\"grant_type\":\"refresh_token\",\"refresh_token\":\"$REFRESH_TOKEN\",\"client_id\":\"claude-ai\"}")
  
  NEW_ACCESS_TOKEN=$(echo "$REFRESH_RESPONSE" | grep -oP '"access_token":"[^"]*"' | cut -d'"' -f4)
  
  if [ -n "$NEW_ACCESS_TOKEN" ]; then
    TEST_WITH_NEW_TOKEN=$(curl -s -X POST \
      "$SITE_URL/index.php?rest_route=/goldt-webmcp-bridge/v1/tools/wordpress.getCurrentUser" \
      -H "Authorization: Bearer $NEW_ACCESS_TOKEN" \
      -H "Content-Type: application/json" \
      -d '{}')
    
    if echo "$TEST_WITH_NEW_TOKEN" | grep -q '"username"'; then
      echo "   ✅ Refresh token flow (new token works)"
      ((PASSED++))
    else
      echo "   ❌ Refresh token flow (new token doesn't work)"
      ((FAILED++))
    fi
    
    TEST_WITH_OLD_TOKEN=$(curl -s -X POST \
      "$SITE_URL/index.php?rest_route=/goldt-webmcp-bridge/v1/tools/wordpress.getCurrentUser" \
      -H "Authorization: Bearer $OLD_ACCESS_TOKEN" \
      -H "Content-Type: application/json" \
      -d '{}')
    
    if echo "$TEST_WITH_OLD_TOKEN" | grep -qE '"code":"(invalid_token|no_token)"'; then
      echo "   ✅ Old token revoked after refresh"
      ((PASSED++))
    else
      echo "   ❌ Old token not revoked after refresh"
      ((FAILED++))
    fi
  else
    echo "   ❌ Refresh token exchange failed"
    ((FAILED++))
  fi
else
  echo "   ❌ No refresh token in response"
  ((FAILED++))
fi

wp eval "
global \$wpdb;
\$wpdb->query(\"DELETE FROM {\$wpdb->prefix}ai_connect_oauth_tokens WHERE client_id = 'claude-ai' AND user_id = 1\");
" 2>/dev/null

echo ""
echo "╔════════════════════════════════════════════╗"
echo "║   Test Results                             ║"
echo "╠════════════════════════════════════════════╣"
printf "║   ✅ Passed: %-3d                          ║\n" $PASSED
printf "║   ❌ Failed: %-3d                          ║\n" $FAILED
printf "║   📊 Total:  %-3d                          ║\n" $((PASSED + FAILED))
echo "╠════════════════════════════════════════════╣"
if [ $FAILED -eq 0 ]; then
  echo "║                                            ║"
  echo "║          ✅ ALL TESTS PASSED! ✅          ║"
  echo "║                                            ║"
else
  echo "║                                            ║"
  echo "║         ⚠️  SOME TESTS FAILED ⚠️          ║"
  echo "║                                            ║"
fi
echo "╚════════════════════════════════════════════╝"

# Cleanup
rm -f /tmp/tool_list.txt

exit $FAILED
