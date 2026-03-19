#!/usr/bin/env php
<?php
/**
 * GoldT WebMCP Bridge - Comprehensive Test Suite
 * 
 * Run from command line:
 * php tests/run-tests.php
 * 
 * Or via WP-CLI:
 * wp eval-file tests/run-tests.php
 */

define('DOING_GOLDTWMCP_TESTS', true);

// Load WordPress if not already loaded
if (!defined('ABSPATH')) {
    // WP_ROOT env var set by build.php (dev environment)
    // Fallback: standard install path (plugin in wp-content/plugins/, 4 levels up)
    $wp_load = (getenv('WP_ROOT') ?: dirname(__DIR__, 4)) . '/wp-load.php';
    require_once $wp_load;
}

class GoldT_WebMCP_Test_Runner {
    
    private $passed = 0;
    private $failed = 0;
    private $tests = [];
    
    public function run() {
        $this->print_header();
        
        // Core Tests
        $this->run_test('Plugin Files Exist', [$this, 'test_plugin_files']);
        $this->run_test('Plugin Activated', [$this, 'test_plugin_active']);
        $this->run_test('No PHP Errors on Load', [$this, 'test_no_php_errors']);
        $this->run_test('Database Tables Ready', [$this, 'test_database']);
        
        // API Tests
        $this->run_test('REST API Available', [$this, 'test_rest_api']);
        $this->run_test('Manifest Endpoint', [$this, 'test_manifest']);
        
        // Core Functionality
        $this->run_test('OAuth Client Creation', [$this, 'test_oauth_client']);
        $this->run_test('Rate Limiter Works', [$this, 'test_rate_limiter']);
        $this->run_test('WordPress Core Tools', [$this, 'test_core_tools']);
        
        // Pro Plugin Tests (if active)
        if ($this->is_pro_active()) {
            $this->run_test('Pro Plugin Detected', [$this, 'test_pro_detected']);
            $this->run_test('Pro Hooks Registered', [$this, 'test_pro_hooks']);
            $this->run_test('WooCommerce Tools Available', [$this, 'test_woocommerce_tools']);
        } else {
            // Only test this if Pro is NOT active
            $this->run_test('WooCommerce NOT in Free', [$this, 'test_no_woocommerce_in_free']);
        }
        
        // Security Tests
        $this->run_test('No Locked Features in Free', [$this, 'test_no_locked_features']);
        $this->run_test('Proper Escaping', [$this, 'test_escaping']);
        
        $this->print_summary();
        
        return $this->failed === 0;
    }
    
    private function run_test($name, $callback) {
        echo "🧪 Testing: {$name}... ";
        
        try {
            $result = call_user_func($callback);
            
            if ($result === true) {
                echo "✅ PASS\n";
                $this->passed++;
                $this->tests[$name] = 'PASS';
            } else {
                echo "❌ FAIL: {$result}\n";
                $this->failed++;
                $this->tests[$name] = "FAIL: {$result}";
            }
        } catch (Exception $e) {
            echo "💥 ERROR: {$e->getMessage()}\n";
            $this->failed++;
            $this->tests[$name] = "ERROR: {$e->getMessage()}";
        }
    }
    
    private function print_header() {
        echo "\n";
        echo "╔════════════════════════════════════════════╗\n";
        echo "║   GoldT WebMCP Bridge - Test Suite        ║\n";
        echo "╚════════════════════════════════════════════╝\n";
        echo "\n";
    }
    
    private function print_summary() {
        echo "\n";
        echo "╔════════════════════════════════════════════╗\n";
        echo "║   Test Summary                             ║\n";
        echo "╠════════════════════════════════════════════╣\n";
        echo sprintf("║   Passed: %-3d                            ║\n", $this->passed);
        echo sprintf("║   Failed: %-3d                            ║\n", $this->failed);
        echo sprintf("║   Total:  %-3d                            ║\n", $this->passed + $this->failed);
        echo "╚════════════════════════════════════════════╝\n";
        
        if ($this->failed > 0) {
            echo "\n⚠️  Some tests failed. Review the output above.\n";
            exit(1);
        } else {
            echo "\n✅ All tests passed! Plugin is ready.\n";
            exit(0);
        }
    }
    
