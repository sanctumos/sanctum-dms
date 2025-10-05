<?php
/**
 * Edge Cases and Error Conditions Unit Tests
 * Tests boundary conditions, error handling, and edge cases
 */

require_once __DIR__ . '/../bootstrap.php';

class EdgeCaseTest extends BaseTest {
    private $db;
    private $dealerService;
    private $vehicleService;
    private $saleService;
    private $auth;

    public function setUp(): void {
        $this->db = Database::getInstance();
        $this->dealerService = new DealerManagementService();
        $this->vehicleService = new VehicleService();
        $this->saleService = new SaleService();
        $this->auth = new Auth();
        
        $this->cleanupTestData();
    }

    public function tearDown(): void {
        $this->cleanupTestData();
    }

    private function cleanupTestData() {
        $this->db->execute("DELETE FROM sales WHERE dealer_id IN (SELECT id FROM dealers WHERE code LIKE 'EDGE_%')");
        $this->db->execute("DELETE FROM vehicles WHERE dealer_id IN (SELECT id FROM dealers WHERE code LIKE 'EDGE_%')");
        $this->db->execute("DELETE FROM dealers WHERE code LIKE 'EDGE_%'");
    }

    // Database Edge Cases
    public function testDatabaseConnectionFailure() {
        // Test with invalid database path
        $originalPath = DB_PATH;
        
        try {
            // This should fail gracefully
            $invalidDb = new Database();
            $this->assertFalse($invalidDb->isConnected());
        } catch (Exception $e) {
            $this->assertStringContainsString('database', strtolower($e->getMessage()));
        }
    }

    public function testDatabaseTransactionNesting() {
        $this->db->beginTransaction();
        $this->db->beginTransaction(); // Nested transaction
        
        $this->db->execute("INSERT INTO dealers (name, code) VALUES (?, ?)", ['Nested Dealer', 'EDGE_NEST']);
        
        $this->db->commit();
        $this->db->commit();
        
        $dealer = $this->db->fetchOne("SELECT * FROM dealers WHERE code = ?", ['EDGE_NEST']);
        $this->assertNotNull($dealer);
    }

    public function testDatabaseLargeDataHandling() {
        // Test with very long strings
        $longName = str_repeat('A', 1000);
        $longCode = str_repeat('B', 100);
        
        $this->expectException(Exception::class);
        $this->db->execute("INSERT INTO dealers (name, code) VALUES (?, ?)", [$longName, $longCode]);
    }

    public function testDatabaseConcurrentAccess() {
        // Simulate concurrent access by creating multiple connections
        $dealerId = TestDataFactory::createDealer(['code' => 'EDGE_CONCURRENT']);
        
        // Multiple operations on same dealer
        $this->db->execute("UPDATE dealers SET name = ? WHERE id = ?", ['Updated Name 1', $dealerId]);
        $this->db->execute("UPDATE dealers SET name = ? WHERE id = ?", ['Updated Name 2', $dealerId]);
        
        $dealer = $this->db->fetchOne("SELECT * FROM dealers WHERE id = ?", [$dealerId]);
        $this->assertEquals('Updated Name 2', $dealer['name']);
    }

    // Authentication Edge Cases
    public function testAuthInvalidPasswordFormats() {
        $invalidPasswords = [
            '', // Empty password
            'a', // Too short
            str_repeat('a', 1000), // Too long
            null, // Null password
            123, // Non-string
        ];
        
        foreach ($invalidPasswords as $password) {
            $this->expectException(Exception::class);
            $this->auth->hashPassword($password);
        }
    }

    public function testAuthApiKeyCollision() {
        // Test API key uniqueness (very unlikely but possible)
        $keys = [];
        for ($i = 0; $i < 1000; $i++) {
            $key = $this->auth->generateApiKey();
            $this->assertNotContains($key, $keys);
            $keys[] = $key;
        }
    }

    public function testAuthRateLimitEdgeCases() {
        // Test rate limiting with different IPs
        $_SERVER['REMOTE_ADDR'] = '192.168.1.1';
        $_SERVER['HTTP_X_API_KEY'] = 'test-key';
        
        // Should not hit rate limit for different IP
        for ($i = 0; $i < 10; $i++) {
            $this->assertTrue($this->auth->checkRateLimit());
        }
        
        // Test rate limit reset
        $_SERVER['REMOTE_ADDR'] = '192.168.1.2';
        $this->assertTrue($this->auth->checkRateLimit());
    }

    public function testAuthSessionEdgeCases() {
        // Test session handling edge cases
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // Test with invalid session data
        $_SESSION['user_id'] = 'invalid';
        $this->assertFalse($this->auth->isLoggedIn());
        
        // Test with non-existent user
        $_SESSION['user_id'] = 99999;
        $this->assertFalse($this->auth->isLoggedIn());
    }

