<?php
/**
 * AI Connect - Admin Test Page
 * 
 * Access via: WordPress Admin → Tools → AI Connect Tests
 * 
 * This provides a browser-based test interface for manual testing.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', 'ai_connect_add_test_page');

function ai_connect_add_test_page() {
    add_management_page(
        'AI Connect Tests',
        'AI Connect Tests',
        'manage_options',
        'ai-connect-tests',
        'ai_connect_render_test_page'
    );
}

function ai_connect_render_test_page() {
    ?>
    <div class="wrap">
        <h1>🧪 AI Connect - Interactive Tests</h1>
        
        <div class="notice notice-info">
            <p><strong>Test Suite:</strong> Run comprehensive tests to verify plugin functionality.</p>
        </div>
        
        <?php ai_connect_run_admin_tests(); ?>
    </div>
    
    <style>
        .test-result {
            padding: 10px;
            margin: 10px 0;
            border-left: 4px solid #ddd;
        }
        .test-result.pass {
            background: #d4edda;
            border-color: #28a745;
        }
        .test-result.fail {
            background: #f8d7da;
            border-color: #dc3545;
        }
        .test-section {
            margin: 20px 0;
            padding: 20px;
            background: #fff;
            border: 1px solid #ccc;
        }
    </style>
    <?php
}

function ai_connect_run_admin_tests() {
    $tests = [];
    
    // Test 1: Plugin Activated
    $tests[] = [
        'name' => 'Plugin Activated',
        'result' => defined('AI_CONNECT_VERSION'),
        'message' => defined('AI_CONNECT_VERSION') ? 
            'Version: ' . AI_CONNECT_VERSION : 
            'Plugin constant not defined'
    ];
    
    // Test 2: REST API
    $manifest_url = rest_url('ai-connect/v1/manifest');
    $response = wp_remote_get($manifest_url);
    $tests[] = [
        'name' => 'Manifest API',
        'result' => !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200,
        'message' => !is_wp_error($response) ? 
            'HTTP ' . wp_remote_retrieve_response_code($response) : 
            $response->get_error_message()
    ];
    
    // Test 3: Tool Count
    if (!is_wp_error($response)) {
        $data = json_decode(wp_remote_retrieve_body($response), true);
        $tool_count = isset($data['tools']) ? count($data['tools']) : 0;
        
        $tests[] = [
            'name' => 'Tool Count',
            'result' => $tool_count === 5 || (defined('AI_CONNECT_PRO_VERSION') && $tool_count > 5),
            'message' => "Found {$tool_count} tools"
        ];
        
        // Test 4: Core Tools Present
        $tool_names = array_map(function($t) { return $t['name']; }, $data['tools'] ?? []);
        $required = ['wordpress.searchPosts', 'wordpress.getPost', 'wordpress.searchPages', 
                     'wordpress.getPage', 'wordpress.getCurrentUser'];
        
        $missing = array_diff($required, $tool_names);
        $tests[] = [
            'name' => 'Core Tools Present',
            'result' => empty($missing),
            'message' => empty($missing) ? 'All 5 core tools present' : 'Missing: ' . implode(', ', $missing)
        ];
        
        // Test 5: No WooCommerce in Free
        $has_wc = false;
        foreach ($tool_names as $name) {
            if (strpos($name, 'woocommerce') !== false) {
                $has_wc = true;
                break;
            }
        }
        
        if (!defined('AI_CONNECT_PRO_VERSION')) {
            $tests[] = [
                'name' => 'No WooCommerce in Free',
                'result' => !$has_wc,
                'message' => $has_wc ? 'WooCommerce tools found (should be Pro only)' : 'Correctly excluded'
            ];
        }
    }
    
    // Test 6: Pro Plugin
    if (defined('AI_CONNECT_PRO_VERSION')) {
        $tests[] = [
            'name' => 'Pro Plugin Active',
            'result' => true,
            'message' => 'Version: ' . AI_CONNECT_PRO_VERSION
        ];
        
        // Test 7: WooCommerce Tools in Pro
        $tests[] = [
            'name' => 'WooCommerce Tools (Pro)',
            'result' => $has_wc,
            'message' => $has_wc ? 'WooCommerce tools loaded' : 'WooCommerce tools missing'
        ];
    }
    
    // Test 8: OAuth Client Creation
    if (class_exists('AIConnect\Core\Auth')) {
        $auth = new \AIConnect\Core\Auth();
        $client = $auth->register_client('Test Client', 'https://example.com/callback');
        
        $oauth_works = isset($client['client_id']) && isset($client['client_secret']);
        
        $tests[] = [
            'name' => 'OAuth Client Creation',
            'result' => $oauth_works,
            'message' => $oauth_works ? 'Successfully created test client' : 'Failed to create client'
        ];
        
        if ($oauth_works) {
            delete_option('ai_connect_client_' . $client['client_id']);
        }
    }
    
    // Render Results
    echo '<div class="test-section">';
    echo '<h2>Test Results</h2>';
    
    $passed = 0;
    $total = count($tests);
    
    foreach ($tests as $test) {
        $class = $test['result'] ? 'pass' : 'fail';
        $icon = $test['result'] ? '✅' : '❌';
        
        if ($test['result']) {
            $passed++;
        }
        
        echo "<div class='test-result {$class}'>";
        echo "<strong>{$icon} {$test['name']}</strong><br>";
        echo "<small>{$test['message']}</small>";
        echo "</div>";
    }
    
    echo '</div>';
    
    // Summary
    echo '<div class="test-section">';
    echo '<h2>Summary</h2>';
    echo "<p><strong>Passed:</strong> {$passed} / {$total}</p>";
    
    if ($passed === $total) {
        echo '<div class="notice notice-success inline"><p>✅ All tests passed!</p></div>';
    } else {
        $failed = $total - $passed;
        echo "<div class='notice notice-error inline'><p>❌ {$failed} test(s) failed</p></div>";
    }
    echo '</div>';
    
    // Manual Test Buttons
    ai_connect_render_manual_tests();
}

function ai_connect_render_manual_tests() {
    ?>
    <div class="test-section">
        <h2>Manual Tests</h2>
        <p>Click these buttons to test specific functionality:</p>
        
        <table class="widefat">
            <thead>
                <tr>
                    <th>Test</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>View Manifest JSON</strong></td>
                    <td>
                        <a href="<?php echo esc_url(rest_url('ai-connect/v1/manifest')); ?>" 
                           target="_blank" class="button">
                            Open Manifest
                        </a>
                    </td>
                </tr>
                <tr>
                    <td><strong>Check Status Endpoint</strong></td>
                    <td>
                        <a href="<?php echo esc_url(rest_url('ai-connect/v1/status')); ?>" 
                           target="_blank" class="button">
                            Open Status
                        </a>
                    </td>
                </tr>
                <tr>
                    <td><strong>Create OAuth Client</strong></td>
                    <td>
                        <a href="<?php echo admin_url('admin.php?page=ai-connect-clients'); ?>" 
                           class="button">
                            Go to OAuth Clients
                        </a>
                    </td>
                </tr>
                <tr>
                    <td><strong>View Settings</strong></td>
                    <td>
                        <a href="<?php echo admin_url('admin.php?page=ai-connect-settings'); ?>" 
                           class="button">
                            Go to Settings
                        </a>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
    
    <div class="test-section">
        <h2>Next Steps</h2>
        <ol>
            <li>✅ If all automated tests passed, the plugin is working</li>
            <li>🔧 Create an OAuth client and test the authorization flow</li>
            <li>🧪 Test each tool endpoint with actual API requests</li>
            <li>💎 If using Pro, activate it and verify WooCommerce tools appear</li>
            <li>📝 Review the <a href="https://github.com/chgold/ai-connect#readme" target="_blank">README</a> for integration guides</li>
        </ol>
    </div>
    <?php
}
