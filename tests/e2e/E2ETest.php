<?php
/**
 * End-to-End Tests - Full User Workflows
 * Tests complete user journeys through the DMS system
 */

require_once __DIR__ . '/../bootstrap.php';

class E2ETest extends PHPUnit\Framework\TestCase {
    private $baseUrl = 'http://localhost:8080';
    private $apiBaseUrl = 'http://localhost:8080/api/v1';
    private $apiKey = 'dev-key-123';
    private $testData = [];

    protected function setUp(): void {
        $this->cleanupTestData();
    }

    protected function tearDown(): void {
        $this->cleanupTestData();
    }

    private function cleanupTestData() {
        $db = Database::getInstance();
        $db->execute("DELETE FROM sales WHERE dealer_id IN (SELECT id FROM dealers WHERE code LIKE 'E2E_%')");
        $db->execute("DELETE FROM vehicles WHERE dealer_id IN (SELECT id FROM dealers WHERE code LIKE 'E2E_%')");
        $db->execute("DELETE FROM dealers WHERE code LIKE 'E2E_%'");
    }

    private function makeApiRequest($endpoint, $method = 'GET', $data = null) {
        $url = $this->apiBaseUrl . $endpoint;
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

    private function makeWebRequest($endpoint) {
        $url = $this->baseUrl . $endpoint;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'body' => $response,
            'status' => $httpCode
        ];
    }

    public function testCompleteDealerWorkflow() {
        // Step 1: Create a new dealer
        $dealerData = [
            'name' => 'E2E Test Dealer',
            'code' => 'E2E_' . uniqid(),
            'email' => 'e2e@testdealer.com',
            'phone' => '555-0123',
            'max_sales_per_year' => 3
        ];

        $response = $this->makeApiRequest('/dealers', 'POST', $dealerData);
        $this->assertEquals(201, $response['status']);
        $dealerId = $response['body']['data']['id'];
        $this->testData['dealer_id'] = $dealerId;

        // Step 2: Add vehicles to inventory
        $vehicles = [
            [
                'vin' => 'E2E123456789012345',
                'make' => 'Toyota',
                'model' => 'Camry',
                'year' => 2020,
                'color' => 'Silver',
                'price' => 25000,
                'cost' => 20000
            ],
            [
                'vin' => 'E2E223456789012345',
                'make' => 'Honda',
                'model' => 'Civic',
                'year' => 2021,
                'color' => 'Blue',
                'price' => 22000,
                'cost' => 18000
            ]
        ];

        $vehicleIds = [];
        foreach ($vehicles as $vehicleData) {
            $vehicleData['dealer_id'] = $dealerId;
            $response = $this->makeApiRequest('/vehicles', 'POST', $vehicleData);
            $this->assertEquals(201, $response['status']);
            $vehicleIds[] = $response['body']['data']['id'];
        }
        $this->testData['vehicle_ids'] = $vehicleIds;

        // Step 3: Verify dealer inventory
        $response = $this->makeApiRequest('/dealers/' . $dealerId . '/inventory');
        $this->assertEquals(200, $response['status']);
        $this->assertCount(2, $response['body']['data']);

        // Step 4: Record sales
        $sales = [
            [
                'vehicle_id' => $vehicleIds[0],
                'sale_price' => 25000,
                'sale_date' => '2023-10-01',
                'salesperson' => 'John Doe',
                'commission_rate' => 0.05
            ],
            [
                'vehicle_id' => $vehicleIds[1],
                'sale_price' => 22000,
                'sale_date' => '2023-10-02',
                'salesperson' => 'Jane Smith',
                'commission_rate' => 0.06
            ]
        ];

        $saleIds = [];
        foreach ($sales as $saleData) {
            $saleData['dealer_id'] = $dealerId;
            $response = $this->makeApiRequest('/sales', 'POST', $saleData);
            $this->assertEquals(201, $response['status']);
            $saleIds[] = $response['body']['data']['id'];
        }
        $this->testData['sale_ids'] = $saleIds;

        // Step 5: Verify sales were recorded
        $response = $this->makeApiRequest('/sales');
        $this->assertEquals(200, $response['status']);
        $this->assertGreaterThanOrEqual(2, count($response['body']['data']));

        // Step 6: Check dealer compliance
        $response = $this->makeApiRequest('/compliance');
        $this->assertEquals(200, $response['status']);
        $dealerCompliance = array_filter($response['body']['data'], fn($d) => $d['dealer_id'] === $dealerId);
        $dealerCompliance = reset($dealerCompliance);
        $this->assertEquals(2, $dealerCompliance['sales_this_year']);
        $this->assertEquals('compliant', $dealerCompliance['status']);

        // Step 7: Generate reports
        $response = $this->makeApiRequest('/reports/summary');
        $this->assertEquals(200, $response['status']);
        $this->assertGreaterThanOrEqual(2, $response['body']['data']['sales']['count']);

        $response = $this->makeApiRequest('/reports/dealers/' . $dealerId);
        $this->assertEquals(200, $response['status']);
        $this->assertEquals(2, $response['body']['data']['sales_count']);
    }