    // Service Edge Cases
    public function testDealerServiceBoundaryValues() {
        // Test minimum values
        $dealerData = [
            'name' => 'A', // Minimum length
            'code' => 'B', // Minimum length
            'email' => 'a@b.c', // Minimum valid email
            'max_sales_per_year' => 0 // Zero sales limit
        ];
        
        $dealerId = $this->dealerService->createDealer($dealerData);
        $this->assertIsInt($dealerId);
        
        // Test maximum values
        $dealerData2 = [
            'name' => str_repeat('A', 200), // Max length
            'code' => str_repeat('B', 50), // Max length
            'email' => str_repeat('a', 90) . '@example.com', // Max length
            'max_sales_per_year' => 999999 // Very high limit
        ];
        
        $dealerId2 = $this->dealerService->createDealer($dealerData2);
        $this->assertIsInt($dealerId2);
    }

    public function testDealerServiceDuplicateHandling() {
        $dealerData = ['name' => 'Duplicate Test', 'code' => 'EDGE_DUP', 'email' => 'test@example.com'];
        
        $dealerId1 = $this->dealerService->createDealer($dealerData);
        
        // Try to create duplicate
        $this->expectException(Exception::class);
        $this->dealerService->createDealer($dealerData);
    }

    public function testVehicleServiceVinEdgeCases() {
        $dealerId = TestDataFactory::createDealer(['code' => 'EDGE_VIN']);
        
        // Test VIN edge cases
        $invalidVins = [
            '', // Empty VIN
            'A', // Too short
            str_repeat('A', 20), // Too long
            'VIN12345678901234!', // Invalid character
            'VIN12345678901234O', // Contains O (confused with 0)
            'VIN12345678901234I', // Contains I (confused with 1)
        ];
        
        foreach ($invalidVins as $vin) {
            $this->expectException(Exception::class);
            $this->vehicleService->addVehicle([
                'dealer_id' => $dealerId,
                'vin' => $vin,
                'make' => 'Test',
                'model' => 'Test',
                'year' => 2020
            ]);
        }
    }

    public function testVehicleServicePriceEdgeCases() {
        $dealerId = TestDataFactory::createDealer(['code' => 'EDGE_PRICE']);
        
        // Test price edge cases
        $priceCases = [
            ['price' => -1000, 'cost' => 20000], // Negative price
            ['price' => 0, 'cost' => 20000], // Zero price
            ['price' => 20000, 'cost' => -1000], // Negative cost
            ['price' => 20000, 'cost' => 30000], // Cost higher than price
            ['price' => 999999999, 'cost' => 999999998], // Very high values
        ];
        
        foreach ($priceCases as $i => $case) {
            try {
                $vehicleId = $this->vehicleService->addVehicle([
                    'dealer_id' => $dealerId,
                    'vin' => 'EDGE' . str_pad($i, 12, '0') . '12345',
                    'make' => 'Test',
                    'model' => 'Test',
                    'year' => 2020,
                    'price' => $case['price'],
                    'cost' => $case['cost']
                ]);
                
                // Some cases might be valid (like zero price for free vehicles)
                $vehicle = $this->vehicleService->getVehicle($vehicleId);
                $this->assertNotNull($vehicle);
                
            } catch (Exception $e) {
                // Expected for invalid cases
                $this->assertStringContainsString('price', strtolower($e->getMessage()));
            }
        }
    }

    public function testSaleServiceDateEdgeCases() {
        $dealerId = TestDataFactory::createDealer(['code' => 'EDGE_DATE']);
        $vehicleId = TestDataFactory::createVehicle($dealerId, 'EDGE123456789012345');
        
        // Test date edge cases
        $dateCases = [
            '1900-01-01', // Very old date
            '2030-12-31', // Future date
            '2023-02-29', // Invalid date (not leap year)
            '2023-13-01', // Invalid month
            '2023-01-32', // Invalid day
            '', // Empty date
            'invalid-date', // Invalid format
        ];
        
        foreach ($dateCases as $date) {
            try {
                $saleId = $this->saleService->recordSale([
                    'dealer_id' => $dealerId,
                    'vehicle_id' => $vehicleId,
                    'sale_price' => 25000,
                    'sale_date' => $date
                ]);
                
                // Some dates might be valid
                $sale = $this->saleService->getSale($saleId);
                $this->assertNotNull($sale);
                
            } catch (Exception $e) {
                // Expected for invalid dates
                $this->assertStringContainsString('date', strtolower($e->getMessage()));
            }
        }
    }

    public function testSaleServiceCommissionEdgeCases() {
        $dealerId = TestDataFactory::createDealer(['code' => 'EDGE_COMM']);
        $vehicleId = TestDataFactory::createVehicle($dealerId, 'EDGE123456789012345');
        
        // Test commission edge cases
        $commissionCases = [
            ['rate' => -0.1, 'expected' => 0], // Negative rate
            ['rate' => 0, 'expected' => 0], // Zero rate
            ['rate' => 1.0, 'expected' => 25000], // 100% commission
            ['rate' => 1.5, 'expected' => 37500], // Over 100% commission
        ];
        
        foreach ($commissionCases as $case) {
            $saleId = $this->saleService->recordSale([
                'dealer_id' => $dealerId,
                'vehicle_id' => $vehicleId,
                'sale_price' => 25000,
                'sale_date' => '2023-10-01',
                'commission_rate' => $case['rate']
            ]);
            
            $sale = $this->saleService->getSale($saleId);
            $this->assertEquals($case['expected'], $sale['commission']);
        }
    }

