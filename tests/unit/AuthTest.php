<?php
/**
 * Unit Tests - Authentication Service
 * Tests authentication, authorization, and security features
 */

require_once __DIR__ . '/../bootstrap.php';

class AuthTest extends PHPUnit\Framework\TestCase {
    private $auth;
    private $db;

    protected function setUp(): void {
        $this->db = Database::getInstance();
        $this->auth = new Auth();
        
        // Clean up any existing test data
        $this->db->execute("DELETE FROM users WHERE username LIKE 'test_%'");
    }

    protected function tearDown(): void {
        $this->db->execute("DELETE FROM users WHERE username LIKE 'test_%'");
    }

    public function testPasswordHashing() {
        $password = 'testpassword123';
        $hash = $this->auth->hashPassword($password);
        
        $this->assertNotEquals($password, $hash);
        $this->assertTrue($this->auth->verifyPassword($password, $hash));
        $this->assertFalse($this->auth->verifyPassword('wrongpassword', $hash));
    }

    public function testApiKeyGeneration() {
        $key1 = $this->auth->generateApiKey();
        $key2 = $this->auth->generateApiKey();
        
        $this->assertNotEquals($key1, $key2);
        $this->assertEquals(64, strlen($key1));
        $this->assertEquals(64, strlen($key2));
        $this->assertMatchesRegularExpression('/^[a-f0-9]+$/', $key1);
    }

    public function testUserCreation() {
        $userData = [
            'username' => 'test_user',
            'email' => 'test@example.com',
            'password' => 'testpass123',
            'role' => 'user'
        ];
        
        $userId = $this->auth->createUser($userData);
        
        $this->assertIsInt($userId);
        $this->assertGreaterThan(0, $userId);
        
        $user = $this->db->fetchOne("SELECT * FROM users WHERE id = ?", [$userId]);
        $this->assertEquals('test_user', $user['username']);
        $this->assertEquals('test@example.com', $user['email']);
        $this->assertEquals('user', $user['role']);
        $this->assertNotNull($user['api_key']);
    }

    public function testUserAuthentication() {
        $userData = [
            'username' => 'test_auth_user',
            'email' => 'auth@example.com',
            'password' => 'authpass123',
            'role' => 'admin'
        ];
        
        $userId = $this->auth->createUser($userData);
        $user = $this->db->fetchOne("SELECT * FROM users WHERE id = ?", [$userId]);
        
        // Test API key authentication
        $_SERVER['HTTP_X_API_KEY'] = $user['api_key'];
        $authenticatedUser = $this->auth->authenticateApiKey();
        
        $this->assertNotNull($authenticatedUser);
        $this->assertEquals($userId, $authenticatedUser['id']);
        $this->assertEquals('test_auth_user', $authenticatedUser['username']);
    }

    public function testInvalidApiKey() {
        $_SERVER['HTTP_X_API_KEY'] = 'invalid_key_12345';
        $user = $this->auth->authenticateApiKey();
        
        $this->assertNull($user);
    }

    public function testRoleBasedAccess() {
        $userData = [
            'username' => 'test_role_user',
            'email' => 'role@example.com',
            'password' => 'rolepass123',
            'role' => 'user'
        ];
        
        $userId = $this->auth->createUser($userData);
        $user = $this->db->fetchOne("SELECT * FROM users WHERE id = ?", [$userId]);
        
        $_SERVER['HTTP_X_API_KEY'] = $user['api_key'];
        $authenticatedUser = $this->auth->authenticateApiKey();
        
        $this->assertTrue($this->auth->hasRole($authenticatedUser, 'user'));
        $this->assertFalse($this->auth->hasRole($authenticatedUser, 'admin'));
    }

    public function testRateLimiting() {
        $userData = [
            'username' => 'test_rate_user',
            'email' => 'rate@example.com',
            'password' => 'ratepass123',
            'role' => 'user'
        ];
        
        $userId = $this->auth->createUser($userData);
        $user = $this->db->fetchOne("SELECT * FROM users WHERE id = ?", [$userId]);
        
        $_SERVER['HTTP_X_API_KEY'] = $user['api_key'];
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        
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

    public function testSessionManagement() {
        // Start session for testing
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $userData = [
            'username' => 'test_session_user',
            'email' => 'session@example.com',
            'password' => 'sessionpass123',
            'role' => 'user'
        ];
        
        $userId = $this->auth->createUser($userData);
        
        // Test session login
        $this->auth->loginSession($userId);
        
        $this->assertTrue($this->auth->isLoggedIn());
        $this->assertEquals($userId, $this->auth->getCurrentUserId());
        
        // Test logout
        $this->auth->logout();
        $this->assertFalse($this->auth->isLoggedIn());
    }

    public function testCsrfProtection() {
        $token = $this->auth->generateCsrfToken();
        
        $this->assertIsString($token);
        $this->assertGreaterThan(20, strlen($token));
        
        $this->assertTrue($this->auth->validateCsrfToken($token));
        $this->assertFalse($this->auth->validateCsrfToken('invalid_token'));
    }

    public function testUserUpdate() {
        $userData = [
            'username' => 'test_update_user',
            'email' => 'update@example.com',
            'password' => 'updatepass123',
            'role' => 'user'
        ];
        
        $userId = $this->auth->createUser($userData);
        
        $updateData = [
            'email' => 'updated@example.com',
            'role' => 'admin'
        ];
        
        $this->auth->updateUser($userId, $updateData);
        
        $user = $this->db->fetchOne("SELECT * FROM users WHERE id = ?", [$userId]);
        $this->assertEquals('updated@example.com', $user['email']);
        $this->assertEquals('admin', $user['role']);
        $this->assertEquals('test_update_user', $user['username']); // Should remain unchanged
    }

    public function testUserDeactivation() {
        $userData = [
            'username' => 'test_deactivate_user',
            'email' => 'deactivate@example.com',
            'password' => 'deactivatepass123',
            'role' => 'user'
        ];
        
        $userId = $this->auth->createUser($userData);
        $this->auth->deactivateUser($userId);
        
        $user = $this->db->fetchOne("SELECT * FROM users WHERE id = ?", [$userId]);
        $this->assertEquals('inactive', $user['status']);
    }
}