    // Test: Plugin Files Exist
    private function test_plugin_files() {
        $required_files = [
            'goldt-webmcp-bridge.php',
            'includes/class-goldtwmcp.php',
            'includes/core/class-manifest.php',
            'includes/oauth/class-oauth-server.php',
            'includes/modules/class-core-module.php',
        ];
        
        foreach ($required_files as $file) {
            if (!file_exists(GOLDTWMCP_PATH . $file)) {
                return "Missing file: {$file}";
            }
        }
        
        return true;
    }
    
    // Test: Plugin is Active
    private function test_plugin_active() {
        if (!defined('GOLDTWMCP_VERSION')) {
            return "GOLDTWMCP_VERSION not defined";
        }
        
        if (!is_plugin_active('goldt-webmcp-bridge/goldt-webmcp-bridge.php')) {
            return "Plugin not active in WordPress";
        }
        
        return true;
    }
    
    // Test: No PHP Errors
    private function test_no_php_errors() {
        $last_error = error_get_last();
        
        if ($last_error && $last_error['type'] === E_ERROR) {
            return "PHP Error detected: {$last_error['message']}";
        }
        
        return true;
    }
    
    // Test: Database
    private function test_database() {
        global $wpdb;
        
        // Check if we can query options
        $test = get_option('goldtwmcp_version');
        
        if ($test === false && !get_option('goldtwmcp_installed')) {
            return "Plugin not properly installed in database";
        }
        
        return true;
    }
    
    // Test: REST API
    private function test_rest_api() {
        $routes = rest_get_server()->get_routes();
        
        if (!isset($routes['/goldt-webmcp-bridge/v1/manifest'])) {
            return "Manifest route not registered";
        }
        
        if (!isset($routes['/goldt-webmcp-bridge/v1/tools'])) {
            return "Tools route not registered";
        }
        
        return true;
    }
    
    // Test: Manifest Endpoint
    private function test_manifest() {
        $request = new WP_REST_Request('GET', '/goldt-webmcp-bridge/v1/manifest');
        $response = rest_get_server()->dispatch($request);
        
        if ($response->is_error()) {
            return "Manifest returned error";
        }
        
        $data = $response->get_data();
        
        if (!isset($data['tools'])) {
            return "Manifest missing 'tools' key";
        }
        
        if (!is_array($data['tools'])) {
            return "Manifest tools is not an array";
        }
        
        // Free should have exactly 5 tools
        if (!$this->is_pro_active() && count($data['tools']) !== 5) {
            return "Free plugin should have exactly 5 tools, found " . count($data['tools']);
        }
        
        return true;
    }
    
    
    // Test: OAuth Client Creation
    private function test_oauth_client() {
        if (!class_exists('GoldtWebMCP\OAuth\OAuth_Server')) {
            return "OAuth_Server class not loaded";
        }
        
        $oauth_server = new \GoldtWebMCP\OAuth\OAuth_Server();
        
        // Test creating a token (which requires a valid client)
        global $wpdb;
        $clients = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}goldtwmcp_oauth_clients LIMIT 1");
        
        if (empty($clients)) {
            return "No OAuth clients found in database";
        }
        