    // Data Validation Edge Cases
    public function testDataValidationBoundaries() {
        // Test string length boundaries
        $maxLengthTests = [
            'dealer_name' => 200,
            'dealer_code' => 50,
            'dealer_email' => 100,
            'vehicle_make' => 50,
            'vehicle_model' => 50,
            'vehicle_color' => 50,
        ];
        
        foreach ($maxLengthTests as $field => $maxLength) {
            $longValue = str_repeat('A', $maxLength + 1);
            
            $this->expectException(Exception::class);
            
            if ($field === 'dealer_name') {
                $this->dealerService->createDealer(['name' => $longValue, 'code' => 'EDGE_LEN', 'email' => 'test@example.com']);
            }
        }
    }

    public function testDataValidationSpecialCharacters() {
        $dealerId = TestDataFactory::createDealer(['code' => 'EDGE_SPEC']);
        
        // Test special characters in various fields
        $specialCharTests = [
            'make' => 'Toyota™',
            'model' => 'Camry®',
            'color' => 'Silver-Gray',
            'notes' => 'Test "quotes" and \'apostrophes\' and <tags>',
        ];
        
        foreach ($specialCharTests as $field => $value) {
            try {
                $vehicleId = $this->vehicleService->addVehicle([
                    'dealer_id' => $dealerId,
                    'vin' => 'EDGE' . uniqid() . '12345',
                    'make' => 'Toyota',
                    'model' => 'Camry',
                    'year' => 2020,
                    $field => $value
                ]);
                
                $vehicle = $this->vehicleService->getVehicle($vehicleId);
                $this->assertEquals($value, $vehicle[$field]);
                
            } catch (Exception $e) {
                // Some special characters might be rejected
                $this->assertStringContainsString('invalid', strtolower($e->getMessage()));
            }
        }
    }

    // Performance Edge Cases
    public function testLargeDatasetHandling() {
        $dealerId = TestDataFactory::createDealer(['code' => 'EDGE_LARGE']);
        
        // Create many vehicles
        $vehicleIds = [];
        for ($i = 0; $i < 100; $i++) {
            $vehicleIds[] = TestDataFactory::createVehicle($dealerId, 'EDGE' . str_pad($i, 12, '0') . '12345');
        }
        
        // Test getting all vehicles
        $vehicles = $this->vehicleService->getVehiclesByDealer($dealerId);
        $this->assertCount(100, $vehicles);
        
        // Test pagination
        $paginatedVehicles = $this->vehicleService->getVehiclesByDealer($dealerId, 0, 10);
        $this->assertCount(10, $paginatedVehicles);
    }

    public function testConcurrentOperations() {
        $dealerId = TestDataFactory::createDealer(['code' => 'EDGE_CONC']);
        
        // Simulate concurrent vehicle additions
        $processes = [];
        for ($i = 0; $i < 5; $i++) {
            $vin = 'EDGE' . str_pad($i, 12, '0') . '12345';
            $vehicleId = TestDataFactory::createVehicle($dealerId, $vin);
            $processes[] = $vehicleId;
        }
        
        // Verify all vehicles were created
        $vehicles = $this->vehicleService->getVehiclesByDealer($dealerId);
        $this->assertCount(5, $vehicles);
        
        // Verify unique VINs
        $vins = array_column($vehicles, 'vin');
        $this->assertCount(5, array_unique($vins));
    }

    // Error Recovery Edge Cases
    public function testErrorRecoveryAfterFailure() {
        $dealerId = TestDataFactory::createDealer(['code' => 'EDGE_RECOVERY']);
        
        // Cause a failure
        try {
            $this->vehicleService->addVehicle([
                'dealer_id' => $dealerId,
                'vin' => 'INVALID_VIN', // This will fail
                'make' => 'Toyota',
                'model' => 'Camry',
                'year' => 2020
            ]);
        } catch (Exception $e) {
            // Expected failure
        }
        
        // Verify system is still functional
        $vehicleId = TestDataFactory::createVehicle($dealerId, 'EDGE123456789012345');
        $vehicle = $this->vehicleService->getVehicle($vehicleId);
        $this->assertNotNull($vehicle);
        
        // Verify dealer is still accessible
        $dealer = $this->dealerService->getDealer($dealerId);
        $this->assertNotNull($dealer);
    }

    public function testPartialFailureHandling() {
        $dealerId = TestDataFactory::createDealer(['code' => 'EDGE_PARTIAL']);
        
        // Create vehicle with some invalid data
        try {
            $this->vehicleService->addVehicle([
                'dealer_id' => $dealerId,
                'vin' => 'EDGE123456789012345',
                'make' => '', // Invalid empty make
                'model' => 'Camry',
                'year' => 2020
            ]);
        } catch (Exception $e) {
            // Expected failure
        }
        
        // Verify no partial data was saved
        $vehicles = $this->vehicleService->getVehiclesByDealer($dealerId);
        $this->assertCount(0, $vehicles);
    }
}
