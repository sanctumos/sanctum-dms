<?php
/**
 * Simple Test Runner - No hanging, clear output
 */

echo "Starting Simple Test Runner\n";
echo "==========================\n\n";

// Load bootstrap
require_once __DIR__ . '/bootstrap.php';

// Test files to run
$testFiles = [
    'DatabaseTest.php',
    'AuthTest.php', 
    'ServiceTest.php'
];

$totalTests = 0;
$passedTests = 0;
$failedTests = 0;

foreach ($testFiles as $testFile) {
    $testPath = __DIR__ . "/unit/{$testFile}";
    
    if (!file_exists($testPath)) {
        echo "✗ Test file not found: {$testPath}\n";
        continue;
    }
    
    echo "Running {$testFile}...\n";
    
    try {
        // Load test class
        require_once $testPath;
        
        $className = str_replace('.php', '', $testFile);
        if (!class_exists($className)) {
            echo "✗ Test class {$className} not found\n";
            continue;
        }
        
        $testClass = new ReflectionClass($className);
        $testMethods = $testClass->getMethods(ReflectionMethod::IS_PUBLIC);
        
        foreach ($testMethods as $method) {
            if (strpos($method->getName(), 'test') === 0) {
                $totalTests++;
                echo "  Running {$method->getName()}... ";
                
                try {
                    $testInstance = $testClass->newInstance();
                    
                    // Run setUp if exists
                    if ($testClass->hasMethod('setUp')) {
                        $testInstance->setUp();
                    }
                    
                    // Run test method
                    $startTime = microtime(true);
                    $method->invoke($testInstance);
                    $duration = round(microtime(true) - $startTime, 2);
                    
                    // Run tearDown if exists
                    if ($testClass->hasMethod('tearDown')) {
                        $testInstance->tearDown();
                    }
                    
                    $passedTests++;
                    echo "✓ ({$duration}s)\n";
                    
                } catch (Exception $e) {
                    // Check if this exception was expected
                    if (isset($testInstance->expectedException) && $e instanceof $testInstance->expectedException) {
                        // Check if the exception message matches expected
                        if (isset($testInstance->expectedExceptionMessage)) {
                            if (strpos($e->getMessage(), $testInstance->expectedExceptionMessage) !== false) {
                                $passedTests++;
                                echo "✓ (expected exception)\n";
                            } else {
                                $failedTests++;
                                echo "✗ Expected message '{$testInstance->expectedExceptionMessage}', got '{$e->getMessage()}'\n";
                            }
                        } else {
                            $passedTests++;
                            echo "✓ (expected exception)\n";
                        }
                    } else {
                        $failedTests++;
                        echo "✗ {$e->getMessage()}\n";
                    }
                }
            }
        }
        
        echo "✓ {$testFile} completed\n\n";
        
    } catch (Exception $e) {
        echo "✗ Error loading {$testFile}: {$e->getMessage()}\n\n";
    }
}

echo "Test Summary\n";
echo "============\n";
echo "Total: {$totalTests}\n";
echo "Passed: {$passedTests}\n";
echo "Failed: {$failedTests}\n";

if ($failedTests === 0) {
    echo "\n🎉 All tests passed!\n";
} else {
    echo "\n❌ {$failedTests} tests failed\n";
}