        return true;
    }
    
    // Test: Rate Limiter
    private function test_rate_limiter() {
        if (!class_exists('GoldtWebMCP\Core\Rate_Limiter')) {
            return "Rate_Limiter class not loaded";
        }
        
        $limiter = new \GoldtWebMCP\Core\Rate_Limiter();
        
        // Should allow first request (is_rate_limited returns array with 'limited' => false)
        $result = $limiter->is_rate_limited('test_user_123');
        
        if (!is_array($result) || !isset($result['limited'])) {
            return "Rate limiter returned invalid response format";
        }
        
        if ($result['limited'] === true) {
            return "Rate limiter blocked first request";
        }
        
        // Cleanup
        $limiter->reset_limits('test_user_123');
        
        return true;
    }
    
    // Test: WordPress Core Tools
    private function test_core_tools() {
        $request = new WP_REST_Request('GET', '/goldt-webmcp-bridge/v1/manifest');
        $response = rest_get_server()->dispatch($request);
        $data = $response->get_data();
        
        $required_tools = [
            'wordpress.searchPosts',
            'wordpress.getPost',
            'wordpress.searchPages',
            'wordpress.getPage',
            'wordpress.getCurrentUser',
        ];
        
        $tool_names = array_map(function($tool) {
            return $tool['name'];
        }, $data['tools']);
        
        foreach ($required_tools as $tool) {
            if (!in_array($tool, $tool_names)) {
                return "Missing required tool: {$tool}";
            }
        }
        
        return true;
    }
    
    // Test: No WooCommerce in Free
    private function test_no_woocommerce_in_free() {
        $request = new WP_REST_Request('GET', '/goldt-webmcp-bridge/v1/manifest');
        $response = rest_get_server()->dispatch($request);
        $data = $response->get_data();
        
        $tool_names = array_map(function($tool) {
            return $tool['name'];
        }, $data['tools']);
        
        foreach ($tool_names as $name) {
            if (strpos($name, 'woocommerce') !== false) {
                return "WooCommerce tool found in Free plugin: {$name}";
            }
        }
        
        return true;
    }
    
    // Test: Pro Plugin Detected
    private function test_pro_detected() {
        if (!defined('GOLDTWMCP_PRO_VERSION')) {
            return "GOLDTWMCP_PRO_VERSION not defined";
        }
        
        return true;
    }
    
    // Test: Pro Hooks Registered
    private function test_pro_hooks() {
        // Check if hook was fired
        if (!did_action('goldtwmcp_register_modules')) {
            return "goldtwmcp_register_modules hook not fired";
        }
        
        return true;
    }
    
    // Test: WooCommerce Tools in Pro
    private function test_woocommerce_tools() {
        $request = new WP_REST_Request('GET', '/goldt-webmcp-bridge/v1/manifest');
        $response = rest_get_server()->dispatch($request);
        $data = $response->get_data();
        
        $tool_names = array_map(function($tool) {
            return $tool['name'];
        }, $data['tools']);
        
        $has_woocommerce = false;
        foreach ($tool_names as $name) {
            if (strpos($name, 'woocommerce') !== false) {
                $has_woocommerce = true;
                break;
            }
        }
        
        if (!$has_woocommerce) {
            return "No WooCommerce tools found (Pro is active but tools missing)";
        }
        
        return true;
    }
    
    // Test: No Locked Features in Free
    private function test_no_locked_features() {
        $plugin_files = glob(GOLDTWMCP_PATH . 'includes/**/*.php');
        
        foreach ($plugin_files as $file) {
            $content = file_get_contents($file);
            
            // Check for freemium anti-patterns
            if (strpos($content, 'upgrade_url') !== false ||
                strpos($content, 'get_upgrade_url') !== false ||
                strpos($content, 'is_premium') !== false ||
                strpos($content, 'requires Pro') !== false) {
                return "Found locked feature reference in Free plugin";
            }
        }
        
        return true;
    }
    
    // Test: Proper Escaping
    private function test_escaping() {
        $file = GOLDTWMCP_PATH . 'includes/class-goldtwmcp.php';
        $content = file_get_contents($file);
        
        // Basic check: should have esc_html, esc_attr, or similar
        if (strpos($content, 'esc_html') === false && 
            strpos($content, 'esc_attr') === false) {
            return "No escaping functions found (security concern)";
        }
        
        return true;
    }
    
    // Helper: Check if Pro is Active
    private function is_pro_active() {
        // Pro plugin defines this constant when active
        return defined('GOLDTWMCP_PRO_VERSION');
    }
}

// Run tests
$runner = new GoldT_WebMCP_Test_Runner();
$runner->run();
