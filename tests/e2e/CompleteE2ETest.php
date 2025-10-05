<?php
/**
 * Complete E2E Test Suite
 * Tests all user workflows and edge scenarios end-to-end
 */

require_once __DIR__ . '/../bootstrap.php';

class CompleteE2ETest extends PHPUnit\Framework\TestCase {
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
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $body = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $body = null;
        }

        return [
            'body' => $body,
            'status' => $httpCode
        ];
    }

    private function makeWebRequest($endpoint) {
        $url = $this->baseUrl . $endpoint;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'body' => $response,
            'status' => $httpCode
        ];
    }

    // Complete Dealer Management Workflow
    public function testCompleteDealerManagementWorkflow() {
        // Step 1: Create multiple dealers
        $dealers = [];
        for ($i = 1; $i <= 3; $i++) {
            $dealerData = [
                'name' => "E2E Dealer $i",
                'code' => 'E2E_' . uniqid(),
                'email' => "dealer$i@e2e.com",
                'phone' => "555-000$i",
                'max_sales_per_year' => 5 + $i
            ];
            
            $response = $this->makeApiRequest('/dealers', 'POST', $dealerData);
            $this->assertEquals(201, $response['status']);
            $dealers[] = $response['body']['data']['id'];
        }

        // Step 2: Add vehicles to each dealer
        $vehicles = [];
        foreach ($dealers as $dealerId) {
            for ($j = 1; $j <= 5; $j++) {
                $vehicleData = [
                    'dealer_id' => $dealerId,
                    'vin' => 'E2E' . str_pad($dealerId, 3, '0') . str_pad($j, 3, '0') . '12345',
                    'make' => ['Toyota', 'Honda', 'Ford'][$j % 3],
                    'model' => ['Camry', 'Civic', 'Focus'][$j % 3],
                    'year' => 2020 + ($j % 2),
                    'price' => 20000 + ($j * 2000),
                    'cost' => 15000 + ($j * 1500)
                ];
                
                $response = $this->makeApiRequest('/vehicles', 'POST', $vehicleData);
                $this->assertEquals(201, $response['status']);
                $vehicles[] = $response['body']['data']['id'];
            }
        }

        // Step 3: Record sales for each dealer
        $sales = [];
        $vehicleIndex = 0;
        foreach ($dealers as $dealerId) {
            for ($k = 1; $k <= 3; $k++) {
                $saleData = [
                    'dealer_id' => $dealerId,
                    'vehicle_id' => $vehicles[$vehicleIndex],
                    'sale_price' => 20000 + ($k * 2000),
                    'sale_date' => '2023-10-' . str_pad($k, 2, '0'),
                    'salesperson' => "Salesperson $k",
                    'commission_rate' => 0.05 + ($k * 0.01)
                ];
                
                $response = $this->makeApiRequest('/sales', 'POST', $saleData);
                $this->assertEquals(201, $response['status']);
                $sales[] = $response['body']['data']['id'];
                $vehicleIndex++;
            }
        }

        // Step 4: Generate comprehensive reports
        $response = $this->makeApiRequest('/reports/summary');
        $this->assertEquals(200, $response['status']);
        $summary = $response['body']['data'];
        $this->assertEquals(9, $summary['sales']['count']); // 3 dealers * 3 sales each

        // Step 5: Check compliance for each dealer
        $response = $this->makeApiRequest('/compliance');
        $this->assertEquals(200, $response['status']);
        $compliance = $response['body']['data'];
        
        foreach ($compliance as $dealerCompliance) {
            $this->assertArrayHasKey('dealer_id', $dealerCompliance);
            $this->assertArrayHasKey('sales_this_year', $dealerCompliance);
            $this->assertArrayHasKey('status', $dealerCompliance);
        }

        // Step 6: Run audit
        $response = $this->makeApiRequest('/audit/entities');
        $this->assertEquals(200, $response['status']);
        $audit = $response['body']['data'];
        $this->assertArrayHasKey('dealers', $audit);
        $this->assertArrayHasKey('vehicles', $audit);
        $this->assertArrayHasKey('sales', $audit);

        // Step 7: Update dealer information
        $response = $this->makeApiRequest('/dealers/' . $dealers[0], 'PUT', [
            'name' => 'Updated E2E Dealer 1',
            'max_sales_per_year' => 10
        ]);
        $this->assertEquals(200, $response['status']);

        // Step 8: Verify updates
        $response = $this->makeApiRequest('/dealers/' . $dealers[0]);
        $this->assertEquals('Updated E2E Dealer 1', $response['body']['data']['name']);
        $this->assertEquals(10, $response['body']['data']['max_sales_per_year']);
    }

    // Inventory Management Workflow
    public function testCompleteInventoryManagementWorkflow() {
        $dealerId = $this->createTestDealer();

        // Step 1: Add diverse vehicle inventory
        $vehicles = [];
        $vehicleTypes = [
            ['make' => 'Toyota', 'model' => 'Camry', 'year' => 2020, 'price' => 25000, 'cost' => 20000],
            ['make' => 'Honda', 'model' => 'Civic', 'year' => 2021, 'price' => 22000, 'cost' => 18000],
            ['make' => 'Ford', 'model' => 'Focus', 'year' => 2019, 'price' => 18000, 'cost' => 15000],
            ['make' => 'BMW', 'model' => '3 Series', 'year' => 2022, 'price' => 45000, 'cost' => 40000],
            ['make' => 'Mercedes', 'model' => 'C-Class', 'year' => 2021, 'price' => 50000, 'cost' => 45000]
        ];

        foreach ($vehicleTypes as $i => $vehicleData) {
            $vehicleData['dealer_id'] = $dealerId;
            $vehicleData['vin'] = 'E2E' . str_pad($i, 12, '0') . '12345';
            $vehicleData['color'] = ['Silver', 'Black', 'White', 'Blue', 'Red'][$i];
            $vehicleData['mileage'] = 10000 + ($i * 5000);
            $vehicleData['condition'] = 'excellent';

            $response = $this->makeApiRequest('/vehicles', 'POST', $vehicleData);
            $this->assertEquals(201, $response['status']);
            $vehicles[] = $response['body']['data']['id'];
        }

        // Step 2: Update vehicle prices
        $response = $this->makeApiRequest('/vehicles/' . $vehicles[0], 'PUT', ['price' => 26000]);
        $this->assertEquals(200, $response['status']);

        // Step 3: Verify price update and computed columns
        $response = $this->makeApiRequest('/vehicles/' . $vehicles[0]);
        $vehicle = $response['body']['data'];
        $this->assertEquals(26000, $vehicle['price']);
        $this->assertEquals(6000, $vehicle['margin']); // 26000 - 20000
        $this->assertEquals('high', $vehicle['profit_class']); // > 5000

        // Step 4: Get vehicle statistics
        $response = $this->makeApiRequest('/reports/vehicles');
        $this->assertEquals(200, $response['status']);
        $vehicleStats = $response['body']['data'];
        $this->assertArrayHasKey('profit_class_breakdown', $vehicleStats);

        // Step 5: Filter vehicles by make
        $response = $this->makeApiRequest('/vehicles?make=Toyota');
        $this->assertEquals(200, $response['status']);
        $toyotaVehicles = $response['body']['data'];
        $this->assertGreaterThan(0, count($toyotaVehicles));

        // Step 6: Get dealer inventory
        $response = $this->makeApiRequest('/dealers/' . $dealerId . '/inventory');
        $this->assertEquals(200, $response['status']);
        $inventory = $response['body']['data'];
        $this->assertCount(5, $inventory);
    }

    // Sales Process Workflow
    public function testCompleteSalesProcessWorkflow() {
        $dealerId = $this->createTestDealer();
        $vehicleId = $this->createTestVehicle($dealerId, 'E2E123456789012345', 30000, 25000);

        // Step 1: Record initial sale
        $saleData = [
            'dealer_id' => $dealerId,
            'vehicle_id' => $vehicleId,
            'sale_price' => 30000,
            'sale_date' => '2023-10-01',
            'salesperson' => 'John Doe',
            'commission_rate' => 0.05,
            'payment_method' => 'cash'
        ];

        $response = $this->makeApiRequest('/sales', 'POST', $saleData);
        $this->assertEquals(201, $response['status']);
        $saleId = $response['body']['data']['id'];

        // Step 2: Verify vehicle status changed to sold
        $response = $this->makeApiRequest('/vehicles/' . $vehicleId);
        $this->assertEquals('sold', $response['body']['data']['status']);

        // Step 3: Verify commission calculation
        $response = $this->makeApiRequest('/sales/' . $saleId);
        $sale = $response['body']['data'];
        $this->assertEquals(1500, $sale['commission']); // 5% of 30000

        // Step 4: Update sale with higher commission
        $response = $this->makeApiRequest('/sales/' . $saleId, 'PUT', [
            'commission_rate' => 0.07,
            'payment_method' => 'financed'
        ]);
        $this->assertEquals(200, $response['status']);

        // Step 5: Verify commission update
        $response = $this->makeApiRequest('/sales/' . $saleId);
        $sale = $response['body']['data'];
        $this->assertEquals(2100, $sale['commission']); // 7% of 30000
        $this->assertEquals('financed', $sale['payment_method']);

        // Step 6: Cancel sale
        $response = $this->makeApiRequest('/sales/' . $saleId . '/cancel', 'POST');
        $this->assertEquals(200, $response['status']);

        // Step 7: Verify vehicle status reverted
        $response = $this->makeApiRequest('/vehicles/' . $vehicleId);
        $this->assertEquals('available', $response['body']['data']['status']);

        // Step 8: Verify sale status
        $response = $this->makeApiRequest('/sales/' . $saleId);
        $this->assertEquals('cancelled', $response['body']['data']['status']);
    }

    // Compliance Monitoring Workflow
    public function testCompleteComplianceMonitoringWorkflow() {
        $dealerId = $this->createTestDealer();

        // Step 1: Set low sales limit for testing
        $response = $this->makeApiRequest('/dealers/' . $dealerId, 'PUT', ['max_sales_per_year' => 2]);
        $this->assertEquals(200, $response['status']);

        // Step 2: Create vehicles
        $vehicles = [];
        for ($i = 0; $i < 5; $i++) {
            $vehicles[] = $this->createTestVehicle($dealerId, 'E2E' . str_pad($i, 12, '0') . '12345');
        }

        // Step 3: Record sales up to limit
        $sales = [];
        for ($i = 0; $i < 2; $i++) {
            $sales[] = $this->createTestSale($dealerId, $vehicles[$i], 25000 + ($i * 1000));
        }

        // Step 4: Check compliance - should be compliant
        $response = $this->makeApiRequest('/compliance');
        $this->assertEquals(200, $response['status']);
        $compliance = $response['body']['data'];
        $dealerCompliance = array_filter($compliance, fn($d) => $d['dealer_id'] === $dealerId);
        $dealerCompliance = reset($dealerCompliance);
        $this->assertEquals('compliant', $dealerCompliance['status']);
        $this->assertEquals(0, $dealerCompliance['remaining']);

        // Step 5: Try to exceed limit
        $response = $this->makeApiRequest('/sales', 'POST', [
            'dealer_id' => $dealerId,
            'vehicle_id' => $vehicles[2],
            'sale_price' => 27000,
            'sale_date' => '2023-10-01'
        ]);
        $this->assertEquals(400, $response['status']); // Should fail due to compliance

        // Step 6: Increase limit and retry
        $response = $this->makeApiRequest('/dealers/' . $dealerId, 'PUT', ['max_sales_per_year' => 5]);
        $this->assertEquals(200, $response['status']);

        $response = $this->makeApiRequest('/sales', 'POST', [
            'dealer_id' => $dealerId,
            'vehicle_id' => $vehicles[2],
            'sale_price' => 27000,
            'sale_date' => '2023-10-01'
        ]);
        $this->assertEquals(201, $response['status']); // Should succeed now

        // Step 7: Verify updated compliance
        $response = $this->makeApiRequest('/compliance');
        $compliance = $response['body']['data'];
        $dealerCompliance = array_filter($compliance, fn($d) => $d['dealer_id'] === $dealerId);
        $dealerCompliance = reset($dealerCompliance);
        $this->assertEquals(3, $dealerCompliance['sales_this_year']);
        $this->assertEquals(2, $dealerCompliance['remaining']);
    }

    // Reporting and Analytics Workflow
    public function testCompleteReportingWorkflow() {
        // Step 1: Create diverse test data
        $dealers = [];
        for ($i = 1; $i <= 3; $i++) {
            $dealers[] = $this->createTestDealer("E2E Dealer $i");
        }

        $vehicles = [];
        $sales = [];
        foreach ($dealers as $dealerId) {
            for ($j = 0; $j < 3; $j++) {
                $vehicleId = $this->createTestVehicle($dealerId, 'E2E' . str_pad($dealerId, 3, '0') . str_pad($j, 3, '0') . '12345');
                $vehicles[] = $vehicleId;
                
                if ($j < 2) { // Only sell 2 out of 3 vehicles
                    $sales[] = $this->createTestSale($dealerId, $vehicleId, 25000 + ($j * 5000));
                }
            }
        }

        // Step 2: Generate summary report
        $response = $this->makeApiRequest('/reports/summary');
        $this->assertEquals(200, $response['status']);
        $summary = $response['body']['data'];
        $this->assertEquals(6, $summary['sales']['count']); // 3 dealers * 2 sales each
        $this->assertArrayHasKey('monthly_sales', $summary);

        // Step 3: Generate vehicle statistics
        $response = $this->makeApiRequest('/reports/vehicles');
        $this->assertEquals(200, $response['status']);
        $vehicleStats = $response['body']['data'];
        $this->assertArrayHasKey('profit_class_breakdown', $vehicleStats);
        $this->assertArrayHasKey('average_days_in_inventory', $vehicleStats);

        // Step 4: Generate dealer-specific reports
        foreach ($dealers as $dealerId) {
            $response = $this->makeApiRequest('/reports/dealers/' . $dealerId);
            $this->assertEquals(200, $response['status']);
            $dealerReport = $response['body']['data'];
            $this->assertEquals($dealerId, $dealerReport['dealer_id']);
            $this->assertEquals(2, $dealerReport['sales_count']);
            $this->assertEquals(1, $dealerReport['available_inventory']); // 1 unsold vehicle
        }

        // Step 5: Run comprehensive audit
        $response = $this->makeApiRequest('/audit/entities');
        $this->assertEquals(200, $response['status']);
        $audit = $response['body']['data'];
        $this->assertGreaterThan(0, $audit['dealers']['total']);
        $this->assertGreaterThan(0, $audit['vehicles']['total']);
        $this->assertGreaterThan(0, $audit['sales']['total']);
    }

    // Error Handling and Recovery Workflow
    public function testErrorHandlingAndRecoveryWorkflow() {
        // Step 1: Test invalid operations
        $invalidOperations = [
            ['endpoint' => '/dealers', 'method' => 'POST', 'data' => ['name' => 'Test']], // Missing required fields
            ['endpoint' => '/vehicles', 'method' => 'POST', 'data' => ['vin' => 'INVALID']], // Invalid VIN
            ['endpoint' => '/sales', 'method' => 'POST', 'data' => ['sale_price' => -1000]], // Invalid price
            ['endpoint' => '/dealers/99999', 'method' => 'GET'], // Non-existent dealer
            ['endpoint' => '/status', 'method' => 'DELETE'], // Invalid method
        ];

        foreach ($invalidOperations as $operation) {
            $response = $this->makeApiRequest($operation['endpoint'], $operation['method'], $operation['data'] ?? null);
            $this->assertGreaterThanOrEqual(400, $response['status'], "Operation should fail: " . json_encode($operation));
        }

        // Step 2: Test system recovery after errors
        $dealerId = $this->createTestDealer();
        $vehicleId = $this->createTestVehicle($dealerId, 'E2E123456789012345');
        $saleId = $this->createTestSale($dealerId, $vehicleId, 25000);

        // Step 3: Verify system is still functional
        $response = $this->makeApiRequest('/dealers/' . $dealerId);
        $this->assertEquals(200, $response['status']);

        $response = $this->makeApiRequest('/vehicles/' . $vehicleId);
        $this->assertEquals(200, $response['status']);

        $response = $this->makeApiRequest('/sales/' . $saleId);
        $this->assertEquals(200, $response['status']);

        // Step 4: Test partial failure recovery
        try {
            $this->makeApiRequest('/vehicles', 'POST', [
                'dealer_id' => $dealerId,
                'vin' => 'E2E123456789012345', // Duplicate VIN
                'make' => 'Toyota',
                'model' => 'Camry',
                'year' => 2020
            ]);
        } catch (Exception $e) {
            // Expected failure
        }

        // Step 5: Verify no partial data was created
        $response = $this->makeApiRequest('/vehicles?dealer_id=' . $dealerId);
        $this->assertEquals(200, $response['status']);
        $this->assertCount(1, $response['body']['data']); // Only the original vehicle
    }

    // Web Interface Integration Test
    public function testWebInterfaceIntegration() {
        // Step 1: Test main web interface
        $response = $this->makeWebRequest('/');
        $this->assertEquals(200, $response['status']);
        $this->assertStringContainsString('Sanctum DMS', $response['body']);
        $this->assertStringContainsString('Bootstrap', $response['body']);

        // Step 2: Test API integration with web interface
        $response = $this->makeApiRequest('/status');
        $this->assertEquals(200, $response['status']);
        $this->assertStringContainsString('DMS API is operational', $response['body']['message']);

        // Step 3: Test that web interface can access API data
        $dealerId = $this->createTestDealer();
        $response = $this->makeApiRequest('/dealers/' . $dealerId);
        $this->assertEquals(200, $response['status']);
        $this->assertArrayHasKey('name', $response['body']['data']);
    }

    // Data Consistency Workflow
    public function testDataConsistencyWorkflow() {
        // Step 1: Create complex data relationships
        $dealerId = $this->createTestDealer();
        $vehicles = [];
        $sales = [];

        for ($i = 0; $i < 5; $i++) {
            $vehicleId = $this->createTestVehicle($dealerId, 'E2E' . str_pad($i, 12, '0') . '12345');
            $vehicles[] = $vehicleId;
            
            if ($i < 3) { // Sell 3 vehicles
                $saleId = $this->createTestSale($dealerId, $vehicleId, 25000 + ($i * 1000));
                $sales[] = $saleId;
            }
        }

        // Step 2: Verify data consistency across all endpoints
        $response = $this->makeApiRequest('/dealers/' . $dealerId);
        $dealer = $response['body']['data'];
        $this->assertNotNull($dealer);

        $response = $this->makeApiRequest('/vehicles?dealer_id=' . $dealerId);
        $dealerVehicles = $response['body']['data'];
        $this->assertCount(5, $dealerVehicles);

        $response = $this->makeApiRequest('/sales?dealer_id=' . $dealerId);
        $dealerSales = $response['body']['data'];
        $this->assertCount(3, $dealerSales);

        // Step 3: Verify sold vehicles have correct status
        foreach ($sales as $saleId) {
            $response = $this->makeApiRequest('/sales/' . $saleId);
            $sale = $response['body']['data'];
            
            $response = $this->makeApiRequest('/vehicles/' . $sale['vehicle_id']);
            $vehicle = $response['body']['data'];
            $this->assertEquals('sold', $vehicle['status']);
        }

        // Step 4: Verify available vehicles
        $availableVehicles = array_filter($dealerVehicles, fn($v) => $v['status'] === 'available');
        $this->assertCount(2, $availableVehicles);

        // Step 5: Verify reports reflect correct data
        $response = $this->makeApiRequest('/reports/dealers/' . $dealerId);
        $dealerReport = $response['body']['data'];
        $this->assertEquals(3, $dealerReport['sales_count']);
        $this->assertEquals(2, $dealerReport['available_inventory']);
    }

    // Helper methods
    private function createTestDealer($name = null) {
        $dealerData = [
            'name' => $name ?: 'E2E Test Dealer',
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