    public function testInventoryManagementWorkflow() {
        $dealerId = $this->createTestDealer();
        
        // Add multiple vehicles
        $vehicles = [
            ['vin' => 'E2E123456789012345', 'make' => 'Toyota', 'model' => 'Camry', 'year' => 2020, 'price' => 25000, 'cost' => 20000],
            ['vin' => 'E2E223456789012345', 'make' => 'Honda', 'model' => 'Civic', 'year' => 2021, 'price' => 22000, 'cost' => 18000],
            ['vin' => 'E2E323456789012345', 'make' => 'Ford', 'model' => 'Focus', 'year' => 2019, 'price' => 18000, 'cost' => 15000]
        ];

        $vehicleIds = [];
        foreach ($vehicles as $vehicleData) {
            $vehicleData['dealer_id'] = $dealerId;
            $response = $this->makeApiRequest('/vehicles', 'POST', $vehicleData);
            $this->assertEquals(201, $response['status']);
            $vehicleIds[] = $response['body']['data']['id'];
        }

        // Update vehicle prices
        $response = $this->makeApiRequest('/vehicles/' . $vehicleIds[0], 'PUT', ['price' => 26000]);
        $this->assertEquals(200, $response['status']);

        // Verify price update
        $response = $this->makeApiRequest('/vehicles/' . $vehicleIds[0]);
        $this->assertEquals(26000, $response['body']['data']['price']);

        // Check computed columns
        $response = $this->makeApiRequest('/vehicles/' . $vehicleIds[0]);
        $vehicle = $response['body']['data'];
        $this->assertEquals(6000, $vehicle['margin']); // 26000 - 20000
        $this->assertEquals('high', $vehicle['profit_class']); // > 5000

        // Get vehicle statistics
        $response = $this->makeApiRequest('/reports/vehicles');
        $this->assertEquals(200, $response['status']);
        $this->assertArrayHasKey('profit_class_breakdown', $response['body']['data']);
    }

    public function testSalesProcessWorkflow() {
        $dealerId = $this->createTestDealer();
        $vehicleId = $this->createTestVehicle($dealerId, 'E2E123456789012345', 25000, 20000);

        // Record sale
        $saleData = [
            'dealer_id' => $dealerId,
            'vehicle_id' => $vehicleId,
            'sale_price' => 25000,
            'sale_date' => '2023-10-01',
            'salesperson' => 'John Doe',
            'commission_rate' => 0.05
        ];

        $response = $this->makeApiRequest('/sales', 'POST', $saleData);
        $this->assertEquals(201, $response['status']);
        $saleId = $response['body']['data']['id'];

        // Verify vehicle status changed
        $response = $this->makeApiRequest('/vehicles/' . $vehicleId);
        $this->assertEquals('sold', $response['body']['data']['status']);

        // Verify commission calculation
        $response = $this->makeApiRequest('/sales/' . $saleId);
        $this->assertEquals(1250, $response['body']['data']['commission']); // 5% of 25000

        // Update sale
        $response = $this->makeApiRequest('/sales/' . $saleId, 'PUT', ['commission_rate' => 0.07]);
        $this->assertEquals(200, $response['status']);

        // Verify commission update
        $response = $this->makeApiRequest('/sales/' . $saleId);
        $this->assertEquals(1750, $response['body']['data']['commission']); // 7% of 25000

        // Cancel sale
        $response = $this->makeApiRequest('/sales/' . $saleId . '/cancel', 'POST');
        $this->assertEquals(200, $response['status']);

        // Verify vehicle status reverted
        $response = $this->makeApiRequest('/vehicles/' . $vehicleId);
        $this->assertEquals('available', $response['body']['data']['status']);
    }

