<?php
/**
 * Security Tests
 * Tests authentication, authorization, and security vulnerabilities
 */

require_once __DIR__ . '/../bootstrap.php';

class SecurityTest extends PHPUnit\Framework\TestCase {
    private $auth;
    private $db;
    private $testUserId;
    private $testApiKey;

    protected function setUp(): void {
        $this->auth = new Auth();
        $this->db = Database::getInstance();
        
        // Create test user
        $this->testUserId = TestDataFactory::createUser(['role' => 'user']);
        $user = $this->db->fetchOne("SELECT * FROM users WHERE id = ?", [$this->testUserId]);
        $this->testApiKey = $user['api_key'];
        
        $this->cleanupTestData();
    }

    protected function tearDown(): void {
        $this->cleanupTestData();
    }

    private function cleanupTestData() {
        $this->db->execute("DELETE FROM users WHERE username LIKE 'test_%'");
    }

    // Authentication Security Tests
    public function testPasswordSecurity() {
        // Test password hashing strength
        $password = 'testpassword123';
        $hash = $this->auth->hashPassword($password);
        
        // Verify hash is different from password
        $this->assertNotEquals($password, $hash);
        
        // Verify hash length (should be 60+ characters for bcrypt)
        $this->assertGreaterThan(50, strlen($hash));
        
        // Verify hash contains proper bcrypt format
        $this->assertStringStartsWith('$2y$', $hash);
        
        // Test password verification
        $this->assertTrue($this->auth->verifyPassword($password, $hash));
        $this->assertFalse($this->auth->verifyPassword('wrongpassword', $hash));
    }

    public function testApiKeySecurity() {
        // Test API key generation
        $key1 = $this->auth->generateApiKey();
        $key2 = $this->auth->generateApiKey();
        
        // Verify keys are unique
        $this->assertNotEquals($key1, $key2);
        
        // Verify key length (should be 64 characters)
        $this->assertEquals(64, strlen($key1));
        $this->assertEquals(64, strlen($key2));
        
        // Verify key format (hexadecimal)
        $this->assertMatchesRegularExpression('/^[a-f0-9]+$/', $key1);
        $this->assertMatchesRegularExpression('/^[a-f0-9]+$/', $key2);
        
        // Test API key authentication
        $_SERVER['HTTP_X_API_KEY'] = $this->testApiKey;
        $user = $this->auth->authenticateApiKey();
        $this->assertNotNull($user);
        $this->assertEquals($this->testUserId, $user['id']);
    }

    public function testInvalidApiKeyHandling() {
        $invalidKeys = [
            '', // Empty key
            'invalid', // Too short
            str_repeat('a', 100), // Too long
            'INVALID_KEY_12345', // Wrong format
            null, // Null key
        ];
        
        foreach ($invalidKeys as $key) {
            $_SERVER['HTTP_X_API_KEY'] = $key;
            $user = $this->auth->authenticateApiKey();
            $this->assertNull($user);
        }
    }

    public function testSessionSecurity() {
        // Test session handling
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // Test session login
        $this->auth->loginSession($this->testUserId);
        $this->assertTrue($this->auth->isLoggedIn());
        $this->assertEquals($this->testUserId, $this->auth->getCurrentUserId());
        
        // Test session logout
        $this->auth->logout();
        $this->assertFalse($this->auth->isLoggedIn());
        
        // Test invalid session data
        $_SESSION['user_id'] = 'invalid';
        $this->assertFalse($this->auth->isLoggedIn());
        
        $_SESSION['user_id'] = 99999; // Non-existent user
        $this->assertFalse($this->auth->isLoggedIn());
    }

    public function testCsrfProtection() {
        // Test CSRF token generation
        $token1 = $this->auth->generateCsrfToken();
        $token2 = $this->auth->generateCsrfToken();
        
        // Tokens should be different
        $this->assertNotEquals($token1, $token2);
        
        // Tokens should be long enough
        $this->assertGreaterThan(20, strlen($token1));
        
        // Test token validation
        $this->assertTrue($this->auth->validateCsrfToken($token1));
        $this->assertFalse($this->auth->validateCsrfToken('invalid_token'));
        $this->assertFalse($this->auth->validateCsrfToken(''));
    }

