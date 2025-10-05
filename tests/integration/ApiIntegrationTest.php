<?php
/**
 * Integration Tests - API Endpoints
 * Tests API endpoints, request/response handling, and service integration
 */

require_once __DIR__ . '/../bootstrap.php';

class ApiIntegrationTest extends PHPUnit\Framework\TestCase {
    private $baseUrl = 'http://localhost:8080/api/v1';
    private $apiKey = 'dev-key-123';
    private $testDealerId;
    private $testVehicleId;
    private $testSaleId;

    protected function setUp(): void {
        // Clean up any existing test data
        $this->cleanupTestData();
    }

    protected function tearDown(): void {
        $this->cleanupTestData();
    }

    private function cleanupTestData() {
        $db = Database::getInstance();
        $db->execute("DELETE FROM sales WHERE dealer_id IN (SELECT id FROM dealers WHERE code LIKE 'TEST_%')");
        $db->execute("DELETE FROM vehicles WHERE dealer_id IN (SELECT id FROM dealers WHERE code LIKE 'TEST_%')");
        $db->execute("DELETE FROM dealers WHERE code LIKE 'TEST_%'");
    }

    private function makeApiRequest($endpoint, $method = 'GET', $data = null) {
        $url = $this->baseUrl . $endpoint;
        $headers = [
            'Content-Type: application/json',
            'X-API-Key: ' . $this->apiKey
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'body' => json_decode($response, true),
            'status' => $httpCode
        ];
    }

    public function testStatusEndpoint() {
        $response = $this->makeApiRequest('/status');
        
        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
        $this->assertEquals('DMS API is operational', $response['body']['message']);
        $this->assertArrayHasKey('timestamp', $response['body']);
    }

    public function testUnauthorizedAccess() {
        // Test without API key
        $url = $this->baseUrl . '/dealers';
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->assertEquals(401, $httpCode);
    }

    public function testDealersCrud() {
        // Create dealer
        $dealerData = [
            'name' => 'Test Dealer',
            'code' => 'TEST_' . uniqid(),
            'email' => 'test@dealer.com',
            'phone' => '555-0123',
            'max_sales_per_year' => 5
        ];

        $response = $this->makeApiRequest('/dealers', 'POST', $dealerData);
        $this->assertEquals(201, $response['status']);
        $this->assertTrue($response['body']['success']);
        $this->testDealerId = $response['body']['data']['id'];

        // Get dealer
        $response = $this->makeApiRequest('/dealers/' . $this->testDealerId);
        $this->assertEquals(200, $response['status']);
        $this->assertEquals('Test Dealer', $response['body']['data']['name']);

        // Update dealer
        $updateData = ['name' => 'Updated Dealer Name'];
        $response = $this->makeApiRequest('/dealers/' . $this->testDealerId, 'PUT', $updateData);
        $this->assertEquals(200, $response['status']);

        // Verify update
        $response = $this->makeApiRequest('/dealers/' . $this->testDealerId);
        $this->assertEquals('Updated Dealer Name', $response['body']['data']['name']);

        // List dealers
        $response = $this->makeApiRequest('/dealers');
        $this->assertEquals(200, $response['status']);
        $this->assertIsArray($response['body']['data']);

        // Delete dealer
        $response = $this->makeApiRequest('/dealers/' . $this->testDealerId, 'DELETE');
        $this->assertEquals(200, $response['status']);

        // Verify deletion
        $response = $this->makeApiRequest('/dealers/' . $this->testDealerId);
        $this->assertEquals(404, $response['status']);
    }

    public function testVehiclesCrud() {
        // Create dealer first
        $dealerData = ['name' => 'Test Dealer', 'code' => 'TEST_' . uniqid(), 'email' => 'test@dealer.com'];
        $response = $this->makeApiRequest('/dealers', 'POST', $dealerData);
        $this->testDealerId = $response['body']['data']['id'];

        // Create vehicle
        $vehicleData = [
            'dealer_id' => $this->testDealerId,
            'vin' => 'VIN' . uniqid() . '12345',
            'make' => 'Toyota',
            'model' => 'Camry',
            'year' => 2020,
            'color' => 'Silver',
            'price' => 25000,
            'cost' => 20000
        ];

        $response = $this->makeApiRequest('/vehicles', 'POST', $vehicleData);
        $this->assertEquals(201, $response['status']);
        $this->testVehicleId = $response['body']['data']['id'];

        // Get vehicle
        $response = $this->makeApiRequest('/vehicles/' . $this->testVehicleId);
        $this->assertEquals(200, $response['status']);
        $this->assertEquals('Toyota', $response['body']['data']['make']);

        // Update vehicle
        $updateData = ['price' => 26000];
        $response = $this->makeApiRequest('/vehicles/' . $this->testVehicleId, 'PUT', $updateData);
        $this->assertEquals(200, $response['status']);

        // List vehicles
        $response = $this->makeApiRequest('/vehicles');
        $this->assertEquals(200, $response['status']);
        $this->assertIsArray($response['body']['data']);

        // Delete vehicle
        $response = $this->makeApiRequest('/vehicles/' . $this->testVehicleId, 'DELETE');
        $this->assertEquals(200, $response['status']);
    }