    public function testComplianceMonitoringWorkflow() {
        $dealerId = $this->createTestDealer();
        
        // Set low sales limit for testing
        $response = $this->makeApiRequest('/dealers/' . $dealerId, 'PUT', ['max_sales_per_year' => 2]);
        $this->assertEquals(200, $response['status']);

        // Create vehicles and sales up to limit
        $vehicleId1 = $this->createTestVehicle($dealerId, 'E2E123456789012345');
        $vehicleId2 = $this->createTestVehicle($dealerId, 'E2E223456789012345');

        $this->createTestSale($dealerId, $vehicleId1, 25000);
        $this->createTestSale($dealerId, $vehicleId2, 30000);

        // Check compliance - should be compliant
        $response = $this->makeApiRequest('/compliance');
        $dealerCompliance = array_filter($response['body']['data'], fn($d) => $d['dealer_id'] === $dealerId);
        $dealerCompliance = reset($dealerCompliance);
        $this->assertEquals('compliant', $dealerCompliance['status']);
        $this->assertEquals(0, $dealerCompliance['remaining']);

        // Try to exceed limit
        $vehicleId3 = $this->createTestVehicle($dealerId, 'E2E323456789012345');
        $response = $this->makeApiRequest('/sales', 'POST', [
            'dealer_id' => $dealerId,
            'vehicle_id' => $vehicleId3,
            'sale_price' => 35000,
            'sale_date' => '2023-10-01'
        ]);
        $this->assertEquals(400, $response['status']); // Should fail due to compliance
    }

    public function testReportingWorkflow() {
        $dealerId = $this->createTestDealer();
        
        // Create diverse test data
        $vehicles = [
            ['vin' => 'E2E123456789012345', 'make' => 'Toyota', 'model' => 'Camry', 'year' => 2020, 'price' => 30000, 'cost' => 20000],
            ['vin' => 'E2E223456789012345', 'make' => 'Honda', 'model' => 'Civic', 'year' => 2021, 'price' => 25000, 'cost' => 22000],
            ['vin' => 'E2E323456789012345', 'make' => 'Ford', 'model' => 'Focus', 'year' => 2019, 'price' => 18000, 'cost' => 15000]
        ];

        $vehicleIds = [];
        foreach ($vehicles as $vehicleData) {
            $vehicleData['dealer_id'] = $dealerId;
            $response = $this->makeApiRequest('/vehicles', 'POST', $vehicleData);
            $vehicleIds[] = $response['body']['data']['id'];
        }

        // Sell some vehicles
        $this->createTestSale($dealerId, $vehicleIds[0], 30000);
        $this->createTestSale($dealerId, $vehicleIds[1], 25000);

        // Test summary report
        $response = $this->makeApiRequest('/reports/summary');
        $this->assertEquals(200, $response['status']);
        $summary = $response['body']['data'];
        $this->assertEquals(2, $summary['sales']['count']);
        $this->assertEquals(55000, $summary['sales']['total_value']);

        // Test vehicle stats
        $response = $this->makeApiRequest('/reports/vehicles');
        $this->assertEquals(200, $response['status']);
        $vehicleStats = $response['body']['data'];
        $this->assertArrayHasKey('profit_class_breakdown', $vehicleStats);

        // Test dealer summary
        $response = $this->makeApiRequest('/reports/dealers/' . $dealerId);
        $this->assertEquals(200, $response['status']);
        $dealerSummary = $response['body']['data'];
        $this->assertEquals(2, $dealerSummary['sales_count']);
        $this->assertEquals(1, $dealerSummary['available_inventory']); // One vehicle remaining
    }