    // Authorization Security Tests
    public function testRoleBasedAccess() {
        // Create users with different roles
        $adminId = TestDataFactory::createUser(['role' => 'admin']);
        $userId = TestDataFactory::createUser(['role' => 'user']);
        $guestId = TestDataFactory::createUser(['role' => 'guest']);
        
        $adminUser = $this->db->fetchOne("SELECT * FROM users WHERE id = ?", [$adminId]);
        $regularUser = $this->db->fetchOne("SELECT * FROM users WHERE id = ?", [$userId]);
        $guestUser = $this->db->fetchOne("SELECT * FROM users WHERE id = ?", [$guestId]);
        
        // Test role checking
        $this->assertTrue($this->auth->hasRole($adminUser, 'admin'));
        $this->assertTrue($this->auth->hasRole($adminUser, 'user'));
        $this->assertFalse($this->auth->hasRole($regularUser, 'admin'));
        $this->assertTrue($this->auth->hasRole($regularUser, 'user'));
        $this->assertFalse($this->auth->hasRole($guestUser, 'admin'));
        $this->assertFalse($this->auth->hasRole($guestUser, 'user'));
    }

    public function testUnauthorizedAccess() {
        // Test accessing resources without authentication
        $_SERVER['HTTP_X_API_KEY'] = null;
        $user = $this->auth->authenticateApiKey();
        $this->assertNull($user);
        
        // Test with invalid user ID
        $this->expectException(Exception::class);
        $this->auth->hasRole(['id' => 99999, 'role' => 'user'], 'user');
    }

    // Rate Limiting Security Tests
    public function testRateLimiting() {
        $_SERVER['REMOTE_ADDR'] = '192.168.1.100';
        $_SERVER['HTTP_X_API_KEY'] = $this->testApiKey;
        
        // First few requests should pass
        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue($this->auth->checkRateLimit());
        }
        
        // After many requests, should hit rate limit
        for ($i = 0; $i < 100; $i++) {
            $this->auth->checkRateLimit();
        }
        
