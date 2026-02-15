#!/bin/bash

echo "╔════════════════════════════════════════════╗"
echo "║   Complete Manifest & Endpoint Test       ║"
echo "╚════════════════════════════════════════════╝"
echo ""

PASSED=0
FAILED=0

BASE_URL="http://localhost:8888/index.php?rest_route="

echo "📋 Step 1: Validating manifest structure..."
echo ""

curl -s "${BASE_URL}/ai-connect/v1/manifest" > /tmp/manifest_test.json

STRUCTURE_RESULT=$(python3 << 'PYEOF'
import json
try:
    with open('/tmp/manifest_test.json') as f:
        m = json.load(f)
    
    checks = {
        'schema_version': m.get('schema_version') == '1.0',
        'name': bool(m.get('name')),
        'version': bool(m.get('version')),
        'description': bool(m.get('description')),
        'api_version': bool(m.get('api_version')),
        'capabilities': bool(m.get('capabilities')),
        'tools': len(m.get('tools', [])) >= 5,
        'server.url': bool(m.get('server', {}).get('url')),
        'auth.type': m.get('auth', {}).get('type') == 'oauth2',
        'authorization_url': bool(m.get('auth', {}).get('authorization_url')),
        'token_url': bool(m.get('auth', {}).get('token_url')),
        'scopes': len(m.get('auth', {}).get('scopes', {})) >= 3,
    }
    
    passed = sum(1 for v in checks.values() if v)
    failed = sum(1 for v in checks.values() if not v)
    
    for name, result in checks.items():
        print(f"   {'✅' if result else '❌'} {name}")
    
    print(f"\nTOOLS_COUNT:{len(m.get('tools', []))}")
    print(f"RESULT:{passed}|{failed}")
except Exception as e:
    print(f"ERROR: {e}")
    print("RESULT:0|12")
PYEOF
)

PART1=$(echo "$STRUCTURE_RESULT" | grep "RESULT:" | cut -d: -f2)
TOOLS_COUNT=$(echo "$STRUCTURE_RESULT" | grep "TOOLS_COUNT:" | cut -d: -f2)
PART1_PASSED=$(echo "$PART1" | cut -d'|' -f1)
PART1_FAILED=$(echo "$PART1" | cut -d'|' -f2)

PASSED=$((PASSED + PART1_PASSED))
FAILED=$((FAILED + PART1_FAILED))

echo ""
echo "   📊 Found $TOOLS_COUNT tools in manifest"
echo ""

echo "🔐 Step 2: Creating OAuth token..."
echo ""

OAUTH_DATA=$(wp eval "
\$auth = new \AIConnect\Core\Auth();
\$client = \$auth->register_client('Complete Test', 'https://test.com');
\$token = \$auth->generate_access_token(1, \$client['client_id'], 'read write admin');
echo \$token . '|' . \$client['client_id'];
" 2>/dev/null)

TOKEN=$(echo $OAUTH_DATA | cut -d'|' -f1)
CLIENT_ID=$(echo $OAUTH_DATA | cut -d'|' -f2)

if [ ! -z "$TOKEN" ]; then
    echo "   ✅ OAuth token created"
    ((PASSED++))
else
    echo "   ❌ Failed to create token"
    ((FAILED++))
fi

echo ""
echo "🧪 Step 3: Testing all tools from manifest..."
echo ""

wp eval "
\$request = new WP_REST_Request('GET', '/ai-connect/v1/manifest');
\$response = rest_get_server()->dispatch(\$request);
\$manifest = \$response->get_data();
foreach (\$manifest['tools'] as \$tool) {
    echo \$tool['name'] . PHP_EOL;
}
" > /tmp/tools_list.txt

while IFS= read -r TOOL; do
    case $TOOL in
        *.getPost|*.getPage|*.getProduct)
            DATA='{"identifier": 1}'
            ;;
        *.addToCart)
            DATA='{"product_id": 1}'
            ;;
        *)
            DATA='{"limit": 1}'
            ;;
    esac
    
    RESPONSE=$(curl -s -X POST "${BASE_URL}/ai-connect/v1/tools/$TOOL" \
        -H "Authorization: Bearer $TOKEN" \
        -H "Content-Type: application/json" \
        -d "$DATA")
    
    if echo "$RESPONSE" | grep -qE '^\[|^\{' && ! echo "$RESPONSE" | grep -q '<!doctype'; then
        if echo "$RESPONSE" | grep -qiE '"code"|"error"'; then
            if echo "$RESPONSE" | grep -qiE 'not found|not exist|No posts|No pages|No products'; then
                echo "   ✅ $TOOL (returns expected error)"
                ((PASSED++))
            else
                ERROR_MSG=$(echo "$RESPONSE" | grep -oE '"message":"[^"]*"' | cut -d'"' -f4 | head -c 40)
                echo "   ❌ $TOOL - $ERROR_MSG"
                ((FAILED++))
            fi
        else
            echo "   ✅ $TOOL (returns data)"
            ((PASSED++))
        fi
    else
        echo "   ❌ $TOOL (invalid response)"
        ((FAILED++))
    fi
done < /tmp/tools_list.txt

echo ""
echo "🌐 Step 4: Testing infrastructure endpoints..."
echo ""

HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "${BASE_URL}/ai-connect/v1/status")
if [ "$HTTP_CODE" = "200" ]; then
    echo "   ✅ Status endpoint"
    ((PASSED++))
else
    echo "   ❌ Status endpoint (HTTP $HTTP_CODE)"
    ((FAILED++))
fi

HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "${BASE_URL}/ai-connect/v1/manifest")
if [ "$HTTP_CODE" = "200" ]; then
    echo "   ✅ Manifest endpoint"
    ((PASSED++))
else
    echo "   ❌ Manifest endpoint (HTTP $HTTP_CODE)"
    ((FAILED++))
fi

wp eval "delete_option('ai_connect_client_$CLIENT_ID');" 2>/dev/null

echo ""
echo "╔════════════════════════════════════════════╗"
echo "║   Complete Test Results                    ║"
echo "╠════════════════════════════════════════════╣"
printf "║   ✅ Passed: %-3d                          ║\n" $PASSED
printf "║   ❌ Failed: %-3d                          ║\n" $FAILED
printf "║   📊 Total:  %-3d                          ║\n" $((PASSED + FAILED))
echo "╠════════════════════════════════════════════╣"

if [ $FAILED -eq 0 ]; then
    echo "║                                            ║"
    echo "║        ✅ PERFECT SUCCESS! ✅             ║"
    echo "║                                            ║"
    echo "║   • Manifest: Complete & Valid            ║"
    echo "║   • OAuth: Working                        ║"
    echo "║   • All Tools: Functional                 ║"
    echo "║   • All URLs: Accessible                  ║"
    echo "║                                            ║"
else
    echo "║                                            ║"
    echo "║         ⚠️  SOME TESTS FAILED ⚠️          ║"
    echo "║                                            ║"
fi

echo "╚════════════════════════════════════════════╝"

exit $FAILED