    public function testAuditWorkflow() {
        $dealerId = $this->createTestDealer();
        $vehicleId = $this->createTestVehicle($dealerId, 'E2E123456789012345', 25000, 20000);
        $saleId = $this->createTestSale($dealerId, $vehicleId, 25000);

        // Run comprehensive audit
        $response = $this->makeApiRequest('/audit/entities');
        $this->assertEquals(200, $response['status']);
        $audit = $response['body']['data'];

        // Verify audit contains all entity types
        $this->assertArrayHasKey('dealers', $audit);
        $this->assertArrayHasKey('vehicles', $audit);
        $this->assertArrayHasKey('sales', $audit);
        $this->assertArrayHasKey('compliance', $audit);

        // Verify audit data integrity
        $this->assertGreaterThan(0, $audit['dealers']['total']);
        $this->assertGreaterThan(0, $audit['vehicles']['total']);
        $this->assertGreaterThan(0, $audit['sales']['total']);
    }

    public function testWebInterfaceAccess() {
        // Test main web interface
        $response = $this->makeWebRequest('/');
        $this->assertEquals(200, $response['status']);
        $this->assertStringContainsString('Sanctum DMS', $response['body']);
        $this->assertStringContainsString('Bootstrap', $response['body']);

        // Test API status through web interface
        $response = $this->makeApiRequest('/status');
        $this->assertEquals(200, $response['status']);
        $this->assertStringContainsString('DMS API is operational', $response['body']['message']);
    }

    public function testErrorHandlingWorkflow() {
        // Test invalid dealer creation
        $response = $this->makeApiRequest('/dealers', 'POST', ['name' => '', 'code' => '']);
        $this->assertEquals(400, $response['status']);

        // Test invalid vehicle creation
        $dealerId = $this->createTestDealer();
        $response = $this->makeApiRequest('/vehicles', 'POST', [
            'dealer_id' => $dealerId,
            'vin' => 'INVALID_VIN',
            'make' => 'Toyota',
            'model' => 'Camry',
            'year' => 2020
        ]);
        $this->assertEquals(400, $response['status']);

        // Test non-existent resource access
        $response = $this->makeApiRequest('/dealers/99999');
        $this->assertEquals(404, $response['status']);

        // Test unsupported HTTP method
        $response = $this->makeApiRequest('/status', 'DELETE');
        $this->assertEquals(405, $response['status']);
    }

    // Helper methods
    private function createTestDealer() {
        $dealerData = [
            'name' => 'E2E Test Dealer',
            'code' => 'E2E_' . uniqid(),
            'email' => 'e2e@example.com'
        ];
        $response = $this->makeApiRequest('/dealers', 'POST', $dealerData);
        return $response['body']['data']['id'];
    }

    private function createTestVehicle($dealerId, $vin = null, $price = 25000, $cost = 20000) {
        $vin = $vin ?: 'E2E' . uniqid() . '12345';
        $vehicleData = [
            'dealer_id' => $dealerId,
            'vin' => $vin,
            'make' => 'Toyota',
            'model' => 'Camry',
            'year' => 2020,
            'price' => $price,
            'cost' => $cost
        ];
        $response = $this->makeApiRequest('/vehicles', 'POST', $vehicleData);
        return $response['body']['data']['id'];
    }

    private function createTestSale($dealerId, $vehicleId, $salePrice = 25000) {
        $saleData = [
            'dealer_id' => $dealerId,
            'vehicle_id' => $vehicleId,
            'sale_price' => $salePrice,
            'sale_date' => '2023-10-01',
            'commission_rate' => 0.05
        ];
        $response = $this->makeApiRequest('/sales', 'POST', $saleData);
        return $response['body']['data']['id'];
    }
}
