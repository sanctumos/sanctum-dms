<?php
/**
 * Integration Tests - Service Integration
 * Tests service layer interactions and business logic flows
 */

require_once __DIR__ . '/../bootstrap.php';

class ServiceIntegrationTest extends BaseTest {
    private $db;
    private $dealerService;
    private $vehicleService;
    private $saleService;
    private $reportsService;
    private $complianceService;
    private $auditService;

    public function setUp(): void {
        $this->db = Database::getInstance();
        $this->dealerService = new DealerManagementService();
        $this->vehicleService = new VehicleService();
        $this->saleService = new SaleService();
        $this->reportsService = new ReportsService();
        $this->complianceService = new ComplianceService();
        $this->auditService = new AuditService();
        
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

    public function testDealerVehicleRelationship() {
        // Create dealer
        $dealerId = $this->createTestDealer();
        
        // Add vehicles to dealer
        $vehicleId1 = $this->createTestVehicle($dealerId, 'VIN123456789012345');
        $vehicleId2 = $this->createTestVehicle($dealerId, 'VIN223456789012345');
        
        // Get dealer inventory
        $inventory = $this->dealerService->getDealerInventory($dealerId);
        
        $this->assertCount(2, $inventory);
        $this->assertEquals($vehicleId1, $inventory[0]['id']);
        $this->assertEquals($vehicleId2, $inventory[1]['id']);
        
        // Verify vehicles reference correct dealer
        $vehicle1 = $this->vehicleService->getVehicle($vehicleId1);
        $vehicle2 = $this->vehicleService->getVehicle($vehicleId2);
        
        $this->assertEquals($dealerId, $vehicle1['dealer_id']);
        $this->assertEquals($dealerId, $vehicle2['dealer_id']);
    }

    public function testVehicleSaleWorkflow() {
        $dealerId = $this->createTestDealer();
        $vehicleId = $this->createTestVehicle($dealerId, 'VIN123456789012345', 25000, 20000);
        
        // Record sale
        $saleId = $this->createTestSale($dealerId, $vehicleId, 25000);
        
        // Verify vehicle status changed to sold
        $vehicle = $this->vehicleService->getVehicle($vehicleId);
        $this->assertEquals('sold', $vehicle['status']);
        
        // Verify sale references correct entities
        $sale = $this->saleService->getSale($saleId);
        $this->assertEquals($dealerId, $sale['dealer_id']);
        $this->assertEquals($vehicleId, $sale['vehicle_id']);
        $this->assertEquals(25000, $sale['sale_price']);
        
        // Verify commission calculation
        $this->assertEquals(1250, $sale['commission']); // 5% default rate
    }

    public function testDealerComplianceTracking() {
        $dealerId = $this->createTestDealer();
        
        // Set dealer max sales to 2
        $this->db->execute("UPDATE dealers SET max_sales_per_year = 2 WHERE id = ?", [$dealerId]);
        
        // Create 2 sales (should be compliant)
        $vehicleId1 = $this->createTestVehicle($dealerId, 'VIN123456789012345');
        $vehicleId2 = $this->createTestVehicle($dealerId, 'VIN223456789012345');
        
        $this->createTestSale($dealerId, $vehicleId1, 25000);
        $this->createTestSale($dealerId, $vehicleId2, 30000);
        
        // Check compliance
        $compliance = $this->complianceService->dealersCompliance();
        $dealerCompliance = array_filter($compliance, fn($d) => $d['dealer_id'] === $dealerId);
        $dealerCompliance = reset($dealerCompliance);
        
        $this->assertEquals('compliant', $dealerCompliance['status']);
        $this->assertEquals(2, $dealerCompliance['sales_this_year']);
        $this->assertEquals(0, $dealerCompliance['remaining']);
        
        // Create third sale (should exceed limit)
        $vehicleId3 = $this->createTestVehicle($dealerId, 'VIN323456789012345');
        
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Dealer has exceeded annual sales limit');
        $this->createTestSale($dealerId, $vehicleId3, 35000);
    }

    public function testReportsDataConsistency() {
        $dealerId = $this->createTestDealer();
        
        // Create vehicles with different profit classes
        $vehicleId1 = $this->createTestVehicle($dealerId, 'VIN123456789012345', 30000, 20000); // High profit
        $vehicleId2 = $this->createTestVehicle($dealerId, 'VIN223456789012345', 25000, 22000); // Medium profit
        $vehicleId3 = $this->createTestVehicle($dealerId, 'VIN323456789012345', 20000, 19000); // Low profit
        
        // Create some sales
        $this->createTestSale($dealerId, $vehicleId1, 30000);
        
        // Test summary reports
        $summary = $this->reportsService->getSummary();
        $vehicleStats = $this->reportsService->getVehicleStats();
        $dealerSummary = $this->reportsService->getDealerSummary($dealerId);
        
        // Verify sales data
        $this->assertEquals(1, $summary['sales']['count']);
        $this->assertEquals(30000, $summary['sales']['total_value']);
        
        // Verify vehicle data
        $this->assertEquals(2, $vehicleStats['status_breakdown'][0]['c']); // Available vehicles
        $this->assertGreaterThan(0, $vehicleStats['average_margin']);
        
        // Verify dealer data
        $this->assertEquals(1, $dealerSummary['sales_count']);
        $this->assertEquals(30000, $dealerSummary['sales_value']);
        $this->assertEquals(2, $dealerSummary['available_inventory']);
        
        // Verify profit class breakdown
        $profitBreakdown = $vehicleStats['profit_class_breakdown'];
        $highProfitCount = array_filter($profitBreakdown, fn($p) => $p['profit_class'] === 'high');
        $this->assertCount(1, $highProfitCount);
    }

    public function testAuditDataIntegrity() {
        $dealerId = $this->createTestDealer();
        $vehicleId = $this->createTestVehicle($dealerId, 'VIN123456789012345', 25000, 20000);
        $saleId = $this->createTestSale($dealerId, $vehicleId, 25000);
        
        // Run audit
        $audit = $this->auditService->getEntityAudit();
        
        // Verify dealer audit
        $this->assertGreaterThan(0, $audit['dealers']['total']);
        $this->assertGreaterThan(0, $audit['dealers']['active']);
        
        // Verify vehicle audit
        $this->assertGreaterThan(0, $audit['vehicles']['total']);
        $this->assertGreaterThan(0, $audit['vehicles']['sold']);
        
        // Verify sales audit
        $this->assertGreaterThan(0, $audit['sales']['total']);
        $this->assertGreaterThan(0, $audit['sales']['completed']);
        
        // Verify compliance audit
        $this->assertArrayHasKey('year', $audit['compliance']);
        $this->assertIsArray($audit['compliance']['over_limit_dealers']);
    }

    public function testComputedColumnsAccuracy() {
        $dealerId = $this->createTestDealer();
        
        // Create vehicle with specific cost/price
        $vehicleId = $this->createTestVehicle($dealerId, 'VIN123456789012345', 30000, 20000);
        
        // Get vehicle with computed columns
        $vehicle = $this->db->fetchOne("SELECT *, days_in_inventory, margin, profit_class FROM vehicles WHERE id = ?", [$vehicleId]);
        
        // Verify margin calculation
        $this->assertEquals(10000, $vehicle['margin']); // 30000 - 20000
        
        // Verify profit class
        $this->assertEquals('high', $vehicle['profit_class']); // > 5000
        
        // Verify days in inventory (should be 0 or 1 for just created)
        $this->assertGreaterThanOrEqual(0, $vehicle['days_in_inventory']);
    }

    public function testCascadeDeletion() {
        $dealerId = $this->createTestDealer();
        $vehicleId = $this->createTestVehicle($dealerId, 'VIN123456789012345');
        $saleId = $this->createTestSale($dealerId, $vehicleId, 25000);
        
        // Delete dealer (should cascade to vehicles and sales)
        $this->dealerService->deleteDealer($dealerId);
        
        // Verify dealer is deleted
        $dealer = $this->dealerService->getDealer($dealerId);
        $this->assertNull($dealer);
        
        // Verify vehicle is deleted
        $vehicle = $this->vehicleService->getVehicle($vehicleId);
        $this->assertNull($vehicle);
        
        // Verify sale is deleted
        $sale = $this->saleService->getSale($saleId);
        $this->assertNull($sale);
    }

    public function testConcurrentOperations() {
        $dealerId = $this->createTestDealer();
        
        // Simulate concurrent vehicle additions
        $vehicleIds = [];
        for ($i = 0; $i < 5; $i++) {
            $vin = 'VIN' . str_pad($i, 12, '0') . '12345';
            $vehicleId = $this->createTestVehicle($dealerId, $vin, 25000 + ($i * 1000), 20000);
            $vehicleIds[] = $vehicleId;
        }
        
        // Verify all vehicles were created
        $inventory = $this->dealerService->getDealerInventory($dealerId);
        $this->assertCount(5, $inventory);
        
        // Verify each vehicle has unique VIN
        $vins = array_column($inventory, 'vin');
        $this->assertCount(5, array_unique($vins));
    }

    public function testDataValidationAcrossServices() {
        // Test invalid dealer data
        $this->expectException(Exception::class);
        $this->dealerService->createDealer(['name' => '', 'code' => 'INVALID']);
        
        // Test invalid vehicle data
        $dealerId = $this->createTestDealer();
        $this->expectException(Exception::class);
        $this->vehicleService->addVehicle([
            'dealer_id' => $dealerId,
            'vin' => 'INVALID_VIN',
            'make' => 'Toyota',
            'model' => 'Camry',
            'year' => 2020
        ]);
        
        // Test invalid sale data
        $vehicleId = $this->createTestVehicle($dealerId, 'VIN123456789012345');
        $this->expectException(Exception::class);
        $this->saleService->recordSale([
            'dealer_id' => $dealerId,
            'vehicle_id' => $vehicleId,
            'sale_price' => -1000, // Negative price
            'sale_date' => '2023-10-01'
        ]);
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
        $vin = $vin ?: 'VIN' . uniqid() . '12345';
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
