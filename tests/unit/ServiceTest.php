<?php
/**
 * Unit Tests - Service Layer
 * Tests individual service classes and their business logic
 */

require_once __DIR__ . '/../bootstrap.php';

class ServiceTest extends BaseTest {
    protected $db;
    protected $dealerService;
    protected $vehicleService;
    protected $saleService;
    protected $reportsService;

    public function setUp(): void {
        $this->db = Database::getInstance();
        $this->dealerService = new DealerManagementService();
        $this->vehicleService = new VehicleService();
        $this->saleService = new SaleService();
        $this->reportsService = new ReportsService();
        
        // Clean up test data
        $this->cleanupTestData();
    }

    public function tearDown(): void {
        $this->cleanupTestData();
    }

    private function cleanupTestData() {
        $this->db->execute("DELETE FROM sales WHERE dealer_id IN (SELECT id FROM dealers WHERE code LIKE 'TEST_%')");
        $this->db->execute("DELETE FROM vehicles WHERE dealer_id IN (SELECT id FROM dealers WHERE code LIKE 'TEST_%')");
        $this->db->execute("DELETE FROM dealers WHERE code LIKE 'TEST_%'");
    }

    public function testDealerServiceCreate() {
        $dealerData = [
            'name' => 'Test Dealer',
            'code' => 'TEST_001',
            'email' => 'test@dealer.com',
            'phone' => '555-0123',
            'max_sales_per_year' => 5
        ];
        
        $dealerId = $this->dealerService->createDealer($dealerData);
        
        $this->assertIsInt($dealerId);
        $this->assertGreaterThan(0, $dealerId);
        
        $dealer = $this->dealerService->getDealer($dealerId);
        $this->assertEquals('Test Dealer', $dealer['name']);
        $this->assertEquals('TEST_001', $dealer['code']);
        $this->assertEquals('test@dealer.com', $dealer['email']);
    }

    public function testDealerServiceValidation() {
        // Test duplicate code
        $dealerData1 = ['name' => 'Dealer 1', 'code' => 'TEST_DUP', 'email' => 'test1@example.com'];
        $dealerData2 = ['name' => 'Dealer 2', 'code' => 'TEST_DUP', 'email' => 'test2@example.com'];
        
        $this->dealerService->createDealer($dealerData1);
        
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Dealer code already exists');
        $this->dealerService->createDealer($dealerData2);
    }

    public function testDealerServiceUpdate() {
        $dealerData = ['name' => 'Original Name', 'code' => 'TEST_UPDATE', 'email' => 'original@example.com'];
        $dealerId = $this->dealerService->createDealer($dealerData);
        
        $updateData = ['name' => 'Updated Name', 'email' => 'updated@example.com'];
        $this->dealerService->updateDealer($dealerId, $updateData);
        
        $dealer = $this->dealerService->getDealer($dealerId);
        $this->assertEquals('Updated Name', $dealer['name']);
        $this->assertEquals('updated@example.com', $dealer['email']);
        $this->assertEquals('TEST_UPDATE', $dealer['code']); // Should remain unchanged
    }

    public function testVehicleServiceCreate() {
        // Create dealer first
        $dealerId = $this->createTestDealer();
        
        $vehicleData = [
            'dealer_id' => $dealerId,
            'vin' => 'VAN12345678901234',
            'make' => 'Toyota',
            'model' => 'Camry',
            'year' => 2020,
            'color' => 'Silver',
            'price' => 25000,
            'cost' => 20000
        ];
        
        $vehicleId = $this->vehicleService->addVehicle($vehicleData);
        
        $this->assertIsInt($vehicleId);
        $this->assertGreaterThan(0, $vehicleId);
        
        $vehicle = $this->vehicleService->getVehicle($vehicleId);
        $this->assertEquals('VAN12345678901234', $vehicle['vin']);
        $this->assertEquals('Toyota', $vehicle['make']);
        $this->assertEquals(25000, $vehicle['price']);
    }

    public function testVehicleServiceVinValidation() {
        $dealerId = $this->createTestDealer();
        
        $vehicleData = [
            'dealer_id' => $dealerId,
            'vin' => 'INVALID_VIN', // Too short
            'make' => 'Toyota',
            'model' => 'Camry',
            'year' => 2020
        ];
        
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Invalid VIN');
        $this->vehicleService->addVehicle($vehicleData);
    }

    public function testVehicleServiceDuplicateVin() {
        $dealerId = $this->createTestDealer();
        $vin = 'VAN12345678901234';
        
        $vehicleData1 = [
            'dealer_id' => $dealerId,
            'vin' => $vin,
            'make' => 'Toyota',
            'model' => 'Camry',
            'year' => 2020
        ];
        
        $vehicleData2 = [
            'dealer_id' => $dealerId,
            'vin' => $vin, // Same VIN
            'make' => 'Honda',
            'model' => 'Civic',
            'year' => 2021
        ];
        
        $this->vehicleService->addVehicle($vehicleData1);
        
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('VIN already exists');
        $this->vehicleService->addVehicle($vehicleData2);
    }

