<?php
/**
 * DMS Test Bootstrap
 * Test environment setup and configuration
 */

// Define test environment
define('DMS_TESTING', true);
define('DMS_INITIALIZED', true);

// Override database path for testing
if (!defined('DB_PATH')) {
    define('DB_PATH', __DIR__ . '/../db/test_dms.db');
}
if (!defined('DB_TEST_PATH')) {
    define('DB_TEST_PATH', __DIR__ . '/../db/test_dms.db');
}

// Load core components
require_once __DIR__ . '/../public/includes/config.php';
require_once __DIR__ . '/../public/includes/database.php';
require_once __DIR__ . '/../public/includes/auth.php';

// Load service classes
require_once __DIR__ . '/../public/includes/services/DealerManagementService.php';
require_once __DIR__ . '/../public/includes/services/VehicleService.php';
require_once __DIR__ . '/../public/includes/services/SaleService.php';
require_once __DIR__ . '/../public/includes/services/ReportsService.php';
require_once __DIR__ . '/../public/includes/services/ComplianceService.php';
require_once __DIR__ . '/../public/includes/services/AuditService.php';

// Load test data factory
require_once __DIR__ . '/factories/TestDataFactory.php';

// Initialize database for testing
$db = Database::getInstance();
$db->initializeSchema();

// Clean up any existing test data
TestDataFactory::cleanupTestData();

/**
 * Test utilities
 */
class TestUtils {
    
    /**
     * Create test database
     */
    public static function createTestDatabase() {
        // Remove existing test database
        if (file_exists(DB_TEST_PATH)) {
            unlink(DB_TEST_PATH);
        }
        
        // Create new test database
        $db = Database::getInstance();
        return $db;
    }
    
    /**
     * Clean up test database
     */
    public static function cleanupTestDatabase() {
        if (file_exists(DB_TEST_PATH)) {
            unlink(DB_TEST_PATH);
        }
    }
    
    /**
     * Create test user
     */
    public static function createTestUser($role = 'user') {
        $auth = new Auth();
        $username = 'test_' . uniqid();
        $email = $username . '@test.local';
        
        return $auth->createUser($username, $email, 'test123', $role);
    }
    
    /**
     * Create test dealer
     */
    public static function createTestDealer() {
        $db = Database::getInstance();
        $code = 'TEST_' . uniqid();
        
        $stmt = $db->prepare("
            INSERT INTO dealers (name, code, email, status) 
            VALUES (?, ?, ?, 'active')
        ");
        
        $stmt->bindValue(1, 'Test Dealer ' . uniqid(), SQLITE3_TEXT);
        $stmt->bindValue(2, $code, SQLITE3_TEXT);
        $stmt->bindValue(3, 'test@dealer.local', SQLITE3_TEXT);
        $stmt->execute();
        
        return $db->lastInsertRowID();
    }
    
    /**
     * Create test vehicle
     */
    public static function createTestVehicle($dealerId = null) {
        $db = Database::getInstance();
        
        if (!$dealerId) {
            $dealerId = self::createTestDealer();
        }
        
        $vin = 'TEST' . str_pad(uniqid(), 13, '0', STR_PAD_LEFT);
        
        $stmt = $db->prepare("
            INSERT INTO vehicles (dealer_id, vin, make, model, year, status) 
            VALUES (?, ?, ?, ?, ?, 'available')
        ");
        
        $stmt->bindValue(1, $dealerId, SQLITE3_INTEGER);
        $stmt->bindValue(2, $vin, SQLITE3_TEXT);
        $stmt->bindValue(3, 'Test Make', SQLITE3_TEXT);
        $stmt->bindValue(4, 'Test Model', SQLITE3_TEXT);
        $stmt->bindValue(5, 2023, SQLITE3_INTEGER);
        $stmt->execute();
        
        return $db->lastInsertRowID();
    }
    
    /**
     * Make API request
     */
    public static function makeApiRequest($method, $endpoint, $data = null, $headers = []) {
        $url = 'http://localhost:8080' . $endpoint;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            $headers[] = 'Content-Type: application/json';
        }
        
        if ($headers) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $body = json_decode($response ?? '', true);
        if (!is_array($body)) {
            $body = [];
        }
        
        return [
            'code' => $httpCode ?: 0,
            'body' => $body
        ];
    }
}

/**
 * Base test class
 */
abstract class BaseTest {
    protected $db;
    
    public function setUp() {
        $this->db = TestUtils::createTestDatabase();
    }
    
    public function tearDown() {
        TestUtils::cleanupTestDatabase();
    }
    
    protected function assertTrue($condition, $message = '') {
        if (!$condition) {
            throw new Exception("Assertion failed: $message");
        }
    }
    
    protected function assertFalse($condition, $message = '') {
        $this->assertTrue(!$condition, $message);
    }
    
    protected function assertEquals($expected, $actual, $message = '') {
        if ($expected !== $actual) {
            throw new Exception("Assertion failed: Expected '$expected', got '$actual'. $message");
        }
    }
    
    protected function assertNotNull($value, $message = '') {
        if ($value === null) {
            throw new Exception("Assertion failed: Value is null. $message");
        }
    }
    
    protected function assertArrayHasKey($key, $array, $message = '') {
        if (!array_key_exists($key, $array)) {
            throw new Exception("Assertion failed: Array does not have key '$key'. $message");
        }
    }
}
