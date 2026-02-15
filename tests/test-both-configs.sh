#!/bin/bash

echo "╔════════════════════════════════════════════╗"
echo "║   AI Connect - Test Both Configurations   ║"
echo "╚════════════════════════════════════════════╝"
echo ""

TOTAL_PASSED=0
TOTAL_FAILED=0

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  TEST 1: FREE PLUGIN ONLY"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""

wp plugin deactivate ai-connect-pro --quiet 2>/dev/null

echo "📋 Verifying manifest..."
TOOLS_FREE=$(curl -s "http://localhost:8888/wp-json/ai-connect/v1/manifest" | grep -o '"name":"wordpress\.' | wc -l)
echo "   Tools found: $TOOLS_FREE (expected: 5)"

if [ "$TOOLS_FREE" = "5" ]; then
    echo "   ✅ Correct tool count"
    ((TOTAL_PASSED++))
else
    echo "   ❌ Wrong tool count"
    ((TOTAL_FAILED++))
fi

echo ""
echo "🧪 Running unit tests..."
TEST_RESULT=$(wp eval-file wp-content/plugins/ai-connect/tests/run-tests.php 2>&1)
PASSED_FREE=$(echo "$TEST_RESULT" | grep -oP 'Passed: \K\d+')
FAILED_FREE=$(echo "$TEST_RESULT" | grep -oP 'Failed: \K\d+')

echo "   Passed: $PASSED_FREE"
echo "   Failed: $FAILED_FREE"

TOTAL_PASSED=$((TOTAL_PASSED + PASSED_FREE))
TOTAL_FAILED=$((TOTAL_FAILED + FAILED_FREE))

echo ""
echo "🌐 Running manifest validation..."
MANIFEST_RESULT=$(/var/www/wp/wp-content/plugins/ai-connect/tests/test-all-manifest.sh 2>&1)
MANIFEST_PASSED=$(echo "$MANIFEST_RESULT" | grep -oP 'Passed: \K\d+' | tail -1)
MANIFEST_FAILED=$(echo "$MANIFEST_RESULT" | grep -oP 'Failed: \K\d+' | tail -1)

echo "   Passed: $MANIFEST_PASSED"
echo "   Failed: $MANIFEST_FAILED"

TOTAL_PASSED=$((TOTAL_PASSED + MANIFEST_PASSED))
TOTAL_FAILED=$((TOTAL_FAILED + MANIFEST_FAILED))

FREE_TOTAL=$((PASSED_FREE + MANIFEST_PASSED + 1))

echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  TEST 2: FREE + PRO TOGETHER"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""

wp plugin activate ai-connect-pro --quiet 2>/dev/null

echo "📋 Verifying manifest..."
TOOLS_PRO=$(curl -s "http://localhost:8888/wp-json/ai-connect/v1/manifest" | grep -o '"name":"[^"]*\.' | wc -l)
echo "   Tools found: $TOOLS_PRO (expected: 10)"

if [ "$TOOLS_PRO" = "10" ]; then
    echo "   ✅ Correct tool count"
    ((TOTAL_PASSED++))
else
    echo "   ❌ Wrong tool count"
    ((TOTAL_FAILED++))
fi

echo ""
echo "🧪 Running unit tests..."
TEST_RESULT=$(wp eval-file wp-content/plugins/ai-connect/tests/run-tests.php 2>&1)
PASSED_PRO=$(echo "$TEST_RESULT" | grep -oP 'Passed: \K\d+')
FAILED_PRO=$(echo "$TEST_RESULT" | grep -oP 'Failed: \K\d+')

echo "   Passed: $PASSED_PRO"
echo "   Failed: $FAILED_PRO"

TOTAL_PASSED=$((TOTAL_PASSED + PASSED_PRO))
TOTAL_FAILED=$((TOTAL_FAILED + FAILED_PRO))

echo ""
echo "🌐 Running manifest validation..."
MANIFEST_RESULT=$(/var/www/wp/wp-content/plugins/ai-connect/tests/test-all-manifest.sh 2>&1)
MANIFEST_PASSED=$(echo "$MANIFEST_RESULT" | grep -oP 'Passed: \K\d+' | tail -1)
MANIFEST_FAILED=$(echo "$MANIFEST_RESULT" | grep -oP 'Failed: \K\d+' | tail -1)

echo "   Passed: $MANIFEST_PASSED"
echo "   Failed: $MANIFEST_FAILED"

TOTAL_PASSED=$((TOTAL_PASSED + MANIFEST_PASSED))
TOTAL_FAILED=$((TOTAL_FAILED + MANIFEST_FAILED))

PRO_TOTAL=$((PASSED_PRO + MANIFEST_PASSED + 1))

echo ""
echo "╔════════════════════════════════════════════╗"
echo "║   Final Results - Both Configurations     ║"
echo "╠════════════════════════════════════════════╣"
printf "║   Free Only:   %-3d tests %-15s ║\n" $FREE_TOTAL "✅"
printf "║   Free + Pro:  %-3d tests %-15s ║\n" $PRO_TOTAL "✅"
echo "╠════════════════════════════════════════════╣"
printf "║   ✅ Total Passed: %-3d                    ║\n" $TOTAL_PASSED
printf "║   ❌ Total Failed: %-3d                    ║\n" $TOTAL_FAILED
printf "║   📊 Grand Total:  %-3d                    ║\n" $((TOTAL_PASSED + TOTAL_FAILED))
echo "╠════════════════════════════════════════════╣"

if [ $TOTAL_FAILED -eq 0 ]; then
    echo "║                                            ║"
    echo "║    🎉 ALL CONFIGURATIONS PASS! 🎉         ║"
    echo "║                                            ║"
else
    echo "║                                            ║"
    echo "║       ⚠️  SOME TESTS FAILED ⚠️            ║"
    echo "║                                            ║"
fi

echo "╚════════════════════════════════════════════╝"

exit $TOTAL_FAILED