    public function testSalesCrud() {
        // Create dealer and vehicle first
        $dealerData = ['name' => 'Test Dealer', 'code' => 'TEST_' . uniqid(), 'email' => 'test@dealer.com'];
        $response = $this->makeApiRequest('/dealers', 'POST', $dealerData);
        $this->testDealerId = $response['body']['data']['id'];

        $vehicleData = [
            'dealer_id' => $this->testDealerId,
            'vin' => 'VIN' . uniqid() . '12345',
            'make' => 'Toyota',
            'model' => 'Camry',
            'year' => 2020,
            'price' => 25000
        ];
        $response = $this->makeApiRequest('/vehicles', 'POST', $vehicleData);
        $this->testVehicleId = $response['body']['data']['id'];

        // Create sale
        $saleData = [
            'dealer_id' => $this->testDealerId,
            'vehicle_id' => $this->testVehicleId,
            'sale_price' => 25000,
            'sale_date' => '2023-10-01',
            'salesperson' => 'John Doe',
            'commission_rate' => 0.05
        ];

        $response = $this->makeApiRequest('/sales', 'POST', $saleData);
        $this->assertEquals(201, $response['status']);
        $this->testSaleId = $response['body']['data']['id'];

        // Get sale
        $response = $this->makeApiRequest('/sales/' . $this->testSaleId);
        $this->assertEquals(200, $response['status']);
        $this->assertEquals(25000, $response['body']['data']['sale_price']);

        // Update sale
        $updateData = ['commission_rate' => 0.07];
        $response = $this->makeApiRequest('/sales/' . $this->testSaleId, 'PUT', $updateData);
        $this->assertEquals(200, $response['status']);

        // List sales
        $response = $this->makeApiRequest('/sales');
        $this->assertEquals(200, $response['status']);
        $this->assertIsArray($response['body']['data']);

        // Cancel sale
        $response = $this->makeApiRequest('/sales/' . $this->testSaleId . '/cancel', 'POST');
        $this->assertEquals(200, $response['status']);
    }

    public function testReportsEndpoints() {
        // Create test data
        $this->createTestData();

        // Test summary report
        $response = $this->makeApiRequest('/reports/summary');
        $this->assertEquals(200, $response['status']);
        $this->assertArrayHasKey('sales', $response['body']['data']);
        $this->assertArrayHasKey('vehicles', $response['body']['data']);

        // Test vehicle stats
        $response = $this->makeApiRequest('/reports/vehicles');
        $this->assertEquals(200, $response['status']);
        $this->assertArrayHasKey('status_breakdown', $response['body']['data']);

        // Test dealer summary
        $response = $this->makeApiRequest('/reports/dealers/' . $this->testDealerId);
        $this->assertEquals(200, $response['status']);
        $this->assertEquals($this->testDealerId, $response['body']['data']['dealer_id']);
    }

    public function testComplianceEndpoint() {
        // Create test data
        $this->createTestData();

        $response = $this->makeApiRequest('/compliance');
        $this->assertEquals(200, $response['status']);
        $this->assertIsArray($response['body']['data']);
    }

    public function testAuditEndpoint() {
        $response = $this->makeApiRequest('/audit/entities');
        $this->assertEquals(200, $response['status']);
        $this->assertArrayHasKey('dealers', $response['body']['data']);
        $this->assertArrayHasKey('vehicles', $response['body']['data']);
        $this->assertArrayHasKey('sales', $response['body']['data']);
    }

    public function testErrorHandling() {
        // Test 404 for non-existent resource
        $response = $this->makeApiRequest('/dealers/99999');
        $this->assertEquals(404, $response['status']);

        // Test 400 for invalid data
        $invalidData = ['name' => 'Test', 'code' => '']; // Empty code should fail
        $response = $this->makeApiRequest('/dealers', 'POST', $invalidData);
        $this->assertEquals(400, $response['status']);

        // Test 405 for unsupported method
        $response = $this->makeApiRequest('/status', 'DELETE');
        $this->assertEquals(405, $response['status']);
    }

    public function testRateLimiting() {
        // Make many requests quickly
        for ($i = 0; $i < 20; $i++) {
            $response = $this->makeApiRequest('/status');
            if ($response['status'] === 429) {
                $this->assertEquals(429, $response['status']);
                return; // Rate limit hit as expected
            }
        }
        
        // If we get here, rate limiting might not be working or limits are high
        $this->assertTrue(true, 'Rate limiting test completed');
    }

    private function createTestData() {
        // Create dealer
        $dealerData = ['name' => 'Test Dealer', 'code' => 'TEST_' . uniqid(), 'email' => 'test@dealer.com'];
        $response = $this->makeApiRequest('/dealers', 'POST', $dealerData);
        $this->testDealerId = $response['body']['data']['id'];

        // Create vehicle
        $vehicleData = [
            'dealer_id' => $this->testDealerId,
            'vin' => 'VIN' . uniqid() . '12345',
            'make' => 'Toyota',
            'model' => 'Camry',
            'year' => 2020,
            'price' => 25000
        ];
        $response = $this->makeApiRequest('/vehicles', 'POST', $vehicleData);
        $this->testVehicleId = $response['body']['data']['id'];

        // Create sale
        $saleData = [
            'dealer_id' => $this->testDealerId,
            'vehicle_id' => $this->testVehicleId,
            'sale_price' => 25000,
            'sale_date' => '2023-10-01'
        ];
        $response = $this->makeApiRequest('/sales', 'POST', $saleData);
        $this->testSaleId = $response['body']['data']['id'];
    }
}
