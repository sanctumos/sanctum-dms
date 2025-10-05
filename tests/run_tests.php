<?php
/**
 * Comprehensive Test Runner
 * Runs all test suites: Unit, Integration, and E2E tests
 */

require_once __DIR__ . '/bootstrap.php';

class TestRunner {
    private $results = [];
    private $serverProcess = null;
    private $startTime;
    
    public function __construct() {
        $this->startTime = microtime(true);
    }
    
    public function runAllTests() {
        echo "Starting Sanctum DMS Test Suite\n";
        echo "==============================\n\n";
        
        try {
            // Start test server for API and E2E tests
            $this->startTestServer();
            
            // Run test suites
            $this->runUnitTests();
            $this->runIntegrationTests();
            $this->runE2ETests();
            
        } finally {
            // Always stop server
            $this->stopTestServer();
        }
        
        $this->printSummary();
        return $this->getExitCode();
    }
    
    private function startTestServer() {
        echo "Starting test server...\n";
        
        $command = 'php -S localhost:8080 -t ' . __DIR__ . '/../public ' . __DIR__ . '/../public/router.php';
        $this->serverProcess = proc_open($command, [], $pipes);
        
        if (!is_resource($this->serverProcess)) {
            throw new Exception("Failed to start test server");
        }
        
        // Wait for server to be ready
        $maxAttempts = 10;
        for ($i = 0; $i < $maxAttempts; $i++) {
            $response = TestUtils::makeApiRequest('GET', '/api/v1/status');
            if ($response['code'] === 200) {
                echo "Test server ready\n\n";
                return;
            }
            sleep(1);
        }
        
        throw new Exception("Test server failed to start");
    }
    
    private function stopTestServer() {
        if (is_resource($this->serverProcess)) {
            proc_terminate($this->serverProcess);
            proc_close($this->serverProcess);
            echo "Test server stopped\n";
        }
    }
    
    private function runUnitTests() {
        echo "Running Unit Tests\n";
        echo "=================\n";
        
        $testFiles = [
            'DatabaseTest.php',
            'AuthTest.php', 
            'ServiceTest.php',
            'EdgeCaseTest.php',
            'SecurityTest.php',
            'PerformanceTest.php'
        ];
        
        $this->runTestSuite('unit', $testFiles);
    }
    
    private function runIntegrationTests() {
        echo "\nRunning Integration Tests\n";
        echo "========================\n";
        
        $testFiles = [
            'ApiIntegrationTest.php',
            'ServiceIntegrationTest.php',
            'CompleteApiTest.php'
        ];
        
        $this->runTestSuite('integration', $testFiles);
    }
    
    private function runE2ETests() {
        echo "\nRunning End-to-End Tests\n";
        echo "========================\n";
        
        $testFiles = [
            'E2ETest.php',
            'CompleteE2ETest.php'
        ];
        
        $this->runTestSuite('e2e', $testFiles);
    }
    
    private function runTestSuite($suite, $testFiles) {
        $suiteResults = [
            'suite' => $suite,
            'total' => 0,
            'passed' => 0,
            'failed' => 0,
            'tests' => []
        ];
        
        foreach ($testFiles as $testFile) {
            $testPath = __DIR__ . '/' . $suite . '/' . $testFile;
            
            if (!file_exists($testPath)) {
                echo "Warning: Test file not found: $testPath\n";
                continue;
            }
            
            echo "Running $testFile...\n";
            $startTime = microtime(true);
            
            // Load and run test class
            require_once $testPath;
            
            $className = str_replace('.php', '', $testFile);
            if (!class_exists($className)) {
                echo "Error: Test class $className not found\n";
                continue;
            }
            
            $testClass = new ReflectionClass($className);
            $testMethods = $testClass->getMethods(ReflectionMethod::IS_PUBLIC);
            
            foreach ($testMethods as $method) {
                if (strpos($method->getName(), 'test') === 0) {
                    $suiteResults['total']++;
                    
                    try {
                        echo "    Running {$method->getName()}...\n";
                        $testInstance = $testClass->newInstance();
                        
                        // Run setUp if exists
                        if ($testClass->hasMethod('setUp')) {
                            $testInstance->setUp();
                        }
                        
                        // Run test method with timeout
                        $startTime = microtime(true);
                        $method->invoke($testInstance);
                        $testDuration = round(microtime(true) - $startTime, 2);
                        
                        // Run tearDown if exists
                        if ($testClass->hasMethod('tearDown')) {
                            $testInstance->tearDown();
                        }
                        
                        $suiteResults['passed']++;
                        $suiteResults['tests'][] = [
                            'name' => $method->getName(),
                            'status' => 'PASS',
                            'message' => ''
                        ];
                        echo "  ✓ {$method->getName()} ({$testDuration}s)\n";
                        
                    } catch (Exception $e) {
                        $suiteResults['failed']++;
                        $suiteResults['tests'][] = [
                            'name' => $method->getName(),
                            'status' => 'FAIL',
                            'message' => $e->getMessage()
                        ];
                        echo "  ✗ {$method->getName()}: {$e->getMessage()}\n";
                    }
                }
            }
            
            $duration = round(microtime(true) - $startTime, 2);
            echo "✓ {$testFile} completed in {$duration}s\n\n";
        }
        
        $this->results[] = $suiteResults;
        
        // Print suite summary
        echo "\n$suite Results: {$suiteResults['passed']}/{$suiteResults['total']} passed\n";
        if ($suiteResults['failed'] > 0) {
            echo "Failed tests: {$suiteResults['failed']}\n";
        }
    }
    
    private function printSummary() {
        $totalTests = 0;
        $totalPassed = 0;
        $totalFailed = 0;
        
        foreach ($this->results as $suite) {
            $totalTests += $suite['total'];
            $totalPassed += $suite['passed'];
            $totalFailed += $suite['failed'];
        }
        
        $duration = microtime(true) - $this->startTime;
        
        echo "\n";
        echo "Test Summary\n";
        echo "============\n";
        echo "Total Tests: $totalTests\n";
        echo "Passed: $totalPassed\n";
        echo "Failed: $totalFailed\n";
        echo "Duration: " . round($duration, 2) . " seconds\n";
        
        if ($totalFailed > 0) {
            echo "\nFailed Tests:\n";
            foreach ($this->results as $suite) {
                foreach ($suite['tests'] as $test) {
                    if ($test['status'] === 'FAIL') {
                        echo "  {$suite['suite']}/{$test['name']}: {$test['message']}\n";
                    }
                }
            }
        }
        
        echo "\n";
        if ($totalFailed === 0) {
            echo "🎉 All tests passed!\n";
        } else {
            echo "❌ Some tests failed.\n";
        }
    }
    
    private function getExitCode() {
        foreach ($this->results as $suite) {
            if ($suite['failed'] > 0) {
                return 1;
            }
        }
        return 0;
    }
}

// Run tests if called directly
if (basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'])) {
    $runner = new TestRunner();
    exit($runner->runAllTests());
}