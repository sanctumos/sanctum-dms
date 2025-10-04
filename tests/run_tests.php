<?php
/**
 * DMS Test Runner
 * Custom test runner for comprehensive testing
 */

require_once __DIR__ . '/bootstrap.php';

class TestRunner {
    private $results = [];
    private $startTime;
    private $totalTests = 0;
    private $passedTests = 0;
    private $failedTests = 0;
    
    public function __construct() {
        $this->startTime = microtime(true);
    }
    
    /**
     * Run all tests
     */
    public function runAllTests() {
        echo "Running Sanctum DMS Test Suite...\n\n";
        
        $this->runUnitTests();
        $this->runApiTests();
        $this->runIntegrationTests();
        $this->runE2ETests();
        
        $this->generateReport();
    }
    
    /**
     * Run unit tests
     */
    private function runUnitTests() {
        echo "Running Unit Tests...\n";
        
        $testFiles = glob(__DIR__ . '/unit/*Test.php');
        
        foreach ($testFiles as $testFile) {
            $this->runTestFile($testFile, 'Unit');
        }
    }
    
    /**
     * Run API tests
     */
    private function runApiTests() {
        echo "Running API Tests...\n";
        
        $testFiles = glob(__DIR__ . '/api/*Test.php');
        
        foreach ($testFiles as $testFile) {
            $this->runTestFile($testFile, 'API');
        }
    }
    
    /**
     * Run integration tests
     */
    private function runIntegrationTests() {
        echo "Running Integration Tests...\n";
        
        $testFiles = glob(__DIR__ . '/integration/*Test.php');
        
        foreach ($testFiles as $testFile) {
            $this->runTestFile($testFile, 'Integration');
        }
    }
    
    /**
     * Run end-to-end tests
     */
    private function runE2ETests() {
        echo "Running E2E Tests...\n";
        
        $testFiles = glob(__DIR__ . '/e2e/*Test.php');
        
        foreach ($testFiles as $testFile) {
            $this->runTestFile($testFile, 'E2E');
        }
    }
    
    /**
     * Run individual test file
     */
    private function runTestFile($testFile, $category) {
        $className = basename($testFile, '.php');
        
        if (!class_exists($className)) {
            require_once $testFile;
        }
        
        $testInstance = new $className();
        
        // Get all test methods
        $reflection = new ReflectionClass($testInstance);
        $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);
        
        foreach ($methods as $method) {
            $methodName = $method->getName();
            
            if (strpos($methodName, 'test') === 0) {
                $this->runTestMethod($testInstance, $methodName, $className, $category);
            }
        }
    }
    
    /**
     * Run individual test method
     */
    private function runTestMethod($testInstance, $methodName, $className, $category) {
        $this->totalTests++;
        $testName = "$className::$methodName";
        
        try {
            // Set up test
            if (method_exists($testInstance, 'setUp')) {
                $testInstance->setUp();
            }
            
            // Run test
            $testInstance->$methodName();
            
            // Tear down test
            if (method_exists($testInstance, 'tearDown')) {
                $testInstance->tearDown();
            }
            
            $this->passedTests++;
            $this->results[] = [
                'name' => $testName,
                'category' => $category,
                'status' => 'PASSED',
                'message' => ''
            ];
            
            echo "  ✓ $testName\n";
            
        } catch (Exception $e) {
            $this->failedTests++;
            $this->results[] = [
                'name' => $testName,
                'category' => $category,
                'status' => 'FAILED',
                'message' => $e->getMessage()
            ];
            
            echo "  ✗ $testName - " . $e->getMessage() . "\n";
            
            // Tear down on failure
            if (method_exists($testInstance, 'tearDown')) {
                try {
                    $testInstance->tearDown();
                } catch (Exception $te) {
                    // Ignore tear down errors
                }
            }
        }
    }
    
    /**
     * Generate test report
     */
    private function generateReport() {
        $endTime = microtime(true);
        $duration = round($endTime - $this->startTime, 2);
        
        echo "\n" . str_repeat('=', 60) . "\n";
        echo "TEST REPORT\n";
        echo str_repeat('=', 60) . "\n";
        
        echo "Total Tests: $this->totalTests\n";
        echo "Passed: $this->passedTests\n";
        echo "Failed: $this->failedTests\n";
        echo "Duration: {$duration}s\n";
        
        $successRate = $this->totalTests > 0 ? round(($this->passedTests / $this->totalTests) * 100, 1) : 0;
        echo "Success Rate: {$successRate}%\n\n";
        
        // Show failed tests
        if ($this->failedTests > 0) {
            echo "FAILED TESTS:\n";
            echo str_repeat('-', 40) . "\n";
            
            foreach ($this->results as $result) {
                if ($result['status'] === 'FAILED') {
                    echo "✗ {$result['name']}\n";
                    echo "  Category: {$result['category']}\n";
                    echo "  Error: {$result['message']}\n\n";
                }
            }
        }
        
        // Generate HTML report
        $this->generateHtmlReport($duration);
        
        // Exit with appropriate code
        exit($this->failedTests > 0 ? 1 : 0);
    }
    
    /**
     * Generate HTML test report
     */
    private function generateHtmlReport($duration) {
        $html = '<!DOCTYPE html>
<html>
<head>
    <title>DMS Test Report</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .header { background: #f0f0f0; padding: 20px; border-radius: 5px; }
        .passed { color: green; }
        .failed { color: red; }
        .summary { margin: 20px 0; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Sanctum DMS Test Report</h1>
        <p>Generated: ' . date('Y-m-d H:i:s') . '</p>
    </div>
    
    <div class="summary">
        <h2>Summary</h2>
        <p>Total Tests: ' . $this->totalTests . '</p>
        <p class="passed">Passed: ' . $this->passedTests . '</p>
        <p class="failed">Failed: ' . $this->failedTests . '</p>
        <p>Duration: ' . $duration . 's</p>
        <p>Success Rate: ' . round(($this->passedTests / $this->totalTests) * 100, 1) . '%</p>
    </div>
    
    <h2>Test Results</h2>
    <table>
        <tr>
            <th>Test Name</th>
            <th>Category</th>
            <th>Status</th>
            <th>Message</th>
        </tr>';
        
        foreach ($this->results as $result) {
            $statusClass = $result['status'] === 'PASSED' ? 'passed' : 'failed';
            $html .= '<tr>
                <td>' . htmlspecialchars($result['name']) . '</td>
                <td>' . htmlspecialchars($result['category']) . '</td>
                <td class="' . $statusClass . '">' . htmlspecialchars($result['status']) . '</td>
                <td>' . htmlspecialchars($result['message']) . '</td>
            </tr>';
        }
        
        $html .= '</table>
</body>
</html>';
        
        file_put_contents(__DIR__ . '/test_report.html', $html);
        echo "HTML report generated: tests/test_report.html\n";
    }
}

// Run tests if called directly
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'])) {
    $runner = new TestRunner();
    $runner->runAllTests();
}