    public function testSaleServiceCreate() {
        $dealerId = $this->createTestDealer();
        $vehicleId = $this->createTestVehicle($dealerId);
        
        $saleData = [
            'dealer_id' => $dealerId,
            'vehicle_id' => $vehicleId,
            'sale_price' => 25000,
            'sale_date' => '2023-10-01',
            'salesperson' => 'John Doe',
            'commission_rate' => 0.05
        ];
        
        $saleId = $this->saleService->recordSale($saleData);
        
        $this->assertIsInt($saleId);
        $this->assertGreaterThan(0, $saleId);
        
        $sale = $this->saleService->getSale($saleId);
        $this->assertEquals(25000, $sale['sale_price']);
        $this->assertEquals(250, $sale['commission']); // 5% of gross profit (25000-20000)
        $this->assertEquals('completed', $sale['status']);
    }

    public function testSaleServiceCommissionCalculation() {
        $dealerId = $this->createTestDealer();
        $vehicleId = $this->createTestVehicle($dealerId);
        
        $saleData = [
            'dealer_id' => $dealerId,
            'vehicle_id' => $vehicleId,
            'sale_price' => 30000,
            'sale_date' => '2023-10-01',
            'commission_rate' => 0.07
        ];
        
        $saleId = $this->saleService->recordSale($saleData);
        $sale = $this->saleService->getSale($saleId);
        
        $this->assertEquals(700, $sale['commission']); // 7% of gross profit (30000-20000)
    }

    public function testSaleServiceDealerCompliance() {
        $dealerId = $this->createTestDealer();
        
        // Set dealer max sales to 2
        $this->db->execute("UPDATE dealers SET max_sales_per_year = 2 WHERE id = ?", [$dealerId]);
        
        // Create 2 vehicles and sales
        for ($i = 1; $i <= 2; $i++) {
            $vehicleId = $this->createTestVehicle($dealerId, "VAN{$i}2345678901234");
            $this->createTestSale($dealerId, $vehicleId);
        }
        
        // Third sale should fail compliance check
        $vehicleId = $this->createTestVehicle($dealerId, 'VAN32345678901234');
        
        $saleData = [
            'dealer_id' => $dealerId,
            'vehicle_id' => $vehicleId,
            'sale_price' => 25000,
            'sale_date' => '2023-10-01'
        ];
        
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Dealer sales limit reached');
        $this->saleService->recordSale($saleData);
    }

    public function testReportsServiceSummary() {
        $dealerId = $this->createTestDealer();
        
        // Create some test data
        $vehicleId1 = $this->createTestVehicle($dealerId, 'VAN12345678901234', 25000, 20000);
        $vehicleId2 = $this->createTestVehicle($dealerId, 'VAN22345678901234', 30000, 22000);
        
        $this->createTestSale($dealerId, $vehicleId1, 25000);
        $this->createTestSale($dealerId, $vehicleId2, 30000);
        
        $summary = $this->reportsService->getSummary();
        
        $this->assertArrayHasKey('sales', $summary);
        $this->assertArrayHasKey('vehicles', $summary);
        $this->assertEquals(2, $summary['sales']['count']);
        $this->assertEquals(55000.0, $summary['sales']['total_value']);
    }

    public function testReportsServiceDealerSummary() {
        $dealerId = $this->createTestDealer();
        
        $vehicleId = $this->createTestVehicle($dealerId);
        $this->createTestSale($dealerId, $vehicleId, 25000);
        
        $summary = $this->reportsService->getDealerSummary($dealerId);
        
        $this->assertEquals($dealerId, $summary['dealer_id']);
        $this->assertEquals(1, $summary['sales_count']);
        $this->assertEquals(25000.0, $summary['sales_value']);
        $this->assertEquals(0, $summary['available_inventory']); // Vehicle was sold
    }

    public function testReportsServiceVehicleStats() {
        $dealerId = $this->createTestDealer();
        
        // Create vehicles with different statuses
        $this->createTestVehicle($dealerId, 'VAN12345678901234', 25000, 20000);
        $this->createTestVehicle($dealerId, 'VAN22345678901234', 30000, 22000);
        
        $stats = $this->reportsService->getVehicleStats();
        
        $this->assertArrayHasKey('status_breakdown', $stats);
        $this->assertArrayHasKey('top_makes', $stats);
        $this->assertArrayHasKey('average_margin', $stats);
        $this->assertArrayHasKey('profit_class_breakdown', $stats);
    }

    // Helper methods
    private function createTestDealer() {
        $dealerData = [
            'name' => 'Test Dealer',
            'code' => 'TEST_' . uniqid(),
            'email' => 'test@example.com'
        ];
        return $this->dealerService->createDealer($dealerData);
    }

    private function createTestVehicle($dealerId, $vin = null, $price = 25000, $cost = 20000) {
        $vin = $vin ?: 'VAN' . strtoupper(str_pad(substr(md5(uniqid()), 0, 14), 14, '0', STR_PAD_RIGHT));
        $vehicleData = [
            'dealer_id' => $dealerId,
            'vin' => $vin,
            'make' => 'Toyota',
            'model' => 'Camry',
            'year' => 2020,
            'price' => $price,
            'cost' => $cost
        ];
        return $this->vehicleService->addVehicle($vehicleData);
    }

    private function createTestSale($dealerId, $vehicleId, $salePrice = 25000) {
        $saleData = [
            'dealer_id' => $dealerId,
            'vehicle_id' => $vehicleId,
            'sale_price' => $salePrice,
            'sale_date' => '2023-10-01',
            'commission_rate' => 0.05
        ];
        return $this->saleService->recordSale($saleData);
    }
}