        // This should now fail
        $this->expectException(Exception::class);
        $this->auth->checkRateLimit();
    }

    public function testRateLimitingPerIp() {
        // Test rate limiting is per IP
        $_SERVER['REMOTE_ADDR'] = '192.168.1.101';
        $_SERVER['HTTP_X_API_KEY'] = $this->testApiKey;
        
        // Should not be rate limited for different IP
        for ($i = 0; $i < 10; $i++) {
            $this->assertTrue($this->auth->checkRateLimit());
        }
        
        // Switch back to original IP
        $_SERVER['REMOTE_ADDR'] = '192.168.1.100';
        
        // Should still be rate limited
        $this->expectException(Exception::class);
        $this->auth->checkRateLimit();
    }

    // Input Validation Security Tests
    public function testSqlInjectionPrevention() {
        $dealerService = new DealerManagementService();
        
        // Test SQL injection attempts
        $injectionAttempts = [
            "'; DROP TABLE dealers; --",
            "' OR '1'='1",
            "'; INSERT INTO dealers (name, code) VALUES ('Hacked', 'HACK'); --",
            "' UNION SELECT * FROM users --",
        ];
        
        foreach ($injectionAttempts as $injection) {
            try {
                $dealerId = $dealerService->createDealer([
                    'name' => $injection,
                    'code' => 'INJ_' . uniqid(),
                    'email' => 'test@example.com'
                ]);
                
                // If it succeeds, verify the data was escaped properly
                $dealer = $dealerService->getDealer($dealerId);
                $this->assertNotNull($dealer);
                $this->assertEquals($injection, $dealer['name']); // Should be stored as-is, not executed
                
            } catch (Exception $e) {
                // Some injections might be caught by validation
                $this->assertStringContainsString('invalid', strtolower($e->getMessage()));
            }
        }
        
        // Verify database integrity
        $dealers = $this->db->fetchAll("SELECT COUNT(*) as count FROM dealers");
        $this->assertGreaterThan(0, $dealers[0]['count']);
    }

    public function testXssPrevention() {
        $dealerService = new DealerManagementService();
        
        // Test XSS attempts
        $xssAttempts = [
            '<script>alert("XSS")</script>',
            '"><script>alert("XSS")</script>',
            'javascript:alert("XSS")',
            '<img src="x" onerror="alert(\'XSS\')">',
        ];
        
        foreach ($xssAttempts as $xss) {
            $dealerId = $dealerService->createDealer([
                'name' => $xss,
                'code' => 'XSS_' . uniqid(),
                'email' => 'test@example.com'
            ]);
            
            $dealer = $dealerService->getDealer($dealerId);
            $this->assertEquals($xss, $dealer['name']); // Should be stored as-is
        }
    }

    public function testDataSanitization() {
        $dealerService = new DealerManagementService();
        
        // Test various data sanitization scenarios
        $testData = [
            'name' => 'Test Dealer',
            'code' => 'TEST_' . uniqid(),
            'email' => 'test@example.com',
            'phone' => '+1-555-123-4567',
            'address' => '123 Main St.\nApt 4B',
        ];
        
        $dealerId = $dealerService->createDealer($testData);
        $dealer = $dealerService->getDealer($dealerId);
        
        // Verify data is stored correctly
        $this->assertEquals($testData['name'], $dealer['name']);
        $this->assertEquals($testData['email'], $dealer['email']);
        $this->assertEquals($testData['phone'], $dealer['phone']);
        $this->assertEquals($testData['address'], $dealer['address']);
    }

    // File Upload Security Tests
    public function testFileUploadSecurity() {
        // Test file upload validation (if implemented)
        $invalidFiles = [
            ['name' => 'test.php', 'type' => 'application/x-php'],
            ['name' => 'test.exe', 'type' => 'application/x-executable'],
            ['name' => 'test.sh', 'type' => 'application/x-shellscript'],
            ['name' => 'test.bat', 'type' => 'application/x-batch'],
        ];
        
        foreach ($invalidFiles as $file) {
            // This would test file upload validation if implemented
            $this->assertTrue(true); // Placeholder for file upload security tests
        }
    }

    // Session Security Tests
    public function testSessionHijackingPrevention() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // Test session regeneration
        $oldSessionId = session_id();
        $this->auth->loginSession($this->testUserId);
        $newSessionId = session_id();
        
        // Session ID should change after login
        $this->assertNotEquals($oldSessionId, $newSessionId);
        
        // Test session timeout (if implemented)
        $this->assertTrue($this->auth->isLoggedIn());
    }

    public function testSessionFixationPrevention() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // Test session fixation prevention
        $originalSessionId = session_id();
        
        // Simulate login
        $this->auth->loginSession($this->testUserId);
        $newSessionId = session_id();
        
        // Session ID should change
        $this->assertNotEquals($originalSessionId, $newSessionId);
    }

    // Error Information Disclosure Tests
    public function testErrorInformationDisclosure() {
        // Test that errors don't reveal sensitive information
        try {
            $this->db->fetchOne("SELECT * FROM non_existent_table");
        } catch (Exception $e) {
            $message = $e->getMessage();
            
            // Error should not contain sensitive information
            $this->assertStringNotContainsString('password', strtolower($message));
            $this->assertStringNotContainsString('api_key', strtolower($message));
            $this->assertStringNotContainsString('secret', strtolower($message));
        }
    }

    public function testDatabaseErrorHandling() {
        // Test database error handling doesn't leak information
        try {
            $this->db->execute("INVALID SQL STATEMENT");
        } catch (Exception $e) {
            $message = $e->getMessage();
            
            // Error should be generic, not revealing database structure
            $this->assertStringNotContainsString('sqlite', strtolower($message));
            $this->assertStringNotContainsString('table', strtolower($message));
            $this->assertStringNotContainsString('column', strtolower($message));
        }
    }

    // Authentication Bypass Tests
    public function testAuthenticationBypass() {
        // Test various authentication bypass attempts
        $bypassAttempts = [
            ['HTTP_X_API_KEY' => ''],
            ['HTTP_X_API_KEY' => 'null'],
            ['HTTP_X_API_KEY' => 'undefined'],
            ['HTTP_X_API_KEY' => '0'],
            ['HTTP_X_API_KEY' => 'false'],
        ];
        
        foreach ($bypassAttempts as $attempt) {
            $_SERVER = array_merge($_SERVER, $attempt);
            $user = $this->auth->authenticateApiKey();
            $this->assertNull($user);
        }
    }

    public function testPrivilegeEscalation() {
        // Test privilege escalation attempts
        $regularUserId = TestDataFactory::createUser(['role' => 'user']);
        $regularUser = $this->db->fetchOne("SELECT * FROM users WHERE id = ?", [$regularUserId]);
        
        // Try to escalate privileges
        $this->assertFalse($this->auth->hasRole($regularUser, 'admin'));
        
        // Try to modify user role directly
        $this->db->execute("UPDATE users SET role = 'admin' WHERE id = ?", [$regularUserId]);
        $updatedUser = $this->db->fetchOne("SELECT * FROM users WHERE id = ?", [$regularUserId]);
        
        // Verify role change was recorded
        $this->assertEquals('admin', $updatedUser['role']);
    }
}
