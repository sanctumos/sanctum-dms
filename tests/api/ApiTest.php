<?php
/**
 * API Unit Tests
 * Test API endpoints and responses
 */

require_once __DIR__ . '/../bootstrap.php';

class ApiTest extends BaseTest {
    
    public function testStatusEndpoint() {
        // Test status endpoint
        $response = TestUtils::makeApiRequest('GET', '/api/v1/status');
        
        $this->assertEquals(200, $response['code'], 'Status endpoint should return 200');
        $this->assertTrue($response['body']['success'], 'Status response should be successful');
        
        $data = $response['body']['data'];
        $this->assertArrayHasKey('app', $data, 'Status should include app info');
        $this->assertArrayHasKey('database', $data, 'Status should include database info');
        $this->assertArrayHasKey('authentication', $data, 'Status should include auth info');
        $this->assertArrayHasKey('api', $data, 'Status should include API info');
        
        // Check app info
        $this->assertEquals(APP_NAME, $data['app']['name'], 'App name should match');
        $this->assertEquals(APP_VERSION, $data['app']['version'], 'App version should match');
        
        // Check database status
        $this->assertEquals('connected', $data['database']['status'], 'Database should be connected');
        
        // Check API info
        $this->assertEquals(API_VERSION, $data['api']['version'], 'API version should match');
    }
    
    public function testUnauthorizedEndpoints() {
        // Test that protected endpoints require authentication
        $protectedEndpoints = [
            '/api/v1/dealers',
            '/api/v1/vehicles',
            '/api/v1/sales',
            '/api/v1/customers',
            '/api/v1/users'
        ];
        
        foreach ($protectedEndpoints as $endpoint) {
            $response = TestUtils::makeApiRequest('GET', $endpoint);
            
            // Should return 401 or 501 (not implemented yet)
            $this->assertTrue(
                in_array($response['code'], [401, 501]), 
                "Endpoint $endpoint should require auth or be not implemented"
            );
        }
    }
    
    public function testApiResponseFormat() {
        // Test that all API responses follow the standard format
        $response = TestUtils::makeApiRequest('GET', '/api/v1/status');
        
        $this->assertArrayHasKey('success', $response['body'], 'Response should have success field');
        $this->assertArrayHasKey('timestamp', $response['body'], 'Response should have timestamp field');
        $this->assertArrayHasKey('version', $response['body'], 'Response should have version field');
        
        if ($response['body']['success']) {
            $this->assertArrayHasKey('data', $response['body'], 'Successful response should have data field');
        }
    }
    
    public function testNotFoundEndpoint() {
        // Test non-existent endpoint
        $response = TestUtils::makeApiRequest('GET', '/api/v1/nonexistent');
        
        $this->assertEquals(404, $response['code'], 'Non-existent endpoint should return 404');
        $this->assertFalse($response['body']['success'], 'Error response should not be successful');
        $this->assertArrayHasKey('error', $response['body'], 'Error response should have error field');
    }
    
    public function testMethodNotAllowed() {
        // Test unsupported HTTP method
        $response = TestUtils::makeApiRequest('POST', '/api/v1/status');
        
        $this->assertEquals(405, $response['code'], 'Unsupported method should return 405');
        $this->assertFalse($response['body']['success'], 'Error response should not be successful');
    }
    
    public function testApiVersioning() {
        // Test unsupported API version
        $response = TestUtils::makeApiRequest('GET', '/api/v2/status');
        
        $this->assertEquals(400, $response['code'], 'Unsupported API version should return 400');
        $this->assertFalse($response['body']['success'], 'Error response should not be successful');
    }
    
    public function testCorsHeaders() {
        // Test CORS headers in development mode
        if (DEBUG_MODE) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, 'http://localhost:8080/api/v1/status');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, true);
            curl_setopt($ch, CURLOPT_NOBODY, true);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            $this->assertEquals(200, $httpCode, 'CORS preflight should work');
            $this->assertTrue(strpos($response, 'Access-Control-Allow-Origin') !== false, 'CORS headers should be present');
        }
    }
}
